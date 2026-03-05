<?php

namespace RiseTechApps\ApiKey\Models\UserPlan;

use Illuminate\Database\Eloquent\Model;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\HasUuid\Traits\HasUuid;

class UserPlan extends Model
{
    use HasUuid;

    protected $fillable = ['authentication_id', 'plan_id', 'start_date', 'end_date', 'active', 'requests_used'];

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function isActive(): bool
    {
        return now()->between($this->start_date, $this->end_date);
    }
}
