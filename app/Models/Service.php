<?php

namespace App\Models;

use App\Models\UserServiceRate;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Service extends Model
{
    use HasFactory;

    protected $table = 'services';

    protected $fillable = [
        'service_title',
        'category_id',
        'link',
        'username',
        'min_amount',
        'max_amount',
        'average_time',
        'description',
        'rate_per_1000',
        'price',
        'price_percentage_increase',
        'service_status',
        'service_type',
        'api_provider_id',
        'api_service_id',
        'api_provider_price',
        'drip_feed',
        'refill',
        'is_refill_automatic',
    ];

    public $timestamps = false;

    protected $casts = [
        'min_amount' => 'integer',
        'max_amount' => 'integer',
        'price_percentage_increase' => 'double',
        'service_status' => 'integer',
        'drip_feed' => 'integer',
        'refill' => 'boolean',
        'is_refill_automatic' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    // Optional: Relationship to category
    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }


    public function provider()
    {
        return $this->belongsTo(ApiProvider::class, 'api_provider_id', 'id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'service_id', 'id');
    }

    protected function scopeUserRate($query)
    {
        $query->addSelect([
            'user_rate' => UserServiceRate::select('price')
                ->whereColumn('service_id', 'services.id')
                ->where('user_id', auth()->id())
        ]);
    }



    public function getProviderNameAttribute($value)
    {
        if (isset($this->api_provider_id) && $this->api_provider_id != 0) {
            $prov = ApiProvider::find($this->api_provider_id);
            if ($prov) {
                return $prov['api_name'];
            }
            return false;
        }
    }


    public static function increaseAllPrices($percentage)
    {
        $increaseFactor = 1 + ($percentage / 100);

        return self::query()->update([
            'price' => DB::raw("ROUND(price * $increaseFactor, 8)"),

        ]);
    }

    public static function bulkIncreasePrices($percentage, $conditions = [])
    {
        $increaseFactor = 1 + ($percentage / 100);
        $query = self::query();

        // Apply conditions if provided
        if (!empty($conditions['category_id'])) {
            $query->where('category_id', $conditions['category_id']);
        }


        if (!empty($conditions['service_ids'])) {
            $query->whereIn('id', $conditions['service_ids']);
        }

        return $query->update([
            'price' => DB::raw("ROUND(price * $increaseFactor, 8)")

        ]);
    }
}
