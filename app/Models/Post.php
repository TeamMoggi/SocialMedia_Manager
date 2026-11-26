<?php

namespace App\Models;

use App\Domain\Publishing\TargetCleanup;
use App\Enums\Network;
use App\Enums\PostState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\File;

/**
 * A post: one main caption, a version per network (targets), its photos and videos in order (media), and a
 * time (stored in UTC, shown in the brand's time zone). Nothing is sent without the owner's approval.
 */
class Post extends Model
{
    protected $fillable = ['brand_id', 'caption', 'state', 'scheduled_at', 'approved_at', 'approved_by', 'checklist', 'approved_fingerprint'];

    protected $attributes = ['state' => 'draft'];

    protected function casts(): array
    {
        return [
            'state' => PostState::class,
            'scheduled_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'checklist' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // The copies of the post's photos and videos go with it; the files in the Library's folders stay.
        static::deleting(function (Post $post): void {
            // Facebook's scheduled copy and the Hostinger files go too (published versions are left alone).
            $cleanup = app(TargetCleanup::class);
            foreach ($post->targets()->get() as $target) {
                $cleanup->release($target->setRelation('post', $post));
            }
            $post->media()->get()->each->delete();
            File::deleteDirectory($post->mediaDir());
        });
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(PostTarget::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(PostMedia::class)->orderBy('position');
    }

    public function target(Network $network): ?PostTarget
    {
        return $this->targets->first(fn (PostTarget $target): bool => $target->network === $network);
    }

    public function mediaDir(): string
    {
        return app('smm.data_dir').DIRECTORY_SEPARATOR.'media'.DIRECTORY_SEPARATOR.'posts'.DIRECTORY_SEPARATOR.$this->id;
    }
}
