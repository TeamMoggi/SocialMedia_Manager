<?php

namespace App\Filament\Resources\MediaItems;

use App\Domain\Library\Thumbnails;
use App\Domain\Posts\MediaRules;
use App\Enums\MediaKind;
use App\Enums\ProductRange;
use App\Filament\Resources\MediaItems\Pages\ListMediaItems;
use App\Models\Activity;
use App\Models\Brand;
use App\Models\MediaItem;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The Library: every photo, video, voice-over, music file and script in the brand's source folders
 * (Markting Social Media). The files stay where they are; here the owner sees them and edits their labels.
 */
class MediaItemResource extends Resource
{
    protected static ?string $model = MediaItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static ?string $navigationLabel = 'Library';

    protected static ?string $modelLabel = 'library file';

    protected static ?int $navigationSort = 20;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function fileUrl(MediaItem $item, string $what = 'file'): string
    {
        return route("filament.app.library.{$what}", ['tenant' => Filament::getTenant()?->slug, 'item' => $item->id]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('thumbnail')
                    ->label('')
                    ->state(fn (MediaItem $record): ?string => Thumbnails::possible($record) ? self::fileUrl($record, 'thumb') : null)
                    ->imageHeight(48),
                TextColumn::make('relative_path')
                    ->label('File')
                    ->formatStateUsing(fn (string $state): string => basename($state))
                    ->description(fn (MediaItem $record): string => $record->group?->name ?? '')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('kind')->label('Kind')->badge(),
                TextColumn::make('product_range')->label('Range')->placeholder('—'),
                IconColumn::make('ai_made')->label('AI-made')->boolean(),
                TextColumn::make('people')
                    ->label('Shows people')
                    ->state(fn (MediaItem $record): string => match ($record->shows_people) {
                        null => 'Not answered',
                        true => 'Yes',
                        false => 'No',
                    })
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Not answered' ? 'warning' : 'gray'),
                TextColumn::make('size')
                    ->label('Size')
                    ->state(fn (MediaItem $record): string => number_format($record->bytes / 1048576, 1).' MB'),
                TextColumn::make('shape')
                    ->label('Pixels / length')
                    ->state(fn (MediaItem $record): ?string => self::shape($record))
                    ->placeholder('—'),
                TextColumn::make('fits')
                    ->label('Fits')
                    ->state(fn (MediaItem $record): array => collect(MediaRules::badges($record))
                        ->map(fn (bool $ok, string $check): string => ($ok ? '✓ ' : '✗ ').$check)
                        ->values()
                        ->all())
                    ->badge()
                    ->color(fn (string $state): string => str_starts_with($state, '✓') ? 'success' : 'danger'),
                TextColumn::make('post_media_count')->label('Used in posts')->counts('postMedia'),
                TextColumn::make('description')->label('Description')->limit(50)->searchable()->placeholder('—')->toggleable(),
                TextColumn::make('missing_since')
                    ->label('')
                    ->state(fn (MediaItem $record): ?string => $record->isMissing() ? 'Missing' : null)
                    ->badge()
                    ->color('danger'),
            ])
            ->filters([
                SelectFilter::make('kind')->label('Kind')->options(MediaKind::class),
                SelectFilter::make('product_range')->label('Range')->options(ProductRange::class),
                TernaryFilter::make('ai_made')->label('AI-made'),
                Filter::make('people_unanswered')
                    ->label("AI-made, 'shows people' not answered")
                    ->query(fn (Builder $query): Builder => $query->where('ai_made', true)->whereNull('shows_people')),
                Filter::make('missing')
                    ->label('Only missing files')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('missing_since')),
            ])
            ->recordActions([
                Action::make('preview')
                    ->label('Preview')
                    ->icon(Heroicon::OutlinedEye)
                    ->visible(fn (MediaItem $record): bool => $record->kind->isAttachable() && ! $record->isMissing())
                    ->modalHeading(fn (MediaItem $record): string => $record->fileName())
                    ->modalContent(fn (MediaItem $record) => view('filament.library.preview', ['item' => $record, 'url' => self::fileUrl($record)]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
                Action::make('labels')
                    ->label('Edit labels')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->fillForm(fn (MediaItem $record): array => [
                        'kind' => $record->kind,
                        'product_range' => $record->product_range,
                        'ai_made' => $record->ai_made,
                        'shows_people' => $record->shows_people === null ? null : (int) $record->shows_people,
                        'description' => $record->description,
                    ])
                    ->schema([
                        Select::make('kind')->label('Kind')->options(MediaKind::class)->required(),
                        Select::make('product_range')->label('Range')->options(ProductRange::class)->placeholder('Not set'),
                        Toggle::make('ai_made')->label('AI-made'),
                        Select::make('shows_people')
                            ->label('Shows people (a face or a person)')
                            ->options([1 => 'Yes', 0 => 'No'])
                            ->placeholder('Not answered')
                            ->helperText('For AI-made files this decides the AI label on posts.'),
                        Textarea::make('description')->label('Description')->rows(3)->maxLength(1000),
                    ])
                    ->action(function (MediaItem $record, array $data): void {
                        self::saveLabels($record, $data);
                        Notification::make()->title('Labels saved')->success()->send();
                    }),
            ])
            ->defaultSort('mtime', 'desc')
            ->paginationPageOptions([25, 50, 100])
            ->emptyStateHeading('No files yet')
            ->emptyStateDescription('Press "Look for new files" to read the Markting Social Media folder. Nothing in it is changed.');
    }

    /** Saves the label fields only, and logs what changed. */
    public static function saveLabels(MediaItem $item, array $data, string $by = 'owner'): void
    {
        $people = $data['shows_people'] ?? null;
        $new = [
            'kind' => $data['kind'] instanceof MediaKind ? $data['kind'] : MediaKind::from((string) $data['kind']),
            'product_range' => blank($data['product_range'] ?? null) ? null
                : ($data['product_range'] instanceof ProductRange ? $data['product_range'] : ProductRange::from((string) $data['product_range'])),
            'ai_made' => (bool) ($data['ai_made'] ?? false),
            'shows_people' => $people === null || $people === '' ? null : (bool) (int) $people,
            'description' => blank($data['description'] ?? null) ? null : trim((string) $data['description']),
        ];
        $item->fill($new);
        $changes = $item->getDirty();
        if ($changes === []) {
            return;
        }
        $item->save();
        /** @var Brand $brand */
        $brand = $item->brand;
        Activity::record($brand, $item, 'labels changed', array_map(fn ($v) => $v instanceof BackedEnum ? $v->value : $v, $changes), $by);
    }

    private static function shape(MediaItem $item): ?string
    {
        $parts = [];
        if ($item->width && $item->height) {
            $parts[] = "{$item->width}×{$item->height}";
        }
        if ($item->duration !== null) {
            $parts[] = sprintf('%d:%02d', intdiv((int) round($item->duration), 60), (int) round($item->duration) % 60);
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMediaItems::route('/'),
        ];
    }
}
