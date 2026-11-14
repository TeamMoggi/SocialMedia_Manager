<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\Network;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One social media account a brand has connected (a Facebook Page, an Instagram account, a LinkedIn
 * company page). The access tokens can post as the business, so they are encrypted with the app key
 * and never appear in arrays, JSON or logs.
 */
class ConnectedAccount extends Model
{
    protected $fillable = [
        'brand_id', 'network', 'external_id', 'name', 'access_token', 'refresh_token',
        'token_expires_at', 'status', 'last_error', 'meta',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected $attributes = ['status' => 'connected'];

    protected function casts(): array
    {
        return [
            'network' => Network::class,
            'status' => AccountStatus::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'immutable_datetime',
            'meta' => 'array',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
