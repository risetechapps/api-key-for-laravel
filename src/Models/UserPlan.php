<?php

namespace RiseTechApps\ApiKey\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RiseTechApps\HasUuid\Traits\HasUuid\HasUuid;

class UserPlan extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = ['authentication_id', 'plan_id', 'start_date', 'end_date', 'active', 'requests_used'];

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }
//
//    public function isActive()
//    {
//        return now()->between($this->start_date, $this->end_date);
//    }
}
