<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/** Simple key/value app settings (branding etc.), cached for a day. */
class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value'];

    public static function get(string $key, $default = null)
    {
        return static::all_()[$key] ?? $default;
    }

    public static function put(string $key, $value): void
    {
        $value === null || $value === ''
            ? static::whereKey($key)->delete()
            : static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget('app-settings');
    }

    protected static function all_(): array
    {
        try {
            return Cache::remember('app-settings', 86400, fn () => Schema::hasTable('settings') ? static::pluck('value', 'key')->all() : []);
        } catch (\Throwable) {
            return [];
        }
    }
}
