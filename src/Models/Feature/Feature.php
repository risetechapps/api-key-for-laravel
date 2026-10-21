<?php

namespace RiseTechApps\ApiKey\Models\Feature;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Feature extends Model
{
    use HasUuids;

    protected $table = 'plan_features';

    protected $fillable = [
        'key',
        'name',
        'description',
        'icon',
    ];
}
