<?php

namespace App\Models;

use App\Enums\Network;
use App\Enums\PostType;
use App\Enums\TargetState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A post's version on one network: its type, its own caption (empty = the main caption), and, for Facebook, a link. */
class PostTarget extends Model
{
    protected $fillable = [
        'post_id', 'network', 'connected_account_id', 'type', 'caption', 'link', 'state', 'external_id',
        'container_id', 'permalink', 'attempts', 'last_error', 'published_at',
        'next_attempt_at', 'late_days', 'work',
    ];

    protected $attributes = ['state' => 'waiting', 'attempts' => 0];

    protected function casts(): array
    {
        return [
            'network' => Network::class,
            'type' => PostType::class,
            'state' => TargetState::class,
            'published_at' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime',
            'work' => 'array',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function connectedAccount(): BelongsTo
    {
        return $this->belongsTo(ConnectedAccount::class);
    }
}
