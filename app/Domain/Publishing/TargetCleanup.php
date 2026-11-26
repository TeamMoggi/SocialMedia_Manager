<?php

namespace App\Domain\Publishing;

use App\Domain\Meta\MetaGraph;
use App\Domain\Relay\RelayFolder;
use App\Enums\Network;
use App\Enums\TargetState;
use App\Models\PostTarget;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Keeps what Facebook and the Hostinger folder hold in step with the post when the owner changes it: withdrawing
 * the approval, moving the time, or deleting the post. A published version is never touched. If Facebook cannot be
 * reached to take a scheduled copy back, the version stays "handed to Facebook" with a note and the publisher
 * tries again every pass, so an old copy is never left to publish silently.
 */
final class TargetCleanup
{
    public function __construct(
        private readonly ?FacebookSender $facebook,
        private readonly ?InstagramSender $instagram,
        private readonly bool $live,
    ) {}

    public static function make(): self
    {
        $graph = app(MetaGraph::class);
        $relay = app(RelayFolder::class);

        return new self(new FacebookSender($graph), $relay === null ? null : new InstagramSender($graph, $relay), $graph->mode() === 'live');
    }

    /** Takes back whatever the networks hold for this version and puts it back to "not sent yet". */
    public function release(PostTarget $target): void
    {
        if ($target->state === TargetState::Published) {
            return;
        }
        try {
            if ($target->state === TargetState::HandedToFacebook && $this->live && $this->facebook !== null) {
                $this->facebook->withdraw($target);
            }
        } catch (Throwable) {
            $this->couldNotTakeBack($target);

            return;
        }
        if ($target->network === Network::Instagram) {
            $this->instagram?->abandon($target);
        }
        $work = $target->fresh()->work ?? [];
        unset($work['permanent'], $work['publish_started_at'], $work['post_now'], $work['fb_media'], $work['children']);
        $target->update([
            'state' => TargetState::Waiting, 'external_id' => null, 'container_id' => null, 'permalink' => null,
            'attempts' => 0, 'late_days' => 0, 'next_attempt_at' => null, 'last_error' => null, 'work' => $work,
        ]);
    }

    /** The post's time changed: Facebook gets the new time while it is inside its window; anything else starts again. */
    public function moved(PostTarget $target, CarbonImmutable $at): void
    {
        if ($target->state === TargetState::Published) {
            return;
        }
        $now = CarbonImmutable::now();
        $inWindow = $at->greaterThanOrEqualTo($now->addMinutes((int) config('smm.publishing.facebook_min_minutes')))
            && $at->lessThanOrEqualTo($now->addDays((int) config('smm.publishing.facebook_max_days')));
        if ($target->state === TargetState::HandedToFacebook && $this->live && $this->facebook !== null && $inWindow) {
            try {
                $this->facebook->reschedule($target, $at);

                return;
            } catch (Throwable) {
                // Taken back below; the publisher hands it over again at the new time.
            }
        }
        $this->release($target);
    }

    private function couldNotTakeBack(PostTarget $target): void
    {
        $text = 'Could not take the post back from '.$target->network->label().': check the Page.';
        $target->update(['last_error' => $text]);
        try {
            app(Notifier::class)->failed($target->loadMissing('post.brand'), $text);
        } catch (Throwable) {
            // The note on the post is enough if the notice cannot be sent.
        }
    }
}
