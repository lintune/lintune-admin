<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DomainRealmMap extends Model
{
    protected $table = 'domain_realm_map';
    protected $fillable = ['domain', 'realm'];
}
