<?php

namespace Tests\Feature;

use App\Filament\Widgets\SystemStatusWidget;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SystemStatusWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_widget_shows_dry_run_badge(): void
    {
        config(['smm.publish_mode' => 'dry-run']);

        Livewire::test(SystemStatusWidget::class)
            ->assertSee('Dry run: nothing is posted')
            ->assertSee('Timed jobs have not run yet')
            ->assertSee(app('smm.data_dir'));
    }

    public function test_widget_shows_when_timed_jobs_last_ran(): void
    {
        $this->travelTo(now()->startOfMinute());
        Setting::put('heartbeat_at', now()->subMinute()->utc()->toIso8601String());

        Livewire::test(SystemStatusWidget::class)
            ->assertSee('Timed jobs ran 1 minute ago')
            ->assertDontSee('have not run');
    }
}
