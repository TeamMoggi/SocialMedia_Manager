<?php

namespace App\Models;

use App\Enums\MediaKind;
use App\Enums\ProductRange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file found in a source folder (Markting Social Media), with its facts and labels. The app only reads
 * the file where it is; a file attached to a post is copied into that post's own folder.
 */
class MediaItem extends Model
{
    protected $fillable = [
        'brand_id', 'media_group_id', 'source_root', 'relative_path', 'kind', 'product_range', 'mime', 'bytes',
        'width', 'height', 'duration', 'codec', 'fps', 'faststart', 'mtime', 'hash', 'ai_made', 'shows_people',
        'description', 'facts_error', 'missing_since',
    ];

    protected function casts(): array
    {
        return [
            'kind' => MediaKind::class,
            'product_range' => ProductRange::class,
            'ai_made' => 'boolean',
            'shows_people' => 'boolean',
            'faststart' => 'boolean',
            'bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration' => 'float',
            'fps' => 'float',
            'mtime' => 'integer',
            'missing_since' => 'immutable_datetime',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(MediaGroup::class, 'media_group_id');
    }

    public function fullPath(): string
    {
        return rtrim($this->source_root, '\/').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $this->relative_path);
    }

    public function fileName(): string
    {
        return basename($this->relative_path);
    }

    public function isMissing(): bool
    {
        return $this->missing_since !== null;
    }

    /** Photos and videos that can go in a post and are still in the folder. */
    public function scopeAttachable(Builder $query): Builder
    {
        return $query->whereIn('kind', MediaKind::attachableValues())->whereNull('missing_since');
    }
}
