<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChildPanelUser extends Model
{
    use HasFactory;

    protected $fillable = [
        'child_panel_id',
        'username',
        'email',
        'password',
        'balance',
        'status',
        'email_verified_at',
        'last_login_at',
        'api_key',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
    ];

    protected $hidden = [
        'password',
    ];

    public function childPanel(): BelongsTo
    {
        return $this->belongsTo(ChildPanel::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(ChildPanelOrder::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(ChildPanelTransaction::class);
    }

    public function setPasswordAttribute($value)
    {
        $this->attributes['password'] = bcrypt($value);
    }
}

