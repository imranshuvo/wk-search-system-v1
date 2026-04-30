<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    protected $table = 'wk_tenants';
    protected $primaryKey = 'tenant_id';
    public $incrementing = true;
    protected $keyType = 'int';
    
    protected $fillable = [
        'site_name',
        'site_url',
        'status',
        'api_key',
        'feed_frequency',
        'total_products',
        'last_feed_at',
        'last_sync_at',
        'settings',
        'search_config',
    ];
    
    protected $casts = [
        'settings' => 'array',
        'search_config' => 'array',
        'last_feed_at' => 'datetime',
        'last_sync_at' => 'datetime',
    ];
}

