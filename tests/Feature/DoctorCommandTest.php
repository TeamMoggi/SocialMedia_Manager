<?php

namespace Tests\Feature;

use App\Enums\Network;
use App\Enums\PostState;
use App\Enums\PostType;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DoctorCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_doctor_passes_in_test_environment(): void
    {
        $this->artisan('smm:doctor')
            ->expectsOutputToContain('PHP 8.')
            ->expectsOutputToContain('Data folder')
            ->expectsOutputToContain('Publish mode: dry-run')
            ->doesntExpectOutputToContain('FAIL')
            ->assertExitCode(0);
    }

    public function test_doctor_reports_library_folder(): void
    {
        $brand = Brand::create(['name' => 'Moggies Test']);
        $brand->update(['library_sources' => [sys_get_temp_dir()]]);

        $this->artisan('smm:doctor')
            ->expectsOutputToContain('OK    Library folder found: '.sys_get_temp_dir())
            ->assertExitCode(0);

        $brand->update(['library_sources' => ['Z:\\No such folder']]);

        $this->artisan('smm:doctor')
            ->expectsOutputToContain('WARN  Library folder not found: Z:\\No such folder')
            ->assertExitCode(0);
    }

    public function test_doctor_fails_when_a_database_update_is_waiting(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 1])->assertExitCode(0);

        // One expected phrase per printed line: Laravel's matcher uses up one phrase per line.
        $this->artisan('smm:doctor')
            ->expectsOutputToContain('FAIL  1 database update(s) waiting: run php artisan migrate')
            ->assertExitCode(1);
    }

    private function waitingPost(): void
    {
        $brand = Brand::create(['name' => 'Moggies Test']);
        $post = Post::create(['brand_id' => $brand->id, 'caption' => 'x', 'state' => PostState::Approved, 'scheduled_at' => now()->addDay()]);
        PostTarget::create(['post_id' => $post->id, 'network' => Network::Instagram, 'type' => PostType::InstagramPhoto]);
    }

    public function test_doctor_warns_when_a_post_is_waiting_and_wake_timers_are_off_while_plugged_in(): void
    {
        $this->waitingPost();
        app()->bind('smm.powercfg', fn () => fn () => "Current AC Power Setting Index: 0x00000000\nCurrent DC Power Setting Index: 0x00000000\n");

        $this->artisan('smm:doctor')
            ->expectsOutputToContain('WARN  Wake timers are off while plugged in: the laptop will not wake for posts')
            ->assertExitCode(0);
    }

    public function test_doctor_is_quiet_with_nothing_waiting_and_happy_when_wake_timers_are_on(): void
    {
        app()->bind('smm.powercfg', fn () => fn () => "Current AC Power Setting Index: 0x00000000\n");
        $this->artisan('smm:doctor')->doesntExpectOutputToContain('Wake timers')->assertExitCode(0);

        $this->waitingPost();
        app()->bind('smm.powercfg', fn () => fn () => "Current AC Power Setting Index: 0x00000001\n");
        $this->artisan('smm:doctor')->expectsOutputToContain('OK    Wake timers are on while plugged in')->assertExitCode(0);
    }
}
