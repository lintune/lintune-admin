<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RealmConfig extends Model
{
    protected $table    = 'realm_config';
    protected $fillable = ['realm', 'key', 'value', 'encrypted'];

    public static function get(string $realm, string $key, mixed $default = null): mixed
    {
        $record = static::where('realm', $realm)->where('key', $key)->first();
        if (!$record) return $default;

        return $record->encrypted
            ? decrypt($record->value)
            : $record->value;
    }

    public static function set(string $realm, string $key, mixed $value, bool $encrypted = false): void
    {
        static::updateOrCreate(
            ['realm' => $realm, 'key' => $key],
            ['value' => $encrypted ? encrypt($value) : $value, 'encrypted' => $encrypted]
        );
    }
}
