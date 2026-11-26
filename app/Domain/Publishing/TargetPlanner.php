<?php

namespace App\Domain\Publishing;

use App\Enums\Network;
use App\Enums\PostState;
use App\Enums\PostType;
use App\Enums\TargetState;
use App\Models\PostMedia;
use App\Models\PostTarget;
use Carbon\CarbonImmutable;

/**
 * Decides, as a pure function of the target and the time, what to do with one network version of a post:
 * hand it to Facebook, prepare or publish Instagram, retry, go late, catch up the next day, or give up.
 * It never writes to the database or the network.
 */
class TargetPlanner
{
    public function plan(PostTarget $target, CarbonImmutable $now): Action
    {
        $post = $target->post;
        $at = $post->scheduled_at;
        if ($post->state !== PostState::Approved || $at === null) {
            return Action::Nothing;
        }

        // After "Post now" the on-time window runs from the moment the owner pressed it.
        $base = filled($target->work['post_now_at'] ?? null) ? CarbonImmutable::parse($target->work['post_now_at']) : $at;
        $onTimeEnd = $base->addMinutes((int) config('smm.publishing.on_time_minutes'));

        return match ($target->state) {
            TargetState::Published, TargetState::HandedToFacebook, TargetState::Missed => Action::Nothing,
            TargetState::Late => $this->late($target, $now),
            TargetState::Failed => $this->failed($target, $now, $onTimeEnd),
            TargetState::Waiting, TargetState::Queued => $this->unsent($target, $now, $at, $onTimeEnd),
        };
    }

    /** Minutes before the post time at which Instagram work starts: longer for video and carousels. */
    public function leadMinutes(PostTarget $target): int
    {
        $heavy = in_array($target->type, [PostType::InstagramReel, PostType::InstagramCarousel, PostType::FacebookVideo], true)
            || $target->post->media->contains(fn (PostMedia $media): bool => str_starts_with((string) $media->mime, 'video/'));

        return (int) config('smm.publishing.lead_minutes.'.($heavy ? 'heavy' : 'light'));
    }

    /** When to retry after the $attempts-th failure: after 1, 4 and 10 minutes, then never. */
    public function nextRetryAt(int $attempts, CarbonImmutable $from): ?CarbonImmutable
    {
        $minutes = config('smm.publishing.retry_minutes')[$attempts - 1] ?? null;

        return $minutes === null ? null : $from->addMinutes((int) $minutes);
    }

    /** The end of the catch-up days: the start of the day after day +3 (brand time zone). */
    public function windowEnd(PostTarget $target): CarbonImmutable
    {
        return $this->dayStart($target, $target->post->scheduled_at)->addDays((int) config('smm.publishing.catch_up_days') + 1);
    }

    /** The start of the calendar day containing $time, in the brand's time zone. */
    public function dayStart(PostTarget $target, CarbonImmutable $time): CarbonImmutable
    {
        $zone = $target->post->brand->timezone ?: (string) config('smm.display_timezone');

        return $time->setTimezone($zone)->startOfDay();
    }

    private function unsent(PostTarget $target, CarbonImmutable $now, CarbonImmutable $at, CarbonImmutable $onTimeEnd): Action
    {
        // "Post now" from the Missed list: send at once, whatever the planned time was.
        if (($target->work['post_now'] ?? false) === true) {
            if ($target->network === Network::FacebookPage) {
                return Action::PublishFacebookNow;
            }

            return $this->prepared($target) ? Action::PublishInstagram : Action::PrepareInstagram;
        }
        if ($now->greaterThan($onTimeEnd)) {
            return Action::MarkLate;
        }

        if ($target->network === Network::FacebookPage) {
            return $this->facebook($now, $at);
        }

        $prepared = $this->prepared($target);
        if ($now->greaterThanOrEqualTo($at)) {
            return $prepared ? Action::PublishInstagram : Action::PrepareInstagram;
        }
        if ($now->greaterThanOrEqualTo($at->subMinutes($this->leadMinutes($target)))) {
            return $prepared ? Action::Nothing : Action::PrepareInstagram;
        }

        return Action::Nothing;
    }

    private function prepared(PostTarget $target): bool
    {
        // Only a saved container counts: files in the folder without one are leftovers of an interrupted prepare.
        return filled($target->container_id);
    }

    private function facebook(CarbonImmutable $now, CarbonImmutable $at): Action
    {
        if ($now->greaterThanOrEqualTo($at)) {
            return Action::PublishFacebookNow;
        }
        $minutesAhead = $now->diffInMinutes($at, true);
        if ($minutesAhead < (int) config('smm.publishing.facebook_min_minutes')) {
            return Action::Nothing;
        }

        return $at->greaterThan($now->addDays((int) config('smm.publishing.facebook_max_days')))
            ? Action::Nothing
            : Action::HandToFacebook;
    }

    private function failed(PostTarget $target, CarbonImmutable $now, CarbonImmutable $onTimeEnd): Action
    {
        if (($target->work['permanent'] ?? false) === true) {
            return Action::Nothing;
        }
        $retryAt = $target->next_attempt_at;
        if ($retryAt === null || $now->greaterThan($onTimeEnd)) {
            return Action::MarkLate;
        }

        return $now->greaterThanOrEqualTo($retryAt) ? Action::RetryNow : Action::Nothing;
    }

    private function late(PostTarget $target, CarbonImmutable $now): Action
    {
        // The catch-up days are day +1 to day +3 after the planned day; after that the post is Missed.
        if ($now->greaterThanOrEqualTo($this->windowEnd($target))) {
            return Action::GiveUp;
        }
        // One try per calendar day in the brand's zone, starting the day after the planned day.
        $gate = $target->next_attempt_at ?? $this->dayStart($target, $target->post->scheduled_at)->addDay();

        return $now->lessThan($gate) ? Action::Nothing : Action::CatchUp;
    }
}
