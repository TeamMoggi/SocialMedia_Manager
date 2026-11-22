<?php

namespace App\Filament\Pages;

use App\Domain\Meta\MetaGraph;
use App\Domain\Meta\Rehearsal;
use App\Domain\Relay\RelayFolder;
use App\Enums\MediaKind;
use App\Models\Brand;
use App\Models\MediaItem;
use App\Models\Setting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Test send: one photo and one video from the Library go through every step to Instagram and Facebook,
 * stopping just before publishing. Allowed in Dev and App: nothing is published.
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
                        ->options(fn (): array => self::libraryChoices($this->brand(), 'image'))
                        ->searchable()
                        ->required(),
                    Select::make('video')
                        ->label('Video (.mp4 or .mov)')
                        ->options(fn (): array => self::libraryChoices($this->brand(), 'video'))
                        ->searchable()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $brand = $this->brand();
                    $photo = $this->pick($brand, $data['photo']);
                    $video = $this->pick($brand, $data['video']);
                    if ($photo === null || $video === null) {
                        Notification::make()->title('Pick the files again: one of them is no longer there.')->warning()->send();

                        return;
                    }
                    // No time limit: on Windows it counts waiting too, and a stop in the middle would skip the clean-up.
                    set_time_limit(0);
                    $result = (new Rehearsal(app(MetaGraph::class), app(RelayFolder::class)))
                        ->run($brand, $photo->fullPath(), $video->fullPath());
                    Notification::make()
                        ->title($result['ok'] ? 'Test send passed: nothing was published' : 'Test send failed: see the steps below')
                        ->status($result['ok'] ? 'success' : 'danger')
                        ->send();
                }),
        ];
    }

    /**
     * Library files a test send can use: JPEG photos, or MP4/MOV videos, still in the folder.
     *
     * @return array<int, string> item id => "<folder name> / <file name>"
     */
    public static function libraryChoices(Brand $brand, string $type): array
    {
        $kinds = $type === 'image' ? [MediaKind::Photo, MediaKind::AiImage] : [MediaKind::FinishedVideo, MediaKind::Clip];
        $extensions = $type === 'image' ? ['jpg', 'jpeg'] : ['mp4', 'mov'];

        return MediaItem::query()
            ->with('group')
            ->where('brand_id', $brand->id)
            ->attachable()
            ->whereIn('kind', $kinds)
            ->get()
            ->filter(fn (MediaItem $item): bool => in_array(strtolower(pathinfo($item->relative_path, PATHINFO_EXTENSION)), $extensions, true))
            ->mapWithKeys(fn (MediaItem $item): array => [$item->id => ($item->group?->name ?? '').' / '.$item->fileName()])
            ->sort()
            ->all();
    }

    private function pick(Brand $brand, mixed $id): ?MediaItem
    {
        $item = MediaItem::query()->where('brand_id', $brand->id)->attachable()->find($id);

        return $item !== null && is_file($item->fullPath()) ? $item : null;
    }

    private function brand(): Brand
    {
        /** @var Brand $brand */
        $brand = Filament::getTenant();

        return $brand;
    }

    protected function getViewData(): array
    {
        return [
            'last' => Setting::get('rehearsal.last'),
            'source' => (string) config('smm.library_source'),
            'zone' => Filament::getTenant()?->timezone ?? (string) config('smm.display_timezone'),
        ];
    }
}
