<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceUpdate extends Model
{
    use HasFactory;

    protected $fillable = [
        'service',
        'details',
        'date',
        'update',
        'category'
    ];

    protected $casts = [
        'date' => 'date'
    ];
}
