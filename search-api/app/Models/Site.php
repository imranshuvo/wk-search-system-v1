<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Site extends Model
{
    protected $table = 'wk_sites';
    protected $primaryKey = 'tenant_id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $fillable = [
        'tenant_id','site_name','site_url','status','api_key','feed_frequency','settings','search_config','last_feed_at','total_products','last_sync_at'
    ];
}


