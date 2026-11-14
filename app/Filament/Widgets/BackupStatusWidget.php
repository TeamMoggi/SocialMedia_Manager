<?php

namespace App\Filament\Widgets;

use App\Domain\Backup\BackupStatus;
use App\Filament\Pages\Backups;
use Carbon\CarbonImmutable;
use Filament\Widgets\Widget;

class BackupStatusWidget extends Widget
{
    protected string $view = 'filament.widgets.backup-status';

    protected static ?int $sort = -9;

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $now = CarbonImmutable::now();

        return [
            'warnings' => BackupStatus::current($now)->warnings($now),
            'backupsUrl' => Backups::getUrl(),
        ];
    }
}
