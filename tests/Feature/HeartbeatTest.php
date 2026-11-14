<?php

namespace Tests\Feature;

use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class HeartbeatTest extends TestCase
{
    use RefreshDatabase;

    public function test_heartbeat_records_now(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 10:15:00', 'UTC'));

        $this->artisan('smm:heartbeat')->assertExitCode(0);

        $this->assertSame('2026-11-14T10:15:00+00:00', Setting::get('heartbeat_at'));
    }

    public function test_heartbeat_is_not_scheduled_every_minute(): void
    {
        // Read the schedule itself: schedule:list output carries colour codes between the fields.
        $events = array_values(array_filter(
            app(Schedule::class)->events(),
            fn ($event) => str_contains((string) $event->command, 'smm:heartbeat'),
        ));

        // The heartbeat now comes from the once-a-day jobs (smm:daily), not a job every minute.
        $this->assertCount(0, $events);
    }

    public function test_doctor_warns_when_timed_jobs_have_not_run(): void
    {
        $this->artisan('smm:doctor')
            ->expectsOutputToContain('WARN  Daily jobs have not run')
            ->assertExitCode(0);
    }
}
