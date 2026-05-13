<?php

namespace RiseTechApps\ApiKey\Models\UserCard;

use Illuminate\Database\Eloquent\Model;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;

class UserCard extends Model
{
    protected $fillable = [
        'authentication_id',
        'holder_name',
        'last_four',
        'brand',
        'expiry_month',
        'expiry_year',
        'mp_customer_id',
        'mp_card_id',
        'is_default',
    ];

    public function authentication()
    {
        return $this->belongsTo(Authentication::class);
    }
}
