<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminUser extends Model
{
    protected $table = 'wk_admin_users';
    public $timestamps = true;
    protected $fillable = ['email','password_hash'];
    protected $hidden = ['password_hash'];
}


