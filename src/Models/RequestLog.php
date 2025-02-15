<?php

namespace RiseTechApps\ApiKey\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RiseTechApps\HasUuid\Traits\HasUuid\HasUuid;

class RequestLog extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = ['authentication_id', 'endpoint', 'requested_at'];
}
