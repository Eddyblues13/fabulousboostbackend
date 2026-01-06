<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    use HasFactory;

    protected $table = 'orders';
    protected $primaryKey = 'id';

    protected $fillable = [
        'user_id',
        'category_id',
        'service_id',
        'api_order_id',
        'api_refill_id',
        'link',
        'quantity',
        'price',
        'status',
        'refill_status',
        'status_description',
        'reason',
        'agree',
        'start_counter',
        'remains',
        'runs',
        'interval',
        'drip_feed',
        'refilled_at',
        'added_on',
        'start_time',
        'speed',
        'avg_time',
        'guarantee'
    ];

    protected $casts = [
        'price' => 'decimal:8',
        'agree' => 'boolean',
        'runs' => 'integer',
        'interval' => 'integer',
        'drip_feed' => 'boolean',
        'refilled_at' => 'datetime',
        'added_on' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Status constants
    const STATUS_PROCESSING = 'processing';
    const STATUS_IN_PROGRESS = 'in-progress';
    const STATUS_COMPLETED = 'completed';
    const STATUS_PARTIAL = 'partial';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_REFUNDED = 'refunded';




    /**
     * Get the service metrics as an array
     */
    public function getMetricsAttribute()
    {
        return [
            'start_time' => $this->start_time ?? '5-30 minutes',
            'speed' => $this->speed ?? '100-1000/hour',
            'avg_time' => $this->avg_time ?? '7 hours 43 minutes',
            'guarantee' => $this->guarantee ?? '30 days'
        ];
    }


    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
