<?php

namespace Tests\Feature;

use App\Domain\Posts\Approval;
use App\Enums\Network;
use App\Enums\PostState;
use App\Enums\PostType;
use App\Filament\Pages\Calendar;
use App\Models\Brand;
use App\Models\Post;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CalendarTest extends TestCase
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

    private function makePost(string $utc, string $caption = "Raisin grading Reel\nsecond line", ?Brand $brand = null): Post
    {
        $post = Post::create(['brand_id' => ($brand ?? $this->brand)->id, 'caption' => $caption, 'scheduled_at' => CarbonImmutable::parse($utc, 'UTC')]);
        $post->targets()->create(['network' => Network::FacebookPage, 'type' => PostType::FacebookText]);
        $post->targets()->create(['network' => Network::Instagram, 'type' => PostType::InstagramReel]);

        return $post;
    }

    public function test_events_in_range_with_colours_and_links(): void
    {
        $post = $this->makePost('2026-12-04 07:00:00');
        $this->makePost('2027-01-20 07:00:00');

        $events = Livewire::test(Calendar::class)->instance()->calendarEvents('2026-11-30T00:00:00Z', '2026-12-07T00:00:00Z');

        $this->assertCount(1, $events);
        $this->assertSame($post->id, $events[0]['id']);
        $this->assertSame('Raisin grading Reel · FB · IG', $events[0]['title']);
        $this->assertSame(PostState::Draft->color(), $events[0]['color']);
        $this->assertStringContainsString("/posts/{$post->id}/edit", $events[0]['url']);
        $this->assertTrue($events[0]['editable']);
    }

    public function test_events_use_brand_wall_clock_near_midnight(): void
    {
        // 4 Dec 00:30 IST is 3 Dec 19:00 UTC: the calendar must show it on 4 Dec.
        $post = $this->makePost('2026-12-03 19:00:00');
        $page = Livewire::test(Calendar::class)->instance();

        $events = $page->calendarEvents('2026-12-04T00:00:00Z', '2026-12-05T00:00:00Z');
        $this->assertSame('2026-12-04T00:30:00Z', $events[0]['start']);

        $result = $page->movePost($post->id, '2026-12-05T00:30:00Z');
        $this->assertTrue($result['ok']);
        $this->assertSame('2026-12-04 19:00:00', $post->fresh()->scheduled_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_move_keeps_approval(): void
    {
        $post = $this->makePost('2026-12-04 07:00:00');
        $post->update(['state' => PostState::Approved, 'approved_fingerprint' => app(Approval::class)->fingerprint($post)]);

        Livewire::test(Calendar::class)->instance()->movePost($post->id, '2026-12-06T10:00:00');

        $this->assertSame(PostState::Approved, $post->fresh()->state);
        $this->assertSame('2026-12-06 04:30:00', $post->fresh()->scheduled_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_move_to_past_is_refused_with_message(): void
    {
        $post = $this->makePost('2026-12-04 07:00:00');

        $result = Livewire::test(Calendar::class)->instance()->movePost($post->id, '2020-01-01T10:00:00');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Pick a time in the future', $result['message']);
        $this->assertSame('2026-12-04 07:00:00', $post->fresh()->scheduled_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_other_brand_post_cannot_be_moved(): void
    {
        $other = Brand::create(['name' => 'Other business']);
        $theirs = $this->makePost('2026-12-04 07:00:00', 'Theirs', $other);

        $result = Livewire::test(Calendar::class)->instance()->movePost($theirs->id, '2026-12-06T10:00:00');

        $this->assertFalse($result['ok']);
        $this->assertSame('2026-12-04 07:00:00', $theirs->fresh()->scheduled_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_past_posts_cannot_be_dragged(): void
    {
        $this->makePost(CarbonImmutable::now('UTC')->subDay()->format('Y-m-d H:i:s'));

        $events = Livewire::test(Calendar::class)->instance()->calendarEvents(
            CarbonImmutable::now()->subDays(3)->format('Y-m-d\T00:00:00\Z'),
            CarbonImmutable::now()->addDays(3)->format('Y-m-d\T00:00:00\Z'),
        );

        $this->assertFalse($events[0]['editable']);
    }

    public function test_calendar_page_loads_vendored_script(): void
    {
        $this->assertFileExists(public_path('vendor/fullcalendar/6.1.15/index.global.min.js'));

        $this->get(Calendar::getUrl(['tenant' => $this->brand]))
            ->assertOk()
            ->assertSee('vendor/fullcalendar/6.1.15/index.global.min.js', escape: false)
            ->assertSee('Waiting for approval');
    }
}
