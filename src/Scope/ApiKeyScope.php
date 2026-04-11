<?php

namespace RiseTechApps\ApiKey\Scope;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class ApiKeyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = auth()->user();

        if (!$user || !$user->apiKey) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where('api_key_id', $user->apiKey->id);
    }
}
