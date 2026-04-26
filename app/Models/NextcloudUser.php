<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NextcloudUser extends Model
{
    protected $table    = 'nextcloud_users';
    protected $fillable = ['realm', 'username'];
}
