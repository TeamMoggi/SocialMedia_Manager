<?php

namespace App\Filament\Pages;

use App\Domain\Library\SourceFiles;
use App\Domain\Meta\MetaGraph;
use App\Domain\Meta\Rehearsal;
use App\Domain\Relay\RelayFolder;
use App\Models\Brand;
use App\Models\Setting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Test send: one photo and one video from the Markting Social Media folder go through every step to
 * Instagram and Facebook, stopping just before publishing. Allowed in Dev and App: nothing is published.
 */
class TestSend extends Page
{
    protected string $view = 'filament.pages.test-send';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static ?string $navigationLabel = 'Test send';

    protected static ?string $title = 'Test send';

    protected static ?int $navigationSort = 82;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('run')
                ->label('Run test send')
                ->icon(Heroicon::OutlinedPlay)
                ->modalHeading('Run test send')
                ->modalDescription('Nothing is published. It takes 1 to 3 minutes; keep this page open until it finishes.')
                ->modalSubmitActionLabel('Run test send')
                ->schema([
                    Select::make('photo')
                        ->label('Photo (.jpg)')
                        ->options(fn (): array => $this->choices(['jpg', 'jpeg']))
                        ->searchable()
                        ->required(),
                    Select::make('video')
                        ->label('Video (.mp4 or .mov)')
                        ->options(fn (): array => $this->choices(['mp4', 'mov']))
                        ->searchable()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $files = SourceFiles::list($this->source());
                    if (! isset($files[$data['photo']], $files[$data['video']])) {
                        Notification::make()->title('Pick the files again: one of them is no longer there.')->warning()->send();

                        return;
                    }
                    // No time limit: on Windows it counts waiting too, and a stop in the middle would skip the clean-up.
                    set_time_limit(0);
                    /** @var Brand $brand */
                    $brand = Filament::getTenant();
                    $result = (new Rehearsal(app(MetaGraph::class), app(RelayFolder::class)))
                        ->run($brand, $files[$data['photo']], $files[$data['video']]);
                    Notification::make()
                        ->title($result['ok'] ? 'Test send passed: nothing was published' : 'Test send failed: see the steps below')
                        ->status($result['ok'] ? 'success' : 'danger')
                        ->send();
                }),
        ];
    }

    private function source(): string
    {
        return (string) config('smm.library_source');
    }

    /** @return array<string, string> relative path => relative path */
    private function choices(array $extensions): array
    {
        $keys = array_keys(SourceFiles::list($this->source(), $extensions));

        return array_combine($keys, $keys) ?: [];
    }

    protected function getViewData(): array
    {
        return [
            'last' => Setting::get('rehearsal.last'),
            'source' => $this->source(),
            'zone' => Filament::getTenant()?->timezone ?? (string) config('smm.display_timezone'),
        ];
    }
}
