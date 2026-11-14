<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasTenants
{
    public const LOCAL_OWNER_EMAIL = 'owner@smm.local';

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The one owner on the laptop, created on first use. Never creates a second one.
     */
    public static function localOwner(): self
    {
        return static::firstOrCreate(
            ['email' => self::LOCAL_OWNER_EMAIL],
            ['name' => 'Owner', 'password' => Str::random(40)],
        );
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    public function brands(): BelongsToMany
    {
        return $this->belongsToMany(Brand::class)->withTimestamps();
    }

    public function getTenants(Panel $panel): Collection
    {
        return $this->brands;
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->brands()->whereKey($tenant)->exists();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
