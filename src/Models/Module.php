<?php

namespace RiseTechApps\ApiKey\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RiseTechApps\HasUuid\Traits\HasUuid\HasUuid;

class Module extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'name',
        'module'
    ];

    protected $hidden = [
        'id',
        'created_at',
        'updated_at',
        'pivot'
    ];
}
