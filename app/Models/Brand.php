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
    protected $fillable = ['name', 'slug', 'timezone', 'library_sources', 'approval_checklist'];

    protected $attributes = ['timezone' => 'Asia/Kolkata'];

    protected function casts(): array
    {
        return ['library_sources' => 'array', 'approval_checklist' => 'array'];
    }

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

    /** @return list<string> The folders the library reads (never changes); Markting Social Media unless set. */
    public function librarySources(): array
    {
        $sources = array_values(array_filter((array) $this->library_sources, fn ($p) => is_string($p) && trim($p) !== ''));

        return $sources ?: [(string) config('smm.library_source')];
    }

    /** @return list<string> The brand rules every post is checked against before approval. */
    public function approvalChecklist(): array
    {
        $items = array_values(array_filter((array) $this->approval_checklist, fn ($i) => is_string($i) && trim($i) !== ''));

        return $items ?: array_values((array) config('smm.approval_checklist'));
    }

    public function mediaGroups(): HasMany
    {
        return $this->hasMany(MediaGroup::class);
    }

    public function mediaItems(): HasMany
    {
        return $this->hasMany(MediaItem::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
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
