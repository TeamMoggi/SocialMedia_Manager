<?php

namespace App\Domain\Publishing;

use App\Domain\Meta\MetaError;
use App\Domain\Meta\MetaGraph;
use App\Domain\Meta\PublishBlocked;
use App\Domain\Posts\Approval;
use App\Domain\Posts\CaptionBuilder;
use App\Enums\PostType;
use App\Enums\TargetState;
use App\Models\PostMedia;
use App\Models\PostTarget;
use Carbon\CarbonImmutable;
use Closure;
use RuntimeException;
use Throwable;

/**
 * Facebook Page sending: hand a post to Facebook with a scheduled time, publish it now, change that time, or take
 * it back. Meta's v27.0 pages (read 26 Nov 2026): /feed takes published=false + scheduled_publish_time +
 * unpublished_content_type=SCHEDULED (10 minutes to 75 days ahead); photos for a post are uploaded to /photos with
 * published=false + temporary=true and joined by attached_media; a scheduled post is changed or deleted through its
 * own id. Nothing is sent unless the graph is live (so Dev can never upload or schedule anything).
 *
 * Safety: a post Facebook has already published is never deleted (withdraw reads its state first once the time has
 * come); a lost connection on the final call is remembered (`fb_started_at`) so a retry looks for the post on the
 * Page before making it again; `fb_held` records what Facebook holds (the approval fingerprint and the time).
 */
final class FacebookSender
{
    private ?Closure $gate = null;

    public function __construct(private readonly MetaGraph $graph) {}

    /** A check made right before the final send: false cancels it (the approval changed meanwhile). */
    public function setGate(?Closure $gate): void
    {
        $this->gate = $gate;
    }

    public function handOver(PostTarget $target, CarbonImmutable $at): void
    {
        $this->requireLive();
        $id = $this->send($target, $at);
        if ($id === null) {
            return;
        }
        $this->held($target, ['external_id' => $id, 'state' => TargetState::HandedToFacebook, 'last_error' => null], $at);
    }

    public function publishNow(PostTarget $target): void
    {
        $this->requireLive();
        $id = $this->send($target, null);
        if ($id === null) {
            return;
        }
        $this->markPublished($target, $id, null);
    }

    public function reschedule(PostTarget $target, CarbonImmutable $at): void
    {
        $this->requireLive();
        $this->graph->post((string) $target->external_id, ['scheduled_publish_time' => $at->timestamp], $this->token($target));
        $this->held($target, [], $at);
    }

    /** Reads whether a handed-over post is public yet (a read only; allowed in any mode). */
    public function status(PostTarget $target): array
    {
        $video = $target->type === PostType::FacebookVideo;
        $info = $this->graph->get((string) $target->external_id, ['fields' => $video ? 'permalink_url,published' : 'permalink_url,is_published'], $this->token($target));

        return ['public' => (bool) ($info['is_published'] ?? $info['published'] ?? false), 'permalink' => $info['permalink_url'] ?? null];
    }

    /**
     * Deletes Facebook's scheduled copy (and its temporary photos) and puts the target back to "not sent yet". Once
     * the post time has come it first asks Facebook whether the post is already public; a public post is kept and
     * recorded as published, and if Facebook cannot say, nothing is deleted (the error goes to the caller).
     */
    public function withdraw(PostTarget $target): void
    {
        $this->requireLive();
        $token = $this->token($target);
        if (filled($target->external_id)) {
            $due = $target->post->scheduled_at !== null && CarbonImmutable::now()->greaterThanOrEqualTo($target->post->scheduled_at->subMinute());
            if ($due) {
                $info = $this->status($target);
                if ($info['public']) {
                    $this->markPublished($target, (string) $target->external_id, $info['permalink']);

                    return;
                }
            }
            try {
                $this->graph->delete((string) $target->external_id, $token);
            } catch (MetaError $e) {
                // Already gone (the owner deleted it on Facebook): nothing is left to take back.
                if ($e->code() !== 100) {
                    throw $e;
                }
            }
        }
        foreach ($target->work['fb_media'] ?? [] as $photo) {
            try {
                $this->graph->delete((string) $photo, $token);
            } catch (Throwable) {
                // An unused temporary photo is removed by Facebook itself within a day.
            }
        }
        $work = $target->work ?? [];
        unset($work['fb_media'], $work['fb_held']);
        $target->update(['state' => TargetState::Waiting, 'external_id' => null, 'permalink' => null, 'work' => $work]);
    }

    private function markPublished(PostTarget $target, string $id, ?string $permalink): void
    {
        if ($permalink === null) {
            try {
                $permalink = $this->graph->get($id, ['fields' => 'permalink_url'], $this->token($target))['permalink_url'] ?? null;
            } catch (Throwable) {
                // The post is public; a missing link is only a gap in the record.
            }
        }
        $work = $target->work ?? [];
        unset($work['fb_started_at'], $work['fb_held']);
        $target->update([
            'state' => TargetState::Published, 'external_id' => $id, 'permalink' => $permalink,
            'published_at' => CarbonImmutable::now(), 'last_error' => null, 'work' => $work,
        ]);
    }

    /** Saves what Facebook now holds (fingerprint and time) together with $attributes. */
    private function held(PostTarget $target, array $attributes, CarbonImmutable $at): void
    {
        $work = $target->work ?? [];
        unset($work['fb_started_at']);
        $work['fb_held'] = [
            'fingerprint' => app(Approval::class)->fingerprint($target->post),
            'at' => $at->utc()->toIso8601String(),
        ];
        $target->update($attributes + ['work' => $work]);
    }

    /** @return string|null the Facebook id, or null when an earlier attempt's post was found and recorded instead */
    private function send(PostTarget $target, ?CarbonImmutable $at): ?string
    {
        $target->loadMissing('post.media');
        $page = TargetAccount::for($target)->external_id;
        $token = $this->token($target);
        $caption = CaptionBuilder::for($target);

        if (filled($target->work['fb_started_at'] ?? null) && blank($target->external_id)) {
            $this->adoptOrFail($target, $page, $token, $at, $caption);

            return null;
        }

        $schedule = $at === null ? [] : [
            'published' => false, 'scheduled_publish_time' => $at->timestamp, 'unpublished_content_type' => 'SCHEDULED',
        ];
        $params = ['message' => $caption] + $schedule;
        if (filled($target->link)) {
            $params['link'] = $target->link;
        }
        $video = $target->type === PostType::FacebookVideo ? ($target->post->media->first() ?? throw new RuntimeException('This video post has no video file.')) : null;
        if ($target->type === PostType::FacebookPhotos) {
            $ids = [];
            foreach ($target->post->media as $media) {
                /** @var PostMedia $media */
                $ids[] = $this->idOf($this->graph->upload("{$page}/photos", ['published' => false, 'temporary' => true], $media->fullPath(), $token));
            }
            // Saved at once so a failure further on can still clean these up (a retry keeps the new ids only).
            $target->update(['work' => array_merge($target->work ?? [], ['fb_media' => $ids])]);
            foreach ($ids as $i => $id) {
                $params["attached_media[{$i}]"] = ['media_fbid' => $id];
            }
        }

        $this->checkGate($target);
        // From here a lost connection may still have created the post: remember it for the retry.
        $target->update(['work' => array_merge($target->work ?? [], ['fb_started_at' => CarbonImmutable::now()->toIso8601String()])]);
        try {
            return $video !== null
                ? $this->idOf($this->graph->upload("{$page}/videos", ['description' => $caption] + $schedule, $video->fullPath(), $token, true))
                : $this->idOf($this->graph->post("{$page}/feed", $params, $token));
        } catch (MetaError $e) {
            if ($e->code() !== 0) {
                // Facebook answered with a refusal: nothing was created, a retry may send.
                $work = $target->work ?? [];
                unset($work['fb_started_at']);
                $target->update(['work' => $work]);
            }
            throw $e;
        }
    }

    /** A retry after a lost connection: adopt the post if Facebook made it, otherwise stop and ask the owner. */
    private function adoptOrFail(PostTarget $target, string $page, string $token, ?CarbonImmutable $at, string $caption): void
    {
        $started = CarbonImmutable::parse((string) $target->work['fb_started_at'])->subSeconds(30);
        $edge = $at === null ? 'published_posts' : 'scheduled_posts';
        $fields = $at === null ? 'id,message,created_time,permalink_url' : 'id,message,scheduled_publish_time';
        $recent = $this->graph->get("{$page}/{$edge}", ['fields' => $fields, 'limit' => 10], $token)['data'] ?? [];
        $expected = self::normal($caption);
        foreach ($recent as $post) {
            $same = self::normal((string) ($post['message'] ?? $post['description'] ?? '')) === $expected;
            $recently = $at === null
                ? CarbonImmutable::parse((string) ($post['created_time'] ?? '1970-01-01'))->greaterThanOrEqualTo($started)
                : (int) ($post['scheduled_publish_time'] ?? 0) === $at->timestamp;
            if ($same && $recently) {
                $id = (string) $post['id'];
                $at === null
                    ? $this->markPublished($target, $id, $post['permalink_url'] ?? null)
                    : $this->held($target, ['external_id' => $id, 'state' => TargetState::HandedToFacebook, 'last_error' => null], $at);

                return;
            }
        }
        throw new RuntimeException('Facebook may have this post already: check the Page before trying again.');
    }

    private function checkGate(PostTarget $target): void
    {
        if ($this->gate !== null && ! ($this->gate)($target)) {
            throw new SendCancelled('The post changed while it was being sent.');
        }
    }

    private static function normal(string $text): string
    {
        return trim(str_replace("\r\n", "\n", $text));
    }

    private function token(PostTarget $target): string
    {
        return (string) TargetAccount::for($target)->access_token;
    }

    private function idOf(array $response): string
    {
        $id = (string) ($response['id'] ?? '');
        if ($id === '') {
            throw new RuntimeException('Facebook gave no id for the post.');
        }

        return $id;
    }

    private function requireLive(): void
    {
        if ($this->graph->mode() !== 'live') {
            throw new PublishBlocked;
        }
    }
}
