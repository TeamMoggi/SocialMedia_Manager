<?php

namespace Tests\Feature;

use App\Domain\Publishing\WakeTasks;
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

class WakeTasksTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function target(PostType $type, string $scheduledUtc, array $target = [], PostState $post = PostState::Approved): PostTarget
    {
        $brand = Brand::where('slug', 'moggies-international')->firstOrFail();
        $p = Post::create(['brand_id' => $brand->id, 'caption' => 'x', 'state' => $post, 'scheduled_at' => CarbonImmutable::parse($scheduledUtc, 'UTC')]);

        return PostTarget::create(array_merge(['post_id' => $p->id, 'network' => $type->network(), 'type' => $type], $target));
    }

    private function times(string $nowUtc): array
    {
        return array_map(fn (CarbonImmutable $t) => $t->utc()->format('Y-m-d H:i'), (new WakeTasks(enabled: false))->times(CarbonImmutable::parse($nowUtc, 'UTC')));
    }

    public function test_times_include_instagram_lead_and_post_time_and_facebook_post_time_only(): void
    {
        $this->target(PostType::InstagramPhoto, '2026-12-04 06:00:00');
        $this->target(PostType::InstagramReel, '2026-12-05 06:00:00');
        $this->target(PostType::FacebookText, '2026-12-06 06:00:00');

        $this->assertSame([
            '2026-12-04 05:45', '2026-12-04 06:00',
            '2026-12-05 05:30', '2026-12-05 06:00',
            '2026-12-06 06:00',
        ], $this->times('2026-12-03 10:00:00'));
    }

    public function test_times_drop_past_times_published_handed_over_and_unapproved_targets(): void
    {
        $this->target(PostType::InstagramPhoto, '2026-12-04 06:00:00', ['state' => TargetState::Published]);
        $this->target(PostType::FacebookText, '2026-12-04 07:00:00', ['state' => TargetState::HandedToFacebook]);
        $this->target(PostType::InstagramPhoto, '2026-12-04 08:00:00', [], PostState::AwaitingApproval);
        $this->target(PostType::InstagramPhoto, '2026-12-04 09:00:00', ['state' => TargetState::Queued, 'work' => ['relay' => ['a']]]);

        // At 08:50 the lead time of the 09:00 post has passed: only its publish time is left to wake for.
        $this->assertSame(['2026-12-04 09:00'], $this->times('2026-12-04 08:50:00'));
    }

    public function test_sync_passes_the_times_to_the_script_and_does_nothing_when_disabled(): void
    {
        $this->target(PostType::FacebookText, '2026-12-06 06:00:00');
        $calls = [];

        (new WakeTasks(run: function (array $times) use (&$calls): void {
            $calls[] = $times;
        }, enabled: true))->sync(CarbonImmutable::parse('2026-12-03 10:00:00', 'UTC'));
        (new WakeTasks(run: function (array $times) use (&$calls): void {
            $calls[] = 'should not run';
        }, enabled: false))->sync(CarbonImmutable::parse('2026-12-03 10:00:00', 'UTC'));

        $this->assertSame([['2026-12-06T06:00:00+00:00']], $calls);
    }

    public function test_a_failing_script_never_breaks_the_caller(): void
    {
        $this->target(PostType::FacebookText, '2026-12-06 06:00:00');

        (new WakeTasks(run: fn (array $times) => throw new \RuntimeException('no permission'), enabled: true))
            ->sync(CarbonImmutable::parse('2026-12-03 10:00:00', 'UTC'));

        $this->addToAssertionCount(1);
    }
}
