<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Filament\Resources\Posts\PostResource;
use App\Filament\Resources\Posts\Schemas\PostForm;
use App\Models\Activity;
use App\Models\Post;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreatePost extends CreateRecord
{
    protected static string $resource = PostResource::class;

    /** Creating the post, its versions and its copied photos and videos is one database transaction. */
    protected ?bool $hasDatabaseTransactions = true;

    /** The Facebook and Instagram versions from the form, saved after the post exists. */
    private array $targetData = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        [$data, $this->targetData] = PostForm::splitTargets($data);
        // The post belongs to the brand being worked in.
        $data['brand_id'] = Filament::getTenant()?->getKey();

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var Post $post */
        $post = $this->getRecord();
        PostForm::saveTargets($post, $this->targetData);
        Activity::record($post->brand, $post, 'post created', [], auth()->user()?->name ?? 'owner');
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
