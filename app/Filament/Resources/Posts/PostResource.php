<?php

namespace App\Filament\Resources\Posts;

use App\Domain\Posts\Approval;
use App\Domain\Posts\MediaRules;
use App\Enums\Network;
use App\Enums\PostState;
use App\Filament\Resources\Posts\Pages\CreatePost;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Filament\Resources\Posts\Schemas\PostForm;
use App\Models\Post;
use App\Models\PostTarget;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Posts: write once with a version per network, check against Meta's rules, and approve against the brand rules. */
class PostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static ?string $navigationLabel = 'Posts';

    protected static ?string $modelLabel = 'post';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return PostForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('scheduled_at')
                    ->label('Time')
                    ->dateTime('D j M Y, H:i')
                    ->timezone(fn (): string => Filament::getTenant()?->timezone ?: (string) config('smm.display_timezone'))
                    ->placeholder('No time yet')
                    ->sortable(),
                TextColumn::make('caption')->label('Caption')->limit(60)->placeholder('No caption yet')->wrap(),
                TextColumn::make('networks')
                    ->label('Networks')
                    ->state(fn (Post $record): array => $record->targets
                        ->map(fn (PostTarget $t): string => $t->network === Network::FacebookPage ? 'Facebook' : 'Instagram')
                        ->all())
                    ->badge(),
                TextColumn::make('state')->label('State')->badge(),
                TextColumn::make('red_checks')
                    ->label('Red checks')
                    ->state(fn (Post $record): int => count(array_filter(app(Approval::class)->problems($record), fn ($p) => $p->blocks())))
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'success'),
            ])
            ->filters([
                SelectFilter::make('state')->label('State')->options(PostState::class),
            ])
            ->recordActions([EditAction::make()->label('Open')])
            ->defaultSort('scheduled_at')
            ->emptyStateHeading('No posts yet')
            ->emptyStateDescription('Press "New post" to write one. Nothing is sent before you approve it.');
    }

    public static function hasBlocks(Post $post): bool
    {
        return MediaRules::hasBlock(app(Approval::class)->problems($post));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPosts::route('/'),
            'create' => CreatePost::route('/create'),
            'edit' => EditPost::route('/{record}/edit'),
        ];
    }
}
