<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerService extends Model
{
    protected $fillable = ['server_id', 'service', 'service_url', 'is_default'];
    protected $casts = ['is_default' => 'boolean'];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public static function defaultForType(string $type): ?self
    {
        return static::where('service', $type)->where('is_default', true)->first();
    }

    public function setAsDefault(): void
    {
        static::where('service', $this->service)->update(['is_default' => false]);
        $this->update(['is_default' => true]);
    }
}
