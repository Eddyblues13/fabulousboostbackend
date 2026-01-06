<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChildPanel extends Model
{
    use HasFactory;

    protected $fillable = [
        'parent_user_id',
        'domain',
        'subdomain',
        'panel_name',
        'admin_username',
        'admin_email',
        'admin_password',
        'currency',
        'price_per_month',
        'status',
        'expires_at',
        'api_key',
        'markup_percentage',
        'balance',
        'nameservers',
        'settings',
        'last_payment_date',
        'next_payment_date',
    ];

    protected $casts = [
        'price_per_month' => 'decimal:2',
        'markup_percentage' => 'decimal:2',
        'balance' => 'decimal:2',
        'expires_at' => 'datetime',
        'last_payment_date' => 'datetime',
        'next_payment_date' => 'datetime',
        'nameservers' => 'array',
        'settings' => 'array',
    ];

    protected $hidden = [
        'admin_password',
    ];

    public function parentUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parent_user_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(ChildPanelUser::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(ChildPanelOrder::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(ChildPanelTransaction::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(ChildPanelSubscription::class);
    }

    public function setAdminPasswordAttribute($value)
    {
        $this->attributes['admin_password'] = bcrypt($value);
    }
}

