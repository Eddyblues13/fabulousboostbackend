<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChildPanelSubscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'child_panel_id',
        'amount',
        'payment_method',
        'transaction_id',
        'period_start',
        'period_end',
        'status',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
    ];

    public function childPanel(): BelongsTo
    {
        return $this->belongsTo(ChildPanel::class);
    }
}

