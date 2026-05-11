<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DomainRealmMap extends Model
{
    protected $table = 'domain_realm_map';
    protected $fillable = ['domain', 'realm', 'mailcow_enabled', 'nextcloud_enabled', 'max_users', 'max_mailbox_users', 'max_nextcloud_users', 'mailcow_service_id', 'nextcloud_service_id'];
    protected $casts = ['mailcow_enabled' => 'boolean', 'nextcloud_enabled' => 'boolean'];

    public function mailcowService(): BelongsTo
    {
        return $this->belongsTo(ServerService::class, 'mailcow_service_id');
    }

    public function nextcloudService(): BelongsTo
    {
        return $this->belongsTo(ServerService::class, 'nextcloud_service_id');
    }
}
