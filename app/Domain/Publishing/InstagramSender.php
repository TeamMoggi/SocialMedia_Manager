<?php

namespace App\Domain\Publishing;

use App\Domain\Meta\MetaError;
use App\Domain\Meta\MetaGraph;
use App\Domain\Meta\PublishBlocked;
use App\Domain\Posts\AiLabel;
use App\Domain\Posts\CaptionBuilder;
use App\Domain\Relay\RelayFolder;
use App\Enums\PostType;
use App\Enums\TargetState;
use App\Models\PostMedia;
use App\Models\PostTarget;
use Carbon\CarbonImmutable;
use Closure;
use RuntimeException;
use Throwable;

/**
 * Instagram sending: copy the files to the Hostinger folder, make the containers and wait until Instagram has
 * prepared them (prepare), then publish at the post time and confirm (publish). Meta's docs (v27.0, read 26 Nov
 * 2026): container = POST {ig}/media, carousel children carry is_carousel_item=true and the parent lists them in
 * `children`, is_ai_generated goes on the parent only, publish = POST {ig}/media_publish with creation_id.
 * Reels use REELS + video_url only, as the rehearsal proved (share_to_feed is not on Meta's page).
 *
 * The double-post guard: the moment before media_publish is called, `publish_started_at` is saved. A later
 * attempt that finds it without a result first looks at the account's newest posts and adopts a matching one
 * instead of publishing again.
 */
final class InstagramSender
{
    private readonly Closure $sleep;

    public function __construct(
        private readonly MetaGraph $graph,
        private readonly RelayFolder $relay,
        ?Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? fn (int $seconds) => sleep($seconds);
    }

    public function prepare(PostTarget $target): void
    {
        $this->requireLive();
        $target->loadMissing('post.media');
        $account = TargetAccount::for($target);
        $token = (string) $account->access_token;
        $ig = $account->external_id;
        $caption = CaptionBuilder::for($target);
        $ai = AiLabel::applies($target->post);
        $video = false;

        try {
            $urls = [];
            foreach ($target->post->media as $media) {
                /** @var PostMedia $media */
                $file = $this->relay->put($media->fullPath());
                $this->remember($target, 'relay', $file['name']);
                $urls[] = [$file['url'], str_starts_with((string) $media->mime, 'video/')];
                $video = $video || end($urls)[1];
            }

            $children = [];
            if ($target->type === PostType::InstagramCarousel) {
                foreach ($urls as [$url, $isVideo]) {
                    $params = ['is_carousel_item' => true] + ($isVideo ? ['media_type' => 'VIDEO', 'video_url' => $url] : ['image_url' => $url]);
                    $children[] = $this->container($ig, $params, $token);
                }
                $params = ['media_type' => 'CAROUSEL', 'children' => implode(',', $children), 'caption' => $caption];
            } else {
                [$url, $isVideo] = $urls[0] ?? throw new RuntimeException('This post has no photo or video.');
                $params = match ($target->type) {
                    PostType::InstagramReel => ['media_type' => 'REELS', 'video_url' => $url, 'caption' => $caption],
                    PostType::InstagramStory => ['media_type' => 'STORIES'] + ($isVideo ? ['video_url' => $url] : ['image_url' => $url]),
                    default => ['image_url' => $url, 'caption' => $caption],
                };
            }
            if ($ai) {
                $params['is_ai_generated'] = true;
            }
            $parent = $this->container($ig, $params, $token);

            foreach ([...$children, $parent] as $container) {
                $this->waitUntilReady($container, $token, $video ? 10 : 3, $video ? 600 : 60);
            }
        } catch (Throwable $e) {
            $this->cleanRelay($target);
            throw $e;
        }

        $work = $target->fresh()->work ?? [];
        $work['children'] = $children;
        $target->update(['state' => TargetState::Queued, 'container_id' => $parent, 'work' => $work, 'last_error' => null]);
    }

    public function publish(PostTarget $target): void
    {
        $this->requireLive();
        $target->loadMissing('post.media');
        $account = TargetAccount::for($target);
        $token = (string) $account->access_token;
        $ig = $account->external_id;
        $work = $target->work ?? [];

        if (filled($work['publish_started_at'] ?? null) && blank($target->external_id)) {
            $this->adoptOrFail($target, $ig, $token, CarbonImmutable::parse($work['publish_started_at']));

            return;
        }
        if (blank($target->container_id)) {
            throw new RuntimeException('This post was not prepared on Instagram yet.');
        }

        $work['publish_started_at'] = CarbonImmutable::now()->toIso8601String();
        $target->update(['work' => $work]);
        try {
            $mediaId = (string) ($this->graph->post("{$ig}/media_publish", ['creation_id' => $target->container_id], $token)['id'] ?? '');
        } catch (MetaError $e) {
            if ($e->code() !== 0) {
                // Meta answered with a refusal: nothing was published, so a retry may publish.
                unset($work['publish_started_at']);
                $target->update(['work' => $work]);
            }
            throw $e;
        }
        if ($mediaId === '') {
            throw new RuntimeException('Instagram gave no id for the post.');
        }
        $target->update(['external_id' => $mediaId]);
        $this->finish($target, $mediaId, $this->permalink($mediaId, $token));
    }

    /** Removes this post's files from the Hostinger folder; never throws. */
    public function cleanRelay(PostTarget $target): void
    {
        $work = $target->fresh()->work ?? [];
        foreach ($work['relay'] ?? [] as $name) {
            try {
                $this->relay->delete((string) $name);
            } catch (Throwable) {
                // The daily sweep removes leftovers older than two hours.
            }
        }
        unset($work['relay']);
        $target->update(['work' => $work]);
    }

    /** Takes a prepared post back to "not sent yet" (approval withdrawn, time changed, or given up). */
    public function abandon(PostTarget $target): void
    {
        $this->cleanRelay($target);
        $work = $target->fresh()->work ?? [];
        unset($work['children'], $work['publish_started_at']);
        $target->update(['state' => TargetState::Waiting, 'container_id' => null, 'work' => $work]);
    }

    private function adoptOrFail(PostTarget $target, string $ig, string $token, CarbonImmutable $started): void
    {
        $expected = trim(CaptionBuilder::for($target));
        $recent = $this->graph->get("{$ig}/media", ['fields' => 'id,timestamp,permalink,caption', 'limit' => 5], $token)['data'] ?? [];
        foreach ($recent as $media) {
            $posted = CarbonImmutable::parse((string) ($media['timestamp'] ?? '1970-01-01'));
            if ($posted->greaterThanOrEqualTo($started->subSeconds(30)) && ($expected === '' || trim((string) ($media['caption'] ?? '')) === $expected)) {
                $target->update(['external_id' => (string) $media['id']]);
                $this->finish($target, (string) $media['id'], $media['permalink'] ?? null);

                return;
            }
        }
        throw new RuntimeException('Instagram may have published this already: check Instagram before trying again.');
    }

    private function finish(PostTarget $target, string $mediaId, ?string $permalink): void
    {
        $this->cleanRelay($target);
        $target->update([
            'state' => TargetState::Published, 'external_id' => $mediaId, 'permalink' => $permalink,
            'published_at' => CarbonImmutable::now(), 'last_error' => null,
        ]);
    }

    private function permalink(string $mediaId, string $token): ?string
    {
        try {
            return $this->graph->get($mediaId, ['fields' => 'permalink'], $token)['permalink'] ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    private function container(string $ig, array $params, string $token): string
    {
        $id = (string) ($this->graph->post("{$ig}/media", $params, $token)['id'] ?? '');
        if ($id === '') {
            throw new RuntimeException('Instagram gave no container id.');
        }

        return $id;
    }

    private function waitUntilReady(string $container, string $token, int $every, int $limit): void
    {
        for ($waited = 0; ; $waited += $every) {
            $status = $this->graph->get($container, ['fields' => 'status_code,status'], $token);
            $code = (string) ($status['status_code'] ?? '');
            if ($code === 'FINISHED') {
                return;
            }
            if (in_array($code, ['ERROR', 'EXPIRED'], true)) {
                throw new RuntimeException('Instagram could not prepare it: '.($status['status'] ?? $code));
            }
            if ($waited >= $limit) {
                throw new TemporaryFailure('Instagram took over '.intdiv($limit, 60).' minute(s) to prepare it.');
            }
            ($this->sleep)($every);
        }
    }

    /** Saves a relay file name at once, so a crash still leaves a record of what to delete. */
    private function remember(PostTarget $target, string $key, string $value): void
    {
        $work = $target->fresh()->work ?? [];
        $work[$key][] = $value;
        $target->update(['work' => $work]);
    }

    private function requireLive(): void
    {
        if ($this->graph->mode() !== 'live') {
            throw new PublishBlocked;
        }
    }
}
