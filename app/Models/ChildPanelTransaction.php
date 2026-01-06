<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChildPanelTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'child_panel_id',
        'child_panel_user_id',
        'type',
        'amount',
        'balance_before',
        'balance_after',
        'description',
        'payment_method',
        'transaction_id',
        'status',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
    ];

    public function childPanel(): BelongsTo
    {
        return $this->belongsTo(ChildPanel::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(ChildPanelUser::class, 'child_panel_user_id');
    }
}

