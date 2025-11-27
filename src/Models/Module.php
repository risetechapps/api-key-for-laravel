<?php

namespace RiseTechApps\ApiKey\Models;

use Illuminate\Database\Eloquent\Model;
use RiseTechApps\HasUuid\Traits\HasUuid;

class Module extends Model
{
    use HasUuid;

    protected $fillable = [
        'name',
        'module',
        'description',
        'status'
    ];

    protected $hidden = [
        'id',
        'created_at',
        'updated_at',
        'pivot'
    ];
}
