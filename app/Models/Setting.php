<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Small app-wide values (when timed jobs last ran, the last backup, the backup password).
 * Values are stored as JSON; secrets are encrypted with the app key.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value'];

    public static function get(string $key, mixed $default = null): mixed
    {
        $row = static::query()->find($key);

        return $row === null || $row->value === null ? $default : json_decode($row->value, true);
    }

    public static function put(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => json_encode($value)]);
    }

    public static function getSecret(string $key): ?string
    {
        $row = static::query()->find($key);

        return $row === null || $row->value === null ? null : Crypt::decryptString($row->value);
    }

    public static function putSecret(string $key, string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => Crypt::encryptString($value)]);
    }
}
