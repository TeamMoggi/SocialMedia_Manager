<?php

namespace Tests\Feature;

use App\Domain\Publishing\Action;
use App\Domain\Publishing\TargetPlanner;
use App\Enums\Network;
use App\Enums\PostState;
use App\Enums\PostType;
use App\Enums\TargetState;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostTarget;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TargetPlannerTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function at(string $utc): CarbonImmutable
    {
        return CarbonImmutable::parse($utc, 'UTC');
    }

    /** @param array<string, mixed> $target */
    private function target(PostType $type, string $scheduledUtc, array $target = [], PostState $state = PostState::Approved): PostTarget
    {
        $brand = Brand::where('slug', 'moggies-international')->firstOrFail();
        $post = Post::create(['brand_id' => $brand->id, 'caption' => 'x', 'state' => $state, 'scheduled_at' => $this->at($scheduledUtc)]);

        return PostTarget::create(array_merge([
            'post_id' => $post->id, 'network' => $type->network(), 'type' => $type, 'state' => TargetState::Waiting,
        ], $target));
    }

    private function plan(PostTarget $t, string $nowUtc): Action
    {
        return (new TargetPlanner)->plan($t->fresh(['post.brand', 'post.media']), $this->at($nowUtc));
    }

    public function test_facebook_inside_the_window_is_handed_over(): void
    {
        $t = $this->target(PostType::FacebookText, '2026-12-04 06:00:00');

        $this->assertSame(Action::HandToFacebook, $this->plan($t, '2026-12-03 06:00:00'));
        $this->assertSame(Action::HandToFacebook, $this->plan($t, '2026-12-04 05:50:00'));
    }

    public function test_facebook_further_than_75_days_waits_for_the_daily_run(): void
    {
        $t = $this->target(PostType::FacebookText, '2027-03-01 06:00:00');

        $this->assertSame(Action::Nothing, $this->plan($t, '2026-12-04 06:00:00'));
        $this->assertSame(Action::HandToFacebook, $this->plan($t, '2027-01-01 06:00:00'));
    }

    public function test_facebook_closer_than_10_minutes_waits_then_publishes_at_the_time(): void
    {
        $t = $this->target(PostType::FacebookText, '2026-12-04 06:00:00');

        $this->assertSame(Action::Nothing, $this->plan($t, '2026-12-04 05:55:00'));
        $this->assertSame(Action::PublishFacebookNow, $this->plan($t, '2026-12-04 06:00:00'));
        $this->assertSame(Action::PublishFacebookNow, $this->plan($t, '2026-12-04 06:15:00'));
    }

    public function test_instagram_photo_is_prepared_from_15_minutes_before_and_only_once(): void
    {
        $t = $this->target(PostType::InstagramPhoto, '2026-12-04 06:00:00');

        $this->assertSame(Action::Nothing, $this->plan($t, '2026-12-04 05:44:00'));
        $this->assertSame(Action::PrepareInstagram, $this->plan($t, '2026-12-04 05:45:00'));

        $prepared = $this->target(PostType::InstagramPhoto, '2026-12-04 06:00:00', ['state' => TargetState::Queued, 'work' => ['relay' => ['a.jpg']]]);
        $this->assertSame(Action::Nothing, $this->plan($prepared, '2026-12-04 05:50:00'));
    }

    public function test_instagram_reel_and_carousel_get_a_30_minute_lead(): void
    {
        $reel = $this->target(PostType::InstagramReel, '2026-12-04 06:00:00');
        $carousel = $this->target(PostType::InstagramCarousel, '2026-12-04 06:00:00');

        $planner = new TargetPlanner;
        $this->assertSame(30, $planner->leadMinutes($reel->fresh(['post.media'])));
        $this->assertSame(30, $planner->leadMinutes($carousel->fresh(['post.media'])));
        $this->assertSame(15, $planner->leadMinutes($this->target(PostType::InstagramStory, '2026-12-04 06:00:00')->fresh(['post.media'])));
        $this->assertSame(Action::PrepareInstagram, $this->plan($reel, '2026-12-04 05:30:00'));
    }

    public function test_a_video_file_makes_any_instagram_post_heavy(): void
    {
        $t = $this->target(PostType::InstagramStory, '2026-12-04 06:00:00');
        $t->post->media()->create(['position' => 1, 'path' => 'posts/1/v.mp4', 'mime' => 'video/mp4']);

        $this->assertSame(30, (new TargetPlanner)->leadMinutes($t->fresh(['post.media'])));
    }

    public function test_instagram_is_published_at_the_time_when_prepared_or_prepared_first_when_not(): void
    {
        $prepared = $this->target(PostType::InstagramPhoto, '2026-12-04 06:00:00', ['state' => TargetState::Queued, 'work' => ['relay' => ['a.jpg']]]);
        $cold = $this->target(PostType::InstagramPhoto, '2026-12-04 06:00:00');

        $this->assertSame(Action::PublishInstagram, $this->plan($prepared, '2026-12-04 06:00:00'));
        $this->assertSame(Action::PublishInstagram, $this->plan($prepared, '2026-12-04 06:15:00'));
        $this->assertSame(Action::PrepareInstagram, $this->plan($cold, '2026-12-04 06:01:00'));
    }

    public function test_published_handed_over_missed_and_unapproved_targets_do_nothing(): void
    {
        foreach ([TargetState::Published, TargetState::HandedToFacebook, TargetState::Missed] as $state) {
            $t = $this->target(PostType::InstagramPhoto, '2026-12-04 06:00:00', ['state' => $state]);
            $this->assertSame(Action::Nothing, $this->plan($t, '2026-12-04 06:01:00'));
        }
        $draft = $this->target(PostType::InstagramPhoto, '2026-12-04 06:00:00', [], PostState::AwaitingApproval);
        $this->assertSame(Action::Nothing, $this->plan($draft, '2026-12-04 06:01:00'));
    }

    public function test_failed_target_retries_when_due_inside_the_window_and_waits_before(): void
    {
        $t = $this->target(PostType::InstagramPhoto, '2026-12-04 06:00:00', [
            'state' => TargetState::Failed, 'attempts' => 1, 'next_attempt_at' => $this->at('2026-12-04 06:05:00'),
        ]);

        $this->assertSame(Action::Nothing, $this->plan($t, '2026-12-04 06:04:00'));
        $this->assertSame(Action::RetryNow, $this->plan($t, '2026-12-04 06:05:00'));
    }

    public function test_permanent_failure_is_left_alone_and_exhausted_retries_go_late(): void
    {
        $permanent = $this->target(PostType::InstagramPhoto, '2026-12-04 06:00:00', ['state' => TargetState::Failed, 'work' => ['permanent' => true]]);
        $exhausted = $this->target(PostType::InstagramPhoto, '2026-12-04 06:00:00', ['state' => TargetState::Failed, 'attempts' => 4]);

        $this->assertSame(Action::Nothing, $this->plan($permanent, '2026-12-04 06:10:00'));
        $this->assertSame(Action::MarkLate, $this->plan($exhausted, '2026-12-04 06:12:00'));
    }

    public function test_a_target_still_unsent_after_the_on_time_window_goes_late(): void
    {
        $waiting = $this->target(PostType::InstagramPhoto, '2026-12-04 06:00:00');
        $queued = $this->target(PostType::InstagramPhoto, '2026-12-04 06:00:00', ['state' => TargetState::Queued, 'work' => ['relay' => ['a']]]);
        $facebook = $this->target(PostType::FacebookText, '2026-12-04 06:00:00');

        foreach ([$waiting, $queued, $facebook] as $t) {
            $this->assertSame(Action::MarkLate, $this->plan($t, '2026-12-04 06:16:00'));
        }
    }

    public function test_late_target_waits_for_the_next_calendar_day_in_the_brand_zone(): void
    {
        // 23:50 IST on 4 Dec = 18:20 UTC. The next IST day starts at 00:00 IST = 18:30 UTC.
        $t = $this->target(PostType::InstagramPhoto, '2026-12-04 18:20:00', ['state' => TargetState::Late]);

        $this->assertSame(Action::Nothing, $this->plan($t, '2026-12-04 18:29:00'));
        $this->assertSame(Action::CatchUp, $this->plan($t, '2026-12-04 18:40:00'));
    }

    public function test_late_target_uses_its_own_next_attempt_time_and_the_three_day_limit(): void
    {
        $second = $this->target(PostType::InstagramPhoto, '2026-12-04 06:00:00', [
            'state' => TargetState::Late, 'late_days' => 1, 'next_attempt_at' => $this->at('2026-12-06 00:00:00'),
        ]);
        $this->assertSame(Action::Nothing, $this->plan($second, '2026-12-05 23:00:00'));
        $this->assertSame(Action::CatchUp, $this->plan($second, '2026-12-06 03:30:00'));

        $last = $this->target(PostType::InstagramPhoto, '2026-12-04 06:00:00', [
            'state' => TargetState::Late, 'late_days' => 3, 'next_attempt_at' => $this->at('2026-12-08 00:00:00'),
        ]);
        $this->assertSame(Action::Nothing, $this->plan($last, '2026-12-07 12:00:00'));
        $this->assertSame(Action::GiveUp, $this->plan($last, '2026-12-08 03:30:00'));
    }

    public function test_next_retry_is_after_1_then_4_then_10_minutes_then_never(): void
    {
        $planner = new TargetPlanner;
        $from = $this->at('2026-12-04 06:00:00');

        $this->assertSame('06:01', $planner->nextRetryAt(1, $from)->format('H:i'));
        $this->assertSame('06:04', $planner->nextRetryAt(2, $from)->format('H:i'));
        $this->assertSame('06:10', $planner->nextRetryAt(3, $from)->format('H:i'));
        $this->assertNull($planner->nextRetryAt(4, $from));
    }

    public function test_late_target_is_tried_on_the_first_day_the_laptop_is_on_inside_the_window(): void
    {
        $t = $this->target(PostType::InstagramPhoto, '2026-12-04 06:00:00', ['state' => TargetState::Late]);

        // Day +2 in India (laptop was off on day +1).
        $this->assertSame(Action::CatchUp, $this->plan($t, '2026-12-06 05:00:00'));
    }

    public function test_late_target_is_given_up_when_the_three_days_are_over_even_without_attempts(): void
    {
        $t = $this->target(PostType::InstagramPhoto, '2026-12-04 06:00:00', ['state' => TargetState::Late]);

        // The window ends at the start of day +4 in India = 7 Dec 18:30 UTC.
        $this->assertSame(Action::CatchUp, $this->plan($t, '2026-12-07 17:00:00'));
        $this->assertSame(Action::GiveUp, $this->plan($t, '2026-12-07 18:30:00'));
        $this->assertSame('2026-12-07 18:30:00', (new TargetPlanner)->windowEnd($t->fresh(['post.brand']))->utc()->format('Y-m-d H:i:s'));
    }

    public function test_post_now_ignores_the_time_and_the_on_time_window(): void
    {
        $facebook = $this->target(PostType::FacebookText, '2026-12-04 06:00:00', ['work' => ['post_now' => true]]);
        $instagram = $this->target(PostType::InstagramPhoto, '2026-12-04 06:00:00', ['work' => ['post_now' => true]]);

        $this->assertSame(Action::PublishFacebookNow, $this->plan($facebook, '2026-12-09 06:00:00'));
        $this->assertSame(Action::PrepareInstagram, $this->plan($instagram, '2026-12-09 06:00:00'));
    }
}
