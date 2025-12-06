<?php

namespace RiseTechApps\ApiKey\Scope;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class ApiKeyScope implements Scope
{

    public function apply(Builder $builder, Model $model): void
    {
        $builder->where('api_key_id', auth()->user()->apiKey->id);
    }
}
