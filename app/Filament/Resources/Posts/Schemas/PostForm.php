<?php

namespace App\Filament\Resources\Posts\Schemas;

use App\Domain\Posts\CaptionStats;
use App\Enums\AccountStatus;
use App\Enums\Network;
use App\Enums\PostType;
use App\Models\Brand;
use App\Models\ConnectedAccount;
use App\Models\MediaItem;
use App\Models\Post;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

/**
 * Writing a post: one main caption, the photos and videos in order, a time, and a version for Facebook and
 * for Instagram. The network versions are saved as post targets (saveTargets), not as post columns.
 */
final class PostForm
{
    /** Form fields that belong to the network versions, not to the post itself. */
    public const TARGET_FIELDS = ['fb_on', 'fb_type', 'fb_caption', 'fb_link', 'ig_on', 'ig_type', 'ig_caption'];

    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Textarea::make('caption')
                ->label('Main caption')
                ->rows(8)
                ->live(debounce: 500)
                ->hint(fn (?string $state): string => CaptionStats::hint($state))
                ->helperText('Used on every network unless a version below has its own text.'),
            DateTimePicker::make('scheduled_at')
                ->label('Time')
                ->timezone(fn (): string => self::zone())
                ->seconds(false)
                ->helperText(fn (): string => 'In '.self::zone().' time.'),
            Repeater::make('media')
                ->label('Photos and videos, in order')
                ->relationship('media')
                ->orderColumn('position')
                ->reorderable()
                ->defaultItems(0)
                ->addActionLabel('Add a photo or video')
                ->schema([
                    Select::make('media_item_id')
                        ->label('From the Library')
                        ->searchable()
                        ->required()
                        ->getSearchResultsUsing(fn (string $search): array => self::searchLibrary($search))
                        ->getOptionLabelUsing(fn ($value): ?string => ($item = MediaItem::find($value)) ? self::label($item) : null)
                        ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            $item = MediaItem::query()->where('brand_id', self::brand()->id)->find($value);
                            if ($item === null || $item->isMissing() || ! is_file($item->fullPath())) {
                                $fail('This file is no longer in its folder; press "Look for new files" in the Library, then pick it again.');
                            } elseif (! $item->kind->isAttachable()) {
                                $fail('Only photos and videos can go in a post.');
                            }
                        }),
                ]),
            Section::make('Facebook')
                ->schema([
                    Toggle::make('fb_on')->label('Post on the Facebook Page')->live(),
                    Select::make('fb_type')
                        ->label('Kind of post')
                        ->options(PostType::forNetwork(Network::FacebookPage))
                        ->live()
                        ->visible(fn (Get $get): bool => (bool) $get('fb_on'))
                        ->required(fn (Get $get): bool => (bool) $get('fb_on')),
                    Textarea::make('fb_caption')
                        ->label('Facebook text')
                        ->placeholder('Leave empty to use the main caption')
                        ->rows(4)
                        ->visible(fn (Get $get): bool => (bool) $get('fb_on')),
                    TextInput::make('fb_link')
                        ->label('Link')
                        ->url()
                        ->placeholder('https://')
                        ->visible(fn (Get $get): bool => $get('fb_on') && $get('fb_type') === PostType::FacebookLink->value),
                ]),
            Section::make('Instagram')
                ->schema([
                    Toggle::make('ig_on')->label('Post on Instagram')->live(),
                    Select::make('ig_type')
                        ->label('Kind of post')
                        ->options(PostType::forNetwork(Network::Instagram))
                        ->live()
                        ->visible(fn (Get $get): bool => (bool) $get('ig_on'))
                        ->required(fn (Get $get): bool => (bool) $get('ig_on')),
                    Textarea::make('ig_caption')
                        ->label('Instagram text')
                        ->placeholder('Leave empty to use the main caption')
                        ->rows(4)
                        ->hint(fn (?string $state): ?string => filled($state) ? CaptionStats::hint($state) : null)
                        ->live(debounce: 500)
                        ->visible(fn (Get $get): bool => (bool) $get('ig_on')),
                ]),
            View::make('filament.posts.preview')
                ->visible(fn (?Post $record): bool => $record !== null),
            View::make('filament.posts.history')
                ->visible(fn (?Post $record): bool => $record !== null),
        ]);
    }

    /** @return array{0: array, 1: array} the post's own data, and the network versions' fields */
    public static function splitTargets(array $data): array
    {
        $targets = array_intersect_key($data, array_flip(self::TARGET_FIELDS));

        return [array_diff_key($data, $targets), $targets];
    }

    public static function fillTargets(Post $post): array
    {
        $facebook = $post->targets()->where('network', Network::FacebookPage)->first();
        $instagram = $post->targets()->where('network', Network::Instagram)->first();

        return [
            'fb_on' => $facebook !== null,
            'fb_type' => $facebook?->type?->value,
            'fb_caption' => $facebook?->caption,
            'fb_link' => $facebook?->link,
            'ig_on' => $instagram !== null,
            'ig_type' => $instagram?->type?->value,
            'ig_caption' => $instagram?->caption,
        ];
    }

    public static function saveTargets(Post $post, array $data): void
    {
        $versions = [
            [Network::FacebookPage, 'fb', true],
            [Network::Instagram, 'ig', false],
        ];
        foreach ($versions as [$network, $key, $hasLink]) {
            if (empty($data["{$key}_on"]) || blank($data["{$key}_type"] ?? null)) {
                $post->targets()->where('network', $network)->delete();

                continue;
            }
            $account = ConnectedAccount::query()
                ->where('brand_id', $post->brand_id)
                ->where('network', $network)
                ->whereIn('status', [AccountStatus::Connected, AccountStatus::Expiring])
                ->first();
            $post->targets()->updateOrCreate(['network' => $network], [
                'type' => $data["{$key}_type"],
                'caption' => blank($data["{$key}_caption"] ?? null) ? null : trim((string) $data["{$key}_caption"]),
                'link' => $hasLink && $data["{$key}_type"] === PostType::FacebookLink->value && filled($data['fb_link'] ?? null)
                    ? trim((string) $data['fb_link']) : null,
                'connected_account_id' => $account?->id,
            ]);
        }
        $post->unsetRelation('targets');
    }

    /** @return array<int, string> */
    public static function searchLibrary(string $search): array
    {
        $words = array_filter(preg_split('/\s+/', trim($search)) ?: []);
        $query = MediaItem::query()->with('group')->where('brand_id', self::brand()->id)->attachable();
        foreach ($words as $word) {
            $query->where(fn ($q) => $q->where('relative_path', 'like', "%{$word}%")->orWhere('description', 'like', "%{$word}%"));
        }

        return $query->orderByDesc('mtime')->limit(50)->get()
            ->mapWithKeys(fn (MediaItem $item): array => [$item->id => self::label($item)])
            ->all();
    }

    public static function label(MediaItem $item): string
    {
        $facts = $item->kind->isVideo() && $item->duration !== null
            ? sprintf('%d:%02d', intdiv((int) round($item->duration), 60), (int) round($item->duration) % 60)
            : ($item->width ? "{$item->width}×{$item->height}" : '');

        return trim(($item->group?->name ?? '').' / '.$item->fileName().' ('.$item->kind->label().($facts !== '' ? ', '.$facts : '').')');
    }

    private static function brand(): Brand
    {
        /** @var Brand $brand */
        $brand = Filament::getTenant();

        return $brand;
    }

    private static function zone(): string
    {
        return Filament::getTenant()?->timezone ?: (string) config('smm.display_timezone');
    }
}
