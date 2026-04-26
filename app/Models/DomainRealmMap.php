<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DomainRealmMap extends Model
{
    protected $table = 'domain_realm_map';
    protected $fillable = ['domain', 'realm', 'mailcow_enabled', 'nextcloud_enabled', 'max_users', 'max_mailbox_users', 'max_nextcloud_users'];
    protected $casts = ['mailcow_enabled' => 'boolean', 'nextcloud_enabled' => 'boolean'];
}
