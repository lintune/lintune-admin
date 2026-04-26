<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Mailbox extends Model
{
    protected $table    = 'mailboxes';
    protected $fillable = ['email', 'realm', 'active'];
}
