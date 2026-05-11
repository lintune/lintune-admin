<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Server extends Model
{
    protected $fillable = ['label', 'host', 'internal_host', 'ssh_user', 'ssh_port'];

    public function services(): HasMany
    {
        return $this->hasMany(ServerService::class);
    }

    public function serviceOfType(string $type): ?ServerService
    {
        return $this->services->firstWhere('service', $type);
    }
}
