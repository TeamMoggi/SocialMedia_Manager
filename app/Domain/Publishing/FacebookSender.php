<?php

namespace App\Domain\Publishing;

use App\Domain\Meta\MetaGraph;
use App\Domain\Meta\PublishBlocked;
use App\Domain\Posts\CaptionBuilder;
use App\Enums\PostType;
use App\Enums\TargetState;
use App\Models\PostMedia;
use App\Models\PostTarget;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

/**
 * Facebook Page sending: hand a post to Facebook with a scheduled time, publish it now, change that time, or take
 * it back. Meta's v27.0 pages (read 26 Nov 2026): /feed takes published=false + scheduled_publish_time +
 * unpublished_content_type=SCHEDULED (10 minutes to 75 days ahead); photos for a post are uploaded to /photos with
 * published=false + temporary=true and joined by attached_media; a scheduled post is changed or deleted through its
 * own id. Nothing is sent unless the graph is live (so Dev can never upload or schedule anything).
 */
final class FacebookSender
{
    public function __construct(private readonly MetaGraph $graph) {}

    public function handOver(PostTarget $target, CarbonImmutable $at): void
    {
        $this->requireLive();
        $id = $this->send($target, $at);
        $target->update(['state' => TargetState::HandedToFacebook, 'external_id' => $id, 'last_error' => null]);
    }

    public function publishNow(PostTarget $target): void
    {
        $this->requireLive();
        $id = $this->send($target, null);
        $target->update(['external_id' => $id]);
        $permalink = null;
        try {
            $permalink = $this->graph->get($id, ['fields' => 'permalink_url'], $this->token($target))['permalink_url'] ?? null;
        } catch (Throwable) {
            // The post is public; a missing link is only a gap in the record.
        }
        $target->update([
            'state' => TargetState::Published, 'permalink' => $permalink, 'published_at' => CarbonImmutable::now(),
            'last_error' => null,
        ]);
    }

    public function reschedule(PostTarget $target, CarbonImmutable $at): void
    {
        $this->requireLive();
        $this->graph->post((string) $target->external_id, ['scheduled_publish_time' => $at->timestamp], $this->token($target));
    }

    /** Deletes Facebook's scheduled copy (and its temporary photos) and puts the target back to "not sent yet". */
    public function withdraw(PostTarget $target): void
    {
        $this->requireLive();
        $token = $this->token($target);
        if (filled($target->external_id)) {
            $this->graph->delete((string) $target->external_id, $token);
        }
        foreach ($target->work['fb_media'] ?? [] as $photo) {
            try {
                $this->graph->delete((string) $photo, $token);
            } catch (Throwable) {
                // An unused temporary photo is removed by Facebook itself within a day.
            }
        }
        $work = $target->work ?? [];
        unset($work['fb_media']);
        $target->update(['state' => TargetState::Waiting, 'external_id' => null, 'permalink' => null, 'work' => $work]);
    }

    private function send(PostTarget $target, ?CarbonImmutable $at): string
    {
        $target->loadMissing('post.media');
        $page = TargetAccount::for($target)->external_id;
        $token = $this->token($target);
        $schedule = $at === null ? [] : [
            'published' => false, 'scheduled_publish_time' => $at->timestamp, 'unpublished_content_type' => 'SCHEDULED',
        ];
        $caption = CaptionBuilder::for($target);

        if ($target->type === PostType::FacebookVideo) {
            $video = $target->post->media->first() ?? throw new RuntimeException('This video post has no video file.');
            $response = $this->graph->upload("{$page}/videos", ['description' => $caption] + $schedule, $video->fullPath(), $token, true);

            return $this->idOf($response);
        }

        $params = ['message' => $caption] + $schedule;
        if (filled($target->link)) {
            $params['link'] = $target->link;
        }
        if ($target->type === PostType::FacebookPhotos) {
            $ids = [];
            foreach ($target->post->media as $media) {
                /** @var PostMedia $media */
                $ids[] = $this->idOf($this->graph->upload("{$page}/photos", ['published' => false, 'temporary' => true], $media->fullPath(), $token));
            }
            // Saved at once so a failure further on can still clean these up.
            $target->update(['work' => ($target->work ?? []) + ['fb_media' => $ids]]);
            foreach ($ids as $i => $id) {
                $params["attached_media[{$i}]"] = ['media_fbid' => $id];
            }
        }

        return $this->idOf($this->graph->post("{$page}/feed", $params, $token));
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
