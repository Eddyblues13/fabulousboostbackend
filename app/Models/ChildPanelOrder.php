<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChildPanelOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'child_panel_id',
        'child_panel_user_id',
        'service_id',
        'link',
        'quantity',
        'price',
        'cost',
        'profit',
        'status',
        'provider_order_id',
        'start_count',
        'remains',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'cost' => 'decimal:2',
        'profit' => 'decimal:2',
        'quantity' => 'integer',
        'start_count' => 'integer',
        'remains' => 'integer',
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

