<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One folder of a source folder; its name can be changed to something meaningful ("5" -> "Customer video, June 2026"). */
class MediaGroup extends Model
{
    protected $fillable = ['brand_id', 'source_root', 'folder', 'name'];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(MediaItem::class);
    }
}
