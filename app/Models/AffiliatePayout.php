<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AffiliatePayout extends Model
{
    use HasFactory;

    protected $fillable = [
        'affiliate_program_id',
        'amount',
        'status',
        'transaction_id',
        'payment_method',
        'notes'
    ];

    public function program()
    {
        return $this->belongsTo(AffiliateProgram::class);
    }
}
