<?php

namespace App\Filament\Widgets;

use App\Domain\System\SystemHealth;
use App\Support\PublishGuard;
use Carbon\CarbonImmutable;
use Filament\Widgets\Widget;

class SystemStatusWidget extends Widget
{
    protected string $view = 'filament.widgets.system-status';

    protected static ?int $sort = -10;

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $now = CarbonImmutable::now();
        $health = SystemHealth::current();
        $mode = PublishGuard::effectiveMode((string) config('smm.publish_mode'), base_path());

        return [
            'isLive' => $mode === 'live',
            'timedJobs' => $health->describe($now),
            'timedJobsStale' => $health->schedulerIsStale($now),
            'dataDir' => app('smm.data_dir'),
        ];
    }
}
