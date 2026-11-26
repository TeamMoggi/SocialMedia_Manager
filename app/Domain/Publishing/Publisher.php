<?php

namespace App\Domain\Publishing;

use App\Domain\Meta\MetaError;
use App\Domain\Meta\MetaGraph;
use App\Domain\Meta\PublishBlocked;
use App\Domain\Posts\Approval;
use App\Domain\Posts\ApprovalRefused;
use App\Domain\Relay\RelayFolder;
use App\Enums\Network;
use App\Enums\PostState;
use App\Enums\TargetState;
use App\Models\Activity;
use App\Models\PostTarget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * One pass over everything that is due (smm:publish-due). For each network version of an approved post it asks the
 * TargetPlanner what to do, checks the approval gate first, does it, and records the result. In dry-run (always in
 * Dev) it sends nothing to Meta and only writes "dry run: would ..." lines.
 */
final class Publisher
{
    private const LABELS = [
        'hand_to_facebook' => 'hand to Facebook', 'publish_facebook_now' => 'publish on Facebook',
        'prepare_instagram' => 'prepare Instagram', 'publish_instagram' => 'publish to Instagram',
        'retry_now' => 'try again', 'mark_late' => 'mark it late', 'catch_up' => 'try again (next-day catch-up)',
        'give_up' => 'mark it missed',
    ];

    public function __construct(
        private readonly TargetPlanner $planner,
        private readonly ?FacebookSender $facebook,
        private readonly ?InstagramSender $instagram,
        private readonly Approval $approval,
        private readonly Notifier $notifier,
        private readonly bool $live,
    ) {}

    public static function make(): self
    {
        $graph = MetaGraph::make();
        $relay = RelayFolder::fromSettings();

        return new self(
            new TargetPlanner,
            new FacebookSender($graph),
            $relay === null ? null : new InstagramSender($graph, $relay),
            app(Approval::class),
            new Notifier,
            $graph->mode() === 'live',
        );
    }

    /** @return array{done: int, failed: int, late: int, missed: int, locked: bool, lines: list<string>} */
    public function run(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $stats = ['done' => 0, 'failed' => 0, 'late' => 0, 'missed' => 0, 'locked' => false, 'lines' => []];
        $lock = $this->lock();
        if ($lock === null) {
            $stats['locked'] = true;
            $stats['lines'][] = 'Another publish run is working.';

            return $stats;
        }
        try {
            $targets = PostTarget::query()
                ->whereIn('state', [TargetState::Waiting, TargetState::Queued, TargetState::HandedToFacebook, TargetState::Failed, TargetState::Late])
                ->whereHas('post', fn ($q) => $q->whereNotNull('scheduled_at'))
                ->with(['post.brand', 'post.media', 'connectedAccount'])
                ->get()
                ->sortBy(fn (PostTarget $t) => $t->post->scheduled_at->timestamp);
            foreach ($targets as $target) {
                try {
                    $this->process($target, $now, $stats);
                } catch (Throwable $e) {
                    $stats['lines'][] = "Post {$target->post_id} ({$target->network->label()}): {$e->getMessage()}";
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $stats;
    }

    /** "Post now" from the Missed list: send at once, whatever the planned time was. */
    public function postNow(PostTarget $target, string $by): void
    {
        if ($target->post->state !== PostState::Approved) {
            throw new ApprovalRefused('Approve the post again first.');
        }
        $this->reset($target, true);
        Activity::record($target->post->brand, $target->post, 'post now chosen', ['network' => $target->network->value], $by);
    }

    /** "New time" from the Missed list: a new time for the post; its unsent versions start again. */
    public function newTime(PostTarget $target, CarbonImmutable $utc, string $by): void
    {
        $this->approval->reschedule($target->post, $utc, $by);
        foreach ($target->post->targets()->whereIn('state', [TargetState::Failed, TargetState::Late, TargetState::Missed])->get() as $each) {
            $each->setRelation('post', $target->post);
            $this->reset($each, false);
        }
    }

    /** @param array{done: int, failed: int, late: int, missed: int, locked: bool, lines: list<string>} $stats */
    private function process(PostTarget $target, CarbonImmutable $now, array &$stats): void
    {
        $post = $target->post;
        $holding = in_array($target->state, [TargetState::HandedToFacebook, TargetState::Queued], true);

        if (! $this->live) {
            $this->dryRun($target, $now, $stats);

            return;
        }
        if ($post->state !== PostState::Approved) {
            if ($holding) {
                $this->takeBack($target, 'the post is no longer approved');
            }

            return;
        }
        if (! $this->approval->stillApproved($post)) {
            $this->takeBack($target, 'approval changed: not sent');
            $this->withdrawApproval($target);
            $stats['lines'][] = "Post {$post->id} ({$target->network->label()}): not sent, its approval changed.";

            return;
        }
        if ($target->state === TargetState::HandedToFacebook) {
            $this->confirmFacebook($target, $now, $stats);

            return;
        }

        for ($step = 0; $step < 2; $step++) {
            $action = $this->planner->plan($target, $now);
            if ($action === Action::Nothing) {
                return;
            }
            $stats['lines'][] = "Post {$post->id} ({$target->network->label()}): {$action->value}";
            if (! $this->perform($target, $action, $now, $stats)) {
                return;
            }
            $target->refresh()->load('post.brand', 'post.media');
        }
    }

    /** Returns true when the state changed in a way that is worth planning again (it went late). */
    private function perform(PostTarget $target, Action $action, CarbonImmutable $now, array &$stats): bool
    {
        switch ($action) {
            case Action::MarkLate:
                $this->markLate($target, $now, $stats);

                return true;
            case Action::GiveUp:
                $this->giveUp($target, $stats);

                return false;
            case Action::HandToFacebook:
                $this->attempt($target, $now, $stats, false, fn () => $this->facebook->handOver($target, $target->post->scheduled_at));
                break;
            case Action::PublishFacebookNow:
                $this->attempt($target, $now, $stats, false, fn () => $this->facebook->publishNow($target));
                break;
            case Action::PrepareInstagram:
                $this->attempt($target, $now, $stats, false, fn () => $this->prepareInstagram($target, $now));
                break;
            case Action::PublishInstagram:
                $this->attempt($target, $now, $stats, false, fn () => $this->instagramSender()->publish($target));
                break;
            case Action::RetryNow:
                $this->attempt($target, $now, $stats, false, fn () => $this->sendNow($target, $now));
                break;
            case Action::CatchUp:
                $target->update(['late_days' => $target->late_days + 1]);
                if ($target->network === Network::Instagram) {
                    $this->instagramSender()->abandon($target);
                }
                $this->attempt($target, $now, $stats, true, fn () => $this->sendNow($target, $now));
                break;
            case Action::Nothing:
                break;
        }

        return false;
    }

    private function attempt(PostTarget $target, CarbonImmutable $now, array &$stats, bool $catchUp, callable $send): void
    {
        $post = $target->post;
        try {
            $send();
        } catch (PublishBlocked) {
            return;
        } catch (Throwable $e) {
            $this->failure($target, $e, $now, $stats, $catchUp);

            return;
        }
        $target->refresh();
        Activity::record($post->brand, $post, 'send step done', ['network' => $target->network->value, 'state' => $target->state->value], 'publisher');
        if ($target->state === TargetState::Published) {
            $stats['done']++;
            $label = $target->network->label();
            $late = $target->late_days > 0 ? ' late' : '';
            Activity::record($post->brand, $post, 'published', ['network' => $target->network->value, 'link' => $target->permalink, 'late_days' => $target->late_days], 'publisher');
            $this->notifier->published($target, "{$label}: published{$late}.".($target->permalink ? " {$target->permalink}" : ''));
        }
    }

    private function sendNow(PostTarget $target, CarbonImmutable $now): void
    {
        if ($target->network === Network::FacebookPage) {
            $post = $target->post;
            $postNow = ($target->work['post_now'] ?? false) === true;
            if ($now->lessThan($post->scheduled_at) && ! $postNow) {
                $this->facebook->handOver($target, $post->scheduled_at);
            } else {
                $this->facebook->publishNow($target);
            }

            return;
        }
        if (blank($target->container_id)) {
            $this->prepareInstagram($target, $now);

            return;
        }
        $this->instagramSender()->publish($target);
    }

    /** Prepares Instagram, and publishes straight away when the post time has come. */
    private function prepareInstagram(PostTarget $target, CarbonImmutable $now): void
    {
        if (blank($target->container_id)) {
            $this->instagramSender()->prepare($target);
        }
        $target->refresh();
        $due = $now->greaterThanOrEqualTo($target->post->scheduled_at) || ($target->work['post_now'] ?? false) === true;
        if ($due && $target->state === TargetState::Queued) {
            $this->instagramSender()->publish($target);
        }
    }

    private function failure(PostTarget $target, Throwable $e, CarbonImmutable $now, array &$stats, bool $catchUp): void
    {
        $post = $target->post;
        $transient = $e instanceof MetaError ? $e->transient() : $e instanceof TemporaryFailure;
        $text = $e instanceof MetaError ? $e->plain() : $e->getMessage();
        $work = $target->fresh()->work ?? [];
        Activity::record($post->brand, $post, 'send failed', ['network' => $target->network->value, 'error' => $text, 'transient' => $transient, 'catch_up' => $catchUp], 'publisher');

        if ($transient && $catchUp) {
            $next = $this->planner->dayStart($target, $now)->addDay();
            $target->update(['state' => TargetState::Late, 'last_error' => $text, 'next_attempt_at' => $next]);
            if ($next->greaterThanOrEqualTo($this->planner->windowEnd($target))) {
                $this->giveUp($target, $stats);
            }

            return;
        }
        if ($transient) {
            $attempts = $target->attempts + 1;
            $target->update([
                'state' => TargetState::Failed, 'attempts' => $attempts, 'last_error' => $text,
                'next_attempt_at' => $this->planner->nextRetryAt($attempts, $now),
            ]);

            return;
        }
        $this->instagram?->cleanRelay($target);
        $work['permanent'] = true;
        $target->update(['state' => TargetState::Failed, 'last_error' => $text, 'next_attempt_at' => null, 'work' => $work]);
        $stats['failed']++;
        $this->notifier->failed($target, "{$target->network->label()}: {$text}");
    }

    private function markLate(PostTarget $target, CarbonImmutable $now, array &$stats): void
    {
        $post = $target->post;
        if ($target->network === Network::Instagram && $this->instagram !== null) {
            $this->instagram->abandon($target);
        }
        $target->update(['state' => TargetState::Late, 'next_attempt_at' => null]);
        $stats['late']++;
        Activity::record($post->brand, $post, 'not sent on time', ['network' => $target->network->value], 'publisher');
        if ($now->lessThan($this->planner->windowEnd($target))) {
            $this->notifier->late($target, "{$target->network->label()}: the post was not sent on time. The app will try again tomorrow, for up to {$this->days()} days.");
        }
    }

    private function giveUp(PostTarget $target, array &$stats): void
    {
        $post = $target->post;
        if ($target->network === Network::Instagram && $this->instagram !== null) {
            $this->instagram->abandon($target);
        }
        $target->update(['state' => TargetState::Missed, 'next_attempt_at' => null]);
        $stats['missed']++;
        Activity::record($post->brand, $post, 'missed', ['network' => $target->network->value, 'late_days' => $target->late_days], 'publisher');
        $this->notifier->missed($target, "{$target->network->label()}: the post could not be sent in {$this->days()} days. Open Missed posts to post it now or choose a new time.");
    }

    /** Facebook publishes a handed-over post itself; this notices that it did, or that it did not. */
    private function confirmFacebook(PostTarget $target, CarbonImmutable $now, array &$stats): void
    {
        $at = $target->post->scheduled_at;
        if ($now->lessThan($at->addMinutes(5))) {
            return;
        }
        $overdue = $now->greaterThanOrEqualTo($at->addMinutes(60));
        $info = ['public' => false, 'permalink' => null];
        $error = null;
        try {
            $info = $this->facebook->status($target);
        } catch (Throwable $e) {
            $error = $e instanceof MetaError ? $e->plain() : $e->getMessage();
        }
        $public = $info['public'];
        if ($public) {
            $target->update(['state' => TargetState::Published, 'permalink' => $info['permalink'], 'published_at' => $at, 'last_error' => null]);
            $stats['done']++;
            Activity::record($target->post->brand, $target->post, 'published', ['network' => 'facebook_page', 'link' => $info['permalink']], 'publisher');
            $this->notifier->published($target, 'Facebook: published.'.($info['permalink'] ? " {$info['permalink']}" : ''));

            return;
        }
        if ($overdue) {
            $work = $target->work ?? [];
            $work['permanent'] = true;
            $text = $error !== null ? "Facebook did not confirm the post: {$error}" : 'Facebook has not published the scheduled post an hour after its time: check the Page.';
            $target->update(['state' => TargetState::Failed, 'last_error' => $text, 'work' => $work]);
            $stats['failed']++;
            $this->notifier->failed($target, "Facebook: {$text}");
        }
    }

    private function takeBack(PostTarget $target, string $why): void
    {
        $post = $target->post;
        try {
            if ($target->state === TargetState::HandedToFacebook && $this->facebook !== null) {
                $this->facebook->withdraw($target);
            } elseif ($target->state === TargetState::Queued && $this->instagram !== null) {
                $this->instagram->abandon($target);
            }
        } catch (Throwable $e) {
            $target->update(['last_error' => 'Could not take the post back from '.$target->network->label().': check the Page.']);
        }
        Activity::record($post->brand, $post, $why, ['network' => $target->network->value], 'publisher');
    }

    /** The approval no longer covers what would be sent: back to "waiting for approval", with a notice. */
    private function withdrawApproval(PostTarget $target): void
    {
        $post = $target->post->fresh();
        if ($post->state !== PostState::Approved) {
            return;
        }
        if (! $this->approval->reopenIfChanged($post, 'publisher')) {
            $reason = collect($this->approval->problems($post, checkTime: false))->first(fn ($p) => $p->blocks())?->message ?? 'it can no longer be sent';
            $this->approval->withdraw($post, 'publisher', $reason);
        }
        $this->notifier->failed($target, "{$target->network->label()}: the post was not sent because it changed or can no longer be sent. Approve it again.");
    }

    private function reset(PostTarget $target, bool $postNow): void
    {
        $work = $target->work ?? [];
        unset($work['permanent'], $work['publish_started_at'], $work['post_now']);
        if ($postNow) {
            $work['post_now'] = true;
        }
        $target->update([
            'state' => TargetState::Waiting, 'attempts' => 0, 'late_days' => 0, 'next_attempt_at' => null,
            'last_error' => null, 'work' => $work,
        ]);
        if ($target->network === Network::Instagram) {
            $this->instagram?->abandon($target);
        }
    }

    private function dryRun(PostTarget $target, CarbonImmutable $now, array &$stats): void
    {
        $post = $target->post;
        if ($post->state !== PostState::Approved) {
            return;
        }
        $action = $this->planner->plan($target, $now);
        if ($action === Action::Nothing) {
            return;
        }
        $line = 'dry run: would '.self::LABELS[$action->value];
        $subject = $target->network->label();
        $exists = Activity::query()->where('subject_type', $post->getMorphClass())->where('subject_id', $post->id)->where('action', $line)->exists();
        $stats['lines'][] = "Post {$post->id} ({$subject}): {$line}";
        if (! $exists) {
            Activity::record($post->brand, $post, $line, ['network' => $target->network->value], 'publisher');
        }
    }

    private function instagramSender(): InstagramSender
    {
        return $this->instagram ?? throw new \RuntimeException('Set up the Hostinger folder first: Instagram posts go through it.');
    }

    private function days(): int
    {
        return (int) config('smm.publishing.catch_up_days');
    }

    /** @return resource|null */
    private function lock()
    {
        $dir = app('smm.data_dir').DIRECTORY_SEPARATOR.'run';
        File::ensureDirectoryExists($dir);
        $handle = fopen($dir.DIRECTORY_SEPARATOR.'publish.lock', 'c');
        if ($handle === false || ! flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            return null;
        }

        return $handle;
    }
}
