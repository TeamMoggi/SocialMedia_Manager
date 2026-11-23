<?php

namespace App\Filament\Resources\MediaItems\Pages;

use App\Domain\Library\LibraryScanner;
use App\Filament\Resources\MediaItems\MediaItemResource;
use App\Models\Brand;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListMediaItems extends ListRecords
{
    protected static string $resource = MediaItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('scan')
                ->label('Look for new files')
                ->icon(Heroicon::OutlinedArrowPath)
                ->action(function (): void {
                    // The first look at a big folder can take a minute; a stop in the middle would just be redone.
                    set_time_limit(0);
                    /** @var Brand $brand */
                    $brand = Filament::getTenant();
                    $report = app(LibraryScanner::class)->scan($brand);
                    Notification::make()
                        ->title($report->summary())
                        ->status($report->unreachable === [] ? 'success' : 'warning')
                        ->send();
                }),
        ];
    }
}
