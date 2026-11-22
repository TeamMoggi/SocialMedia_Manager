<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** One line of the activity log: who (the owner, Claude or the app) did what to which post or file, and when. */
class Activity extends Model
{
    protected $table = 'activity_log';

    public const UPDATED_AT = null;

    protected $fillable = ['brand_id', 'subject_type', 'subject_id', 'action', 'details', 'by'];

    protected function casts(): array
    {
        return ['details' => 'array'];
    }

    public static function record(Brand $brand, ?Model $subject, string $action, array $details = [], string $by = 'owner'): self
    {
        return static::create([
            'brand_id' => $brand->id,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'action' => $action,
            'details' => $details,
            'by' => $by,
        ]);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
