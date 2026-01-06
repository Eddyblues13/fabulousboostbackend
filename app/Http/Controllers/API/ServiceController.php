<?php

namespace App\Http\Controllers\API;

use App\Models\Service;
use Illuminate\Http\Request;
use App\Models\ServiceUpdate;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;

class ServiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $cacheKey = 'services_category_' . $request->get('category_id', 'all');
            $cacheTime = 300; // 5 minutes

            $services = Cache::remember($cacheKey, $cacheTime, function () use ($request) {
                $query = Service::where('service_status', 1)
                    ->select([
                        'id',
                        'service_title',
                        'category_id',
                        'min_amount',
                        'max_amount',
                        'average_time',
                        'description',
                        'rate_per_1000',
                        'price',
                    ])
                    ->with(['category:id,category_title'])
                    ->orderBy('service_title');

                if ($request->has('category_id')) {
                    $query->where('category_id', $request->category_id);
                }

                return $query->get();
            });

            return response()->json([
                'success' => true,
                'data' => $services
            ]);
        } catch (\Exception $e) {
            Log::error('ServiceController index error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load services'
            ], 500);
        }
    }


    public function allServices(): JsonResponse
    {
        try {
            $services = Cache::remember('all_services_essential', 600, function () {
                return Service::select([
                    'id',
                    'service_title',
                    'category_id',
                    'link',
                    'username',
                    'min_amount',
                    'max_amount',
                    'price',
                    'price_percentage_increase',
                    'service_status',
                    'service_type',
                    'description',
                    'rate_per_1000',
                    'average_time',
                    'api_provider_id',
                    'api_service_id',
                    'api_provider_price',
                    'drip_feed',
                    'refill',
                    'is_refill_automatic',
                    'is_new',
                    'is_recommended',
                    'created_at',
                    'updated_at'
                ])
                    ->where('service_status', 1)
                    ->get();
            });

            return response()->json([
                'success' => true,
                'data' => $services
            ]);
        } catch (\Exception $e) {

            Log::error('allServices error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error loading services'
            ], 500);
        }
    }


    // public function allServices(): JsonResponse
    // {
    //     try {
    //         $services = Cache::remember('all_services_essential', 600, function () {
    //             return Service::select([
    //                 'id',
    //                 'service_title',
    //                 'category_id',
    //                 'api_provider_id',
    //                 'min_amount',
    //                 'max_amount',
    //                 'price',
    //                 'rate_per_1000',
    //                 'service_status'
    //             ])
    //                 // ->with([
    //                 //     'category:id,category_title',
    //                 //     'provider:id,name'
    //                 // ])
    //                 ->get();
    //         });

    //         return response()->json([
    //             'success' => true,
    //             'data' => $services
    //         ]);
    //     } catch (\Exception $e) {
    //         Log::error('ServiceController allServices error: ' . $e->getMessage());
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Failed to load services'
    //         ], 500);
    //     }
    // }

    public function allSmmServices(Request $request): JsonResponse
    {
        try {
            $cacheKey = 'smm_services_' . md5(serialize($request->all()));

            $result = Cache::remember($cacheKey, 300, function () use ($request) {
                $query = Service::select([
                    'id',
                    'service_title',
                    'category_id',
                    'api_provider_id',
                    'min_amount',
                    'max_amount',
                    'price',
                    'rate_per_1000',
                    'average_time',
                    'description',
                    'service_status'
                ])
                    ->with([
                        'category:id,category_title'
                    ])
                    ->where('service_status', 1);

                // Handle is_new filter if column exists
                if ($request->has('is_new')) {
                    try {
                        $query->where('is_new', boolval($request->is_new));
                    } catch (\Exception $e) {
                        // Column might not exist, skip
                    }
                }

                // Handle is_recommended filter if column exists
                if ($request->has('is_recommended')) {
                    try {
                        $query->where('is_recommended', boolval($request->is_recommended));
                    } catch (\Exception $e) {
                        // Column might not exist, skip
                    }
                }

                // Handle category filter
                if ($request->filled('category') && $request->category !== 'all') {
                    $query->whereHas('category', function ($q) use ($request) {
                        $categoryTitle = str_replace('-', ' ', $request->category);
                        $q->where('category_title', 'LIKE', '%' . $categoryTitle . '%');
                    });
                }

                // Handle search
                if ($request->filled('search')) {
                    $searchTerm = $request->search;
                    $query->where(function ($q) use ($searchTerm) {
                        $q->where('service_title', 'LIKE', '%' . $searchTerm . '%')
                            ->orWhere('description', 'LIKE', '%' . $searchTerm . '%');
                    });
                }

                // Handle sorting
                if ($request->has('sort')) {
                    switch ($request->sort) {
                        case 'price-low':
                            $query->orderBy('price', 'asc');
                            break;
                        case 'price-high':
                            $query->orderBy('price', 'desc');
                            break;
                        case 'popular':
                            $query->orderBy('id', 'desc'); // Fallback if orders_count doesn't exist
                            break;
                        default:
                            $query->orderBy('id', 'desc');
                    }
                } else {
                    $query->orderBy('id', 'desc');
                }

                $services = $query->get()->map(function($service) {
                    // Generate slug for category if needed
                    $categorySlug = null;
                    if ($service->category) {
                        $categorySlug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $service->category->category_title), '-'));
                    }

                    return [
                        'id' => $service->id,
                        'service_title' => $service->service_title,
                        'category_id' => $service->category_id,
                        'category' => $service->category ? [
                            'id' => $service->category->id,
                            'category_title' => $service->category->category_title,
                            'slug' => $categorySlug,
                            'name' => $service->category->category_title,
                        ] : null,
                        'price' => (float) ($service->price ?? 0),
                        'min_amount' => (int) ($service->min_amount ?? 0),
                        'max_amount' => (int) ($service->max_amount ?? 0),
                        'average_time' => $service->average_time ?? 'N/A',
                        'description' => $service->description,
                        'rate_per_1000' => (float) ($service->rate_per_1000 ?? 0),
                    ];
                });

                return $services;
            });

            return response()->json([
                'data' => $result
            ]);
        } catch (\Exception $e) {
            Log::error('ServiceController allSmmServices error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load services',
                'error' => $e->getMessage(),
                'data' => []
            ], 500);
        }
    }

    public function searchServices(Request $request): JsonResponse
    {
        try {
            $searchTerm = trim($request->get('q', ''));
            $limit = $request->get('limit', 20);

            if (empty($searchTerm)) {
                return response()->json([
                    'success' => true,
                    'data' => []
                ]);
            }

            $cacheKey = 'service_search_' . md5($searchTerm . '_' . $limit);
            $services = Cache::remember($cacheKey, 120, function () use ($searchTerm, $limit) {
                return Service::select([
                    'id',
                    'service_title',
                    'category_id',
                    'min_amount',
                    'max_amount',
                    'price',
                    'rate_per_1000',
                    'description'
                ])
                    ->with(['category:id,category_title'])
                    ->where('service_status', 1)
                    ->where(function ($query) use ($searchTerm) {
                        $query->where('service_title', 'LIKE', $searchTerm . '%')
                            ->orWhere('service_title', 'LIKE', '% ' . $searchTerm . '%')
                            ->orWhere('description', 'LIKE', '%' . $searchTerm . '%');
                    })
                    ->limit($limit)
                    ->get();
            });

            return response()->json([
                'success' => true,
                'data' => $services
            ]);
        } catch (\Exception $e) {
            Log::error('ServiceController searchServices error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Search failed'
            ], 500);
        }
    }

    public function serviceUpdates(): JsonResponse
    {
        try {
            $updates = Cache::remember('service_updates', 3600, function () {
                return ServiceUpdate::select(['id', 'service', 'details', 'update', 'category', 'date', 'created_at'])
                    ->orderBy('date', 'desc')
                    ->orderBy('created_at', 'desc')
                    ->get()
                    ->map(function ($update) {
                        return [
                            'id' => $update->id,
                            'service' => $update->service ?? '',
                            'details' => $update->details ?? '',
                            'update' => $update->update ?? '',
                            'category' => $update->category ?? '',
                            'date' => $update->date ? date('Y-m-d', strtotime($update->date)) : ($update->created_at ? $update->created_at->format('Y-m-d') : ''),
                            'created_at' => $update->created_at ? $update->created_at->format('Y-m-d H:i:s') : null,
                        ];
                    });
            });

            return response()->json([
                'success' => true,
                'data' => $updates
            ]);
        } catch (\Exception $e) {
            Log::error('ServiceController serviceUpdates error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to load service updates',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
                'data' => []
            ], 500);
        }
    }
}
