<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Gateway extends Model
{
    protected $fillable = [
        'code',
        'name',
        'parameters',
        'currencies',
        'extra_parameters',
        'currency',
        'symbol',
        'min_amount',
        'max_amount',
        'percentage_charge',
        'fixed_charge',
        'convention_rate',
        'sort_by',
        'image',
        'status',
        'note',
    ];

    // Relationships
    public function funds()
    {
        return $this->hasMany(Fund::class);
    }

    // Accessors / Helpers
    public function isActive()
    {
        return $this->status === 1;
    }

    public function isInactive()
    {
        return $this->status === 0;
    }
}
