<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AffiliateReferral extends Model
{
    use HasFactory;

    protected $fillable = [
        'affiliate_program_id',
        'referred_user_id',
        'commission_earned',
        'status'
    ];

    public function program()
    {
        return $this->belongsTo(AffiliateProgram::class);
    }

    public function referredUser()
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }
}
