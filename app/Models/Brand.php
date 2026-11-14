<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A business whose social media accounts the app manages (Moggies International first).
 * Everything brand-owned (accounts, posts, numbers) belongs to one brand and is shown only inside it.
 */
class Brand extends Model
{
    protected $fillable = ['name', 'slug', 'timezone'];

    protected $attributes = ['timezone' => 'Asia/Kolkata'];

    protected static function booted(): void
    {
        static::creating(function (Brand $brand): void {
            if (blank($brand->slug)) {
                $brand->slug = static::uniqueSlug($brand->name);
            }
        });
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'brand';
        $slug = $base;
        for ($i = 2; static::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    public function connectedAccounts(): HasMany
    {
        return $this->hasMany(ConnectedAccount::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }
}
