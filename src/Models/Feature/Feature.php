<?php

namespace RiseTechApps\ApiKey\Models\Feature;

use Illuminate\Database\Eloquent\Model;
use RiseTechApps\HasUuid\Traits\HasUuid;

class Feature extends Model
{
    use HasUuid;

    protected $table = 'plan_features';

    protected $fillable = [
        'key',
        'name',
        'description',
        'icon',
    ];
}
