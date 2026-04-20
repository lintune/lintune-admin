<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $primaryKey = 'key';
    protected $keyType    = 'string';
    public $incrementing  = false;

    protected $fillable = ['key', 'value', 'encrypted'];

    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::find($key);
        if (!$setting) return $default;

        return $setting->encrypted
            ? decrypt($setting->value)
            : $setting->value;
    }

    public static function set(string $key, mixed $value, bool $encrypted = false): void
    {
        static::updateOrCreate(['key' => $key], [
            'value'     => $encrypted ? encrypt($value) : $value,
            'encrypted' => $encrypted,
        ]);
    }
}
