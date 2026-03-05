<?php

namespace RiseTechApps\ApiKey\Models\RequestLog;

use Illuminate\Database\Eloquent\Model;
use RiseTechApps\HasUuid\Traits\HasUuid;

class RequestLog extends Model
{
    use HasUuid;

    protected $fillable = ['authentication_id', 'endpoint', 'requested_at', 'method', 'response_code'];
}
