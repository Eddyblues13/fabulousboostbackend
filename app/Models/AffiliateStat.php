<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AffiliateStat extends Model
{
    use HasFactory;
    protected $fillable = [
        'affiliate_program_id',
        'visits',
        'registrations',
        'referrals',
        'conversion_rate',
        'total_earnings',
        'available_earnings',
        'paid_earnings'
    ];

    public function program()
    {
        return $this->belongsTo(AffiliateProgram::class);
    }
}
