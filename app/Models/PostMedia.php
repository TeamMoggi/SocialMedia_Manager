<?php

namespace App\Models;

use App\Domain\Posts\PostMediaCopier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One photo or video of a post, in order. Attaching a Library file copies it into the post's own folder, so a
 * later change, move or rename in Markting Social Media can't change what is posted.
 */
class PostMedia extends Model
{
    protected $table = 'post_media';

    protected $fillable = [
        'post_id', 'media_item_id', 'position', 'path', 'mime', 'bytes', 'width', 'height', 'duration', 'codec',
        'fps', 'faststart', 'hash',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration' => 'float',
            'fps' => 'float',
            'faststart' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (PostMedia $media): void {
            if ($media->isDirty('media_item_id') || blank($media->path)) {
                PostMediaCopier::copy($media);
            }
        });
        static::deleted(fn (PostMedia $media) => PostMediaCopier::remove($media));
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }

    public function fullPath(): string
    {
        return app('smm.data_dir').DIRECTORY_SEPARATOR.'media'.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, (string) $this->path);
    }

    public function name(): string
    {
        return $this->mediaItem?->fileName() ?? basename((string) $this->path);
    }

    /** This copy's facts in the shape MediaRules::check takes. */
    public function toRuleFile(): array
    {
        return [
            'name' => $this->name(), 'mime' => $this->mime, 'bytes' => (int) $this->bytes, 'width' => $this->width,
            'height' => $this->height, 'duration' => $this->duration, 'codec' => $this->codec, 'fps' => $this->fps,
            'faststart' => $this->faststart, 'exists' => filled($this->path) && is_file($this->fullPath()),
            'isVideo' => str_starts_with((string) $this->mime, 'video/'),
        ];
    }
}
