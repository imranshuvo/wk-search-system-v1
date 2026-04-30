<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Synonym extends Model
{
    protected $table = 'wk_synonyms';
    protected $primaryKey = 'tenant_id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $fillable = ['tenant_id','synonym_data'];
    protected $casts = [
        'synonym_data' => 'array',
    ];
}


