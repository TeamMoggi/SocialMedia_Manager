<?php

namespace Tests\Feature;

use App\Filament\Pages\Calendar;
use App\Filament\Pages\MissedPosts;
use App\Filament\Resources\Posts\PostResource;
use App\Enums\Network;
use App\Enums\PostState;
use App\Enums\PostType;
use App\Enums\TargetState;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MissedPostsPageTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::where('email', User::LOCAL_OWNER_EMAIL)->firstOrFail());
        Filament::setCurrentPanel('app');
        $this->brand = Brand::where('slug', 'moggies-international')->firstOrFail();
        Filament::setTenant($this->brand);
    }

    private function target(TargetState $state, ?Brand $brand = null, string $caption = 'Raisins', array $extra = []): PostTarget
    {
        $post = Post::create(['brand_id' => ($brand ?? $this->brand)->id, 'caption' => $caption, 'state' => PostState::Approved, 'scheduled_at' => CarbonImmutable::now()->subDays(2)]);

        return PostTarget::create(array_merge(['post_id' => $post->id, 'network' => Network::Instagram, 'type' => PostType::InstagramPhoto, 'state' => $state, 'last_error' => 'Meta had a problem'], $extra));
    }

    public function test_page_lists_only_this_brands_missed_late_and_failed_targets(): void
    {
        $other = Brand::create(['name' => 'Other brand']);
        $missed = $this->target(TargetState::Missed);
        $late = $this->target(TargetState::Late, caption: 'Late one');
        $failed = $this->target(TargetState::Failed, caption: 'Failed one');
        $published = $this->target(TargetState::Published, caption: 'Done one');
        $foreign = $this->target(TargetState::Missed, $other, 'Foreign one');

        Livewire::test(MissedPosts::class)
            ->assertCanSeeTableRecords([$missed, $late, $failed])
            ->assertCanNotSeeTableRecords([$published, $foreign]);
    }

    public function test_navigation_badge_counts_missed_and_late_targets(): void
    {
        $this->assertNull(MissedPosts::getNavigationBadge());
        $this->target(TargetState::Missed);
        $this->target(TargetState::Late);
        $this->target(TargetState::Published);

        $this->assertSame('2', MissedPosts::getNavigationBadge());
    }

    public function test_post_now_resets_the_target_for_the_next_pass(): void
    {
        $t = $this->target(TargetState::Missed, extra: ['late_days' => 3, 'attempts' => 4]);

        Livewire::test(MissedPosts::class)->callTableAction('post_now', $t);

        $fresh = $t->fresh();
        $this->assertSame([TargetState::Waiting, 0], [$fresh->state, $fresh->late_days]);
        $this->assertTrue($fresh->work['post_now']);
    }

    public function test_new_time_sets_a_future_time_and_refuses_a_past_one(): void
    {
        $t = $this->target(TargetState::Missed);
        $future = CarbonImmutable::now('Asia/Kolkata')->addDays(2)->setTime(10, 0);

        Livewire::test(MissedPosts::class)->callTableAction('new_time', $t, ['time' => $future->format('Y-m-d H:i:s')]);

        $this->assertSame(TargetState::Waiting, $t->fresh()->state);
        $this->assertSame($future->utc()->format('Y-m-d H:i'), $t->post->fresh()->scheduled_at->utc()->format('Y-m-d H:i'));

        $again = $this->target(TargetState::Missed);
        Livewire::test(MissedPosts::class)->callTableAction('new_time', $again, ['time' => CarbonImmutable::now('Asia/Kolkata')->subDay()->format('Y-m-d H:i:s')]);
        $this->assertSame(TargetState::Missed, $again->fresh()->state);
    }

    public function test_calendar_colours_follow_what_happened_when_sending(): void
    {
        $published = $this->target(TargetState::Published, caption: 'A');
        $queued = $this->target(TargetState::Queued, caption: 'B');
        $late = $this->target(TargetState::Late, caption: 'C');
        $missed = $this->target(TargetState::Missed, caption: 'D');
        foreach ([$published, $queued, $late, $missed] as $t) {
            $t->post->update(['scheduled_at' => CarbonImmutable::parse('2026-12-04 06:00:00', 'UTC')]);
        }

        $events = collect(Livewire::test(Calendar::class)->instance()->calendarEvents('2026-12-03T00:00:00Z', '2026-12-06T00:00:00Z'))
            ->keyBy('id');

        $this->assertSame('#16a34a', $events[$published->post_id]['color']);
        $this->assertSame('#2563eb', $events[$queued->post_id]['color']);
        $this->assertSame('#d97706', $events[$late->post_id]['color']);
        $this->assertSame('#dc2626', $events[$missed->post_id]['color']);
    }

    public function test_post_list_shows_each_networks_sending_state(): void
    {
        $t = $this->target(TargetState::Published);
        PostTarget::create(['post_id' => $t->post_id, 'network' => Network::FacebookPage, 'type' => PostType::FacebookText, 'state' => TargetState::Late]);

        $this->assertSame(['Facebook: Late: will retry', 'Instagram: Published'], PostResource::sendingSummary($t->post->fresh()));
    }
}
