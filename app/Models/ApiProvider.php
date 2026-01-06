<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApiProvider extends Model
{
    use HasFactory;

    protected $table = 'api_providers';

    protected $fillable = [
        'api_name',
        'url',
        'api_key',
        'balance',
        'currency',
        'convention_rate',
        'status',
        'description'
    ];

    public $timestamps = false;

    protected $casts = [
        'status' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    // Relationship to services 
    public function services()
    {
        return $this->hasMany(Service::class, 'api_provider_id');
    }
}
