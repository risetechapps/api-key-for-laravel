<?php

namespace RiseTechApps\ApiKey\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RiseTechApps\CodeGenerate\Traits\HasCodeGenerate;
use RiseTechApps\HasUuid\Traits\HasUuid;
use RiseTechApps\ToUpper\Traits\HasToUpper;

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
