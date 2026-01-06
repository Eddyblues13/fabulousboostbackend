<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Fund extends Model
{
    protected $fillable = [
        'user_id',
        'gateway_id',
        'gateway_currency',
        'amount',
        'charge',
        'rate',
        'final_amount',
        'btc_amount',
        'btc_wallet',
        'transaction',
        'try',
        'status',
        'detail',
        'feedback',
        'payment_id',
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }



    // Accessors / Helper functions
    public function isComplete()
    {
        return $this->status === 1;
    }

    public function isPending()
    {
        return $this->status === 2;
    }

    public function isCancelled()
    {
        return $this->status === 3;
    }
}
