<?php

namespace App\Filament\Pages;

use App\Domain\Posts\ApprovalRefused;
use App\Domain\Publishing\Publisher;
use App\Enums\TargetState;
use App\Models\Brand;
use App\Models\PostTarget;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Posts that were not sent: late (the app will try again on the next days), failed, or missed (the days ran out).
 * Nothing here is sent late without the owner: "Post now" and "New time" are the owner's choices.
 */
class MissedPosts extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.missed-posts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $navigationLabel = 'Missed posts';

    protected static ?string $title = 'Missed posts';

    protected static ?int $navigationSort = 12;

    public static function getNavigationBadge(): ?string
    {
        $count = self::targets()->whereIn('state', [TargetState::Missed, TargetState::Late])->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function table(Table $table): Table
    {
        $zone = fn (): string => $this->brand()->timezone ?: (string) config('smm.display_timezone');

        return $table
            ->query(fn (): Builder => self::targets()->whereIn('state', [TargetState::Missed, TargetState::Late, TargetState::Failed])->with('post'))
            ->columns([
                TextColumn::make('post.scheduled_at')->label('Planned time')->dateTime('D j M Y, H:i')->timezone($zone)->sortable(),
                TextColumn::make('network')->label('Network')->badge(),
                TextColumn::make('post.caption')->label('Caption')->limit(60)->wrap(),
                TextColumn::make('state')->label('State')->badge(),
                TextColumn::make('last_error')->label('What went wrong')->wrap()->placeholder('Not sent in time'),
            ])
            ->recordActions([
                Action::make('post_now')
                    ->label('Post now')
                    ->icon(Heroicon::OutlinedPaperAirplane)
                    ->requiresConfirmation()
                    ->modalDescription('The app sends it at its next check (within a minute while the app is open).')
                    ->action(function (PostTarget $record): void {
                        try {
                            app(Publisher::class)->postNow($record, $this->who());
                            Notification::make()->title('It will be sent now')->success()->send();
                        } catch (ApprovalRefused $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('new_time')
                    ->label('New time')
                    ->icon(Heroicon::OutlinedClock)
                    ->schema([
                        DateTimePicker::make('time')->label('New time')->seconds(false)->timezone($zone)->required(),
                    ])
                    ->action(function (PostTarget $record, array $data): void {
                        try {
                            // The picker shows the brand's time and hands back the moment in the app's own (UTC) time.
                            $utc = CarbonImmutable::parse((string) $data['time'], (string) config('app.timezone'))->utc();
                            app(Publisher::class)->newTime($record, $utc, $this->who());
                            Notification::make()->title('New time saved')->success()->send();
                        } catch (ApprovalRefused $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        } catch (Throwable) {
                            Notification::make()->title('That time could not be read; try again.')->danger()->send();
                        }
                    }),
            ])
            ->defaultSort('post.scheduled_at')
            ->emptyStateHeading('Nothing is waiting')
            ->emptyStateDescription('Posts that could not be sent on time appear here, with what went wrong.');
    }

    /** @return Builder<PostTarget> this brand's versions only */
    private static function targets(): Builder
    {
        $brand = Filament::getTenant();

        return PostTarget::query()->whereHas('post', fn (Builder $q) => $q->where('brand_id', $brand?->getKey()));
    }

    private function brand(): Brand
    {
        /** @var Brand $brand */
        $brand = Filament::getTenant();

        return $brand;
    }

    private function who(): string
    {
        return auth()->user()?->name ?? 'owner';
    }
}
