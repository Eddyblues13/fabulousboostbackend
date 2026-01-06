<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Service;
use App\Models\Category;
use App\Models\Order;
use App\Models\Refill;
use App\Models\ApiProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmmApiController extends Controller
{
    /**
     * Validate API key from request
     */
    private function validateApiKey(Request $request)
    {
        $apiKey = $request->input('key') ?? $request->header('X-API-KEY');

        if (!$apiKey) {
            return response()->json(['error' => 'API key is required'], 401);
        }

        $user = User::where('api_key', $apiKey)->first();

        if (!$user) {
            return response()->json(['error' => 'Invalid API key'], 401);
        }



        return $user;
    }

    /**
     * Get active SMM Provider configuration
     */
    private function getActiveProvider()
    {
        return ApiProvider::where('status', 1)->first();
    }

    /**
     * Call SMM Provider API
     */
    private function callProviderApi($endpoint, $data = [])
    {
        $provider = $this->getActiveProvider();

        if (!$provider) {
            Log::error('No active SMM provider configured');
            return null;
        }

        $fullUrl = $provider->url . $endpoint;

        Log::channel('stderr')->info('Attempting provider API call', [
            'url' => $fullUrl,
            'headers' => [
                'Authorization' => 'Bearer ' . $provider->api_key,
                'Accept' => 'application/json'
            ],
            'payload' => $data
        ]);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $provider->api_key,
                'Accept' => 'application/json',
            ])
                ->timeout(30)
                ->post($fullUrl, $data);

            // Log full response details
            Log::channel('stderr')->info('Provider raw response', [
                'status' => $response->status(),
                'headers' => $response->headers(),
                'body' => $response->body()
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('SMM Provider API Error', [
                'provider' => $provider->api_name,
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'response' => $response->body()
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('SMM Provider API Exception', [
                'provider' => $provider->api_name,
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString() // Add stack trace
            ]);

            return null;
        }
    }

    /**
     * Generate API key for user
     */
    public function generateApiKey(Request $request)
    {
        $user = $request->user();

        if ($user->api_key) {
            return response()->json([
                'message' => 'You already have an API key',
                'api_key' => $user->api_key
            ]);
        }

        $apiKey = Str::random(60);
        $user->api_key = $apiKey;
        $user->save();

        return response()->json([
            'message' => 'API key generated successfully',
            'api_key' => $apiKey
        ]);
    }

    /**
     * Get available services
     */
    public function getServices(Request $request)
    {
        try {
            // User is authenticated by VerifyApiKey middleware
            $user = Auth::user();
            
            if (!$user) {
                // Fallback validation if middleware didn't run
                $apiKey = $request->input('key') ?? $request->header('X-API-KEY');
                if (!$apiKey) {
                    return response()->json(['error' => 'API key is required'], 401);
                }
                $user = User::where('api_key', $apiKey)->first();
                if (!$user) {
                    return response()->json(['error' => 'Invalid API key'], 401);
                }
            }

            // Get provider configuration
            $provider = ApiProvider::where('status', 1)->first();
            if (!$provider) {
                return response()->json(['error' => 'No active API provider configured'], 400);
            }

            // First try to get services from database
            $services = Service::with('category')
                ->where('service_status', 1)
                ->where('api_provider_id', $provider->id)
                ->get()
                ->map(function ($service) {
                    return [
                        'service' => $service->id,
                        'name' => $service->service_title,
                        'category' => $service->category->category_title ?? 'Uncategorized',
                        'rate' => (float)($service->price ?? $service->rate_per_1000 ?? 0),
                        'min' => (int)$service->min_amount,
                        'max' => (int)$service->max_amount,
                        'provider_id' => $service->api_service_id,
                        'type' => $service->service_type ?? 'default',
                        'refill' => (bool)($service->refill ?? false),
                        'drip_feed' => (bool)($service->drip_feed ?? false)
                    ];
                });

            // If no services in DB for this provider, get all active services
            if ($services->isEmpty()) {
                $services = Service::with('category')
                    ->where('service_status', 1)
                    ->get()
                    ->map(function ($service) {
                        return [
                            'service' => $service->id,
                            'name' => $service->service_title,
                            'category' => $service->category->category_title ?? 'Uncategorized',
                            'rate' => (float)($service->price ?? $service->rate_per_1000 ?? 0),
                            'min' => (int)$service->min_amount,
                            'max' => (int)$service->max_amount,
                            'provider_id' => $service->api_service_id,
                            'type' => $service->service_type ?? 'default',
                            'refill' => (bool)($service->refill ?? false),
                            'drip_feed' => (bool)($service->drip_feed ?? false)
                        ];
                    });
            }

            // If still no services, try fetching from provider API
            if ($services->isEmpty() && $provider) {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $provider->api_key,
                    'Accept' => 'application/json',
                ])->get($provider->url . '/services');

                if (!$response->successful()) {
                    Log::error('Bulkfollows API Error', [
                        'status' => $response->status(),
                        'response' => $response->body()
                    ]);
                    return response()->json([
                        'error' => 'Failed to fetch services from provider',
                        'provider_response' => $response->body()
                    ], 502);
                }

                $providerServices = $response->json();

                // Process Bulkfollows specific response format
                foreach ($providerServices as $providerService) {
                    try {
                        $category = Category::firstOrCreate([
                            'name' => $providerService['category'] ?? 'Uncategorized'
                        ]);

                        Service::updateOrCreate(
                            ['api_service_id' => $providerService['id'], 'api_provider_id' => $provider->id],
                            [
                                'category_id' => $category->id,
                                'service_title' => $providerService['name'], // Changed to service_title
                                'rate_per_1000' => $providerService['rate'] ?? 0, // Changed to rate_per_1000
                                'min_amount' => $providerService['min'] ?? 1,
                                'max_amount' => $providerService['max'] ?? 1000,
                                'refill' => $providerService['refill'] ?? false,
                                'service_status' => 1, // Using your actual column name
                                'service_type' => $providerService['type'] ?? 'default',
                                'drip_feed' => $providerService['drip_feed'] ?? false,
                                'api_provider_price' => $providerService['rate'] ?? 0 // Added provider price
                            ]
                        );
                    } catch (\Exception $e) {
                        Log::error('Error saving service', [
                            'service_id' => $providerService['id'] ?? null,
                            'error' => $e->getMessage()
                        ]);
                        continue;
                    }
                }

                // Return the now-populated services
                return $this->getServices($request);
            }

            return response()->json($services);
        } catch (\Exception $e) {
            Log::error('getServices Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'error' => 'Internal Server Error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Place new order
     */
    public function placeOrder(Request $request)
    {

        // 1. Dump the raw request first
        \Illuminate\Support\Facades\Log::channel('stderr')->info('RAW REQUEST: ' . print_r($request->all(), true));

        // 2. Validate API Key
        $user = $this->validateApiKey($request);
        if ($user instanceof \Illuminate\Http\JsonResponse) {
            \Illuminate\Support\Facades\Log::channel('stderr')->error('API KEY FAIL: ' . $user->getContent());
            return $user;
        }

        // 3. Debug Service
        $service = Service::find($request->service);
        if (!$service) {
            $error = 'Service not found: ID ' . $request->service;
            \Illuminate\Support\Facades\Log::channel('stderr')->error($error);
            return response()->json(['error' => $error], 404);
        }

        // 4. Debug Price Calculation
        $price = $service->rate_per_1000 * ($request->quantity / 1000);
        \Illuminate\Support\Facades\Log::channel('stderr')->info("CALCULATED PRICE: $price");

        // 5. Force terminal output before DB operations
        \Illuminate\Support\Facades\Log::channel('stderr')->info('ABOUT TO CREATE ORDER');




        $service = Service::find($request->service);
        if (!$service) {
            Log::error('Service not found:', ['id' => $request->service]);
            return response()->json(['error' => 'Service not found'], 404);
        }

        Log::info('Service:', ['id' => $service->id, 'price' => $service->price, 'rate_per_1000' => $service->rate_per_1000]);
        $user = $this->validateApiKey($request);
        if ($user instanceof \Illuminate\Http\JsonResponse) {
            return $user;
        }

        $request->validate([
            'service' => 'required|integer|exists:services,id',
            'link' => 'required|url',
            'quantity' => 'required|integer|min:1',
            'runs' => 'sometimes|integer|min:1',
            'interval' => 'sometimes|integer|min:1',
            'drip_feed' => 'sometimes|boolean'
        ]);

        $service = Service::findOrFail($request->service);

        // Validate quantity against service limits
        if ($request->quantity < $service->min_amount || $request->quantity > $service->max_amount) {
            return response()->json([
                'error' => 'Quantity must be between ' . $service->min_amount . ' and ' . $service->max_amount
            ], 400);
        }

        // Calculate price - use price if available, otherwise calculate from rate_per_1000
        $ratePerUnit = $service->price ?? ($service->rate_per_1000 / 1000);
        $price = $ratePerUnit * $request->quantity;
        if ($user->balance < $price) {
            return response()->json(['error' => 'Insufficient balance'], 400);
        }

        // Deduct balance
        $user->balance -= $price;
        $user->save();

        // Create order in our system
        $order = Order::create([
            'user_id' => $user->id,
            'category_id' => $service->category_id,
            'service_id' => $service->id,
            'link' => $request->link,
            'quantity' => $request->quantity,
            'price' => $price,
            'status' => 'pending',
            'start_counter' => 0,
            'remains' => $request->quantity,
            'runs' => $request->runs ?? 1,
            'interval' => $request->interval ?? null,
            'drip_feed' => $request->drip_feed ?? false,
            'added_on' => now()
        ]);

        // Call SMM Provider API to place the actual order
        // $providerResponse = $this->callProviderApi('/order', [
        //     'service' => $service->api_service_id,
        //     'link' => $request->link,
        //     'quantity' => $request->quantity,
        //     'runs' => $request->runs ?? 1,
        //     'interval' => $request->interval ?? null
        // ]);

        $providerResponse = $this->callProviderApi('/order', [
            'service' => $service->api_service_id,
            'link' => $request->link,
            'quantity' => $request->quantity,
            'runs' => $request->runs ?? 1,
            'interval' => $request->interval ?? null
        ]);

        Log::channel('stderr')->info('Provider Response:', ['response' => $providerResponse]);

        if (!$providerResponse) {
            Log::channel('stderr')->error('Provider API call failed - no response');
        } elseif (isset($providerResponse['error'])) {
            Log::channel('stderr')->error('Provider API error:', ['error' => $providerResponse['error']]);
        }

        if ($providerResponse && isset($providerResponse['order_id'])) {
            // Update our order with provider's order ID
            $order->api_order_id = $providerResponse['order_id'];
            $order->status = 'in progress';
            $order->save();

            return response()->json([
                'order' => $order->id,
                'provider_order_id' => $providerResponse['order_id']
            ]);
        } else {
            // Provider API failed, refund user
            $user->balance += $price;
            $user->save();

            $order->status = 'failed';
            $order->status_description = 'Failed to place order with provider';
            $order->save();

            return response()->json([
                'error' => 'Failed to place order with provider',
                'refunded' => true
            ], 502);
        }
    }

    /**
     * Check order status
     */
    public function checkOrderStatus(Request $request)
    {
        $user = $this->validateApiKey($request);
        if ($user instanceof \Illuminate\Http\JsonResponse) {
            return $user;
        }

        $request->validate([
            'order' => 'required|integer|exists:orders,id,user_id,' . $user->id
        ]);

        $order = Order::findOrFail($request->order);

        // If we have a provider order ID, check with provider
        if ($order->api_order_id) {
            $providerResponse = $this->callProviderApi('/order/status', [
                'order_id' => $order->api_order_id
            ]);

            if ($providerResponse) {
                // Update our order status based on provider response
                $order->status = $providerResponse['status'] ?? $order->status;
                $order->start_counter = $providerResponse['start_count'] ?? $order->start_counter;
                $order->remains = $providerResponse['remains'] ?? $order->remains;
                $order->status_description = $providerResponse['status_description'] ?? null;
                $order->save();
            }
        }

        return response()->json([
            'charge' => $order->price,
            'start_count' => $order->start_counter,
            'status' => $order->status,
            'status_description' => $order->status_description,
            'remains' => $order->remains,
            'currency' => 'USD',
            'provider_order_id' => $order->api_order_id
        ]);
    }

    /**
     * Check multiple orders status
     */
    public function checkMultiOrderStatus(Request $request)
    {
        $user = $this->validateApiKey($request);
        if ($user instanceof \Illuminate\Http\JsonResponse) {
            return $user;
        }

        $request->validate([
            'orders' => 'required|string'
        ]);

        $orderIds = explode(',', $request->orders);
        $response = [];

        foreach ($orderIds as $orderId) {
            $order = Order::where('id', $orderId)
                ->where('user_id', $user->id)
                ->first();

            if (!$order) {
                $response[$orderId] = ['error' => 'Incorrect order ID'];
                continue;
            }

            // Check with provider if we have an API order ID
            if ($order->api_order_id) {
                $providerResponse = $this->callProviderApi('/order/status', [
                    'order_id' => $order->api_order_id
                ]);

                if ($providerResponse) {
                    $order->status = $providerResponse['status'] ?? $order->status;
                    $order->start_counter = $providerResponse['start_count'] ?? $order->start_counter;
                    $order->remains = $providerResponse['remains'] ?? $order->remains;
                    $order->status_description = $providerResponse['status_description'] ?? null;
                    $order->save();
                }
            }

            $response[$orderId] = [
                'charge' => $order->price,
                'start_count' => $order->start_counter,
                'status' => $order->status,
                'status_description' => $order->status_description,
                'remains' => $order->remains,
                'currency' => 'USD',
                'provider_order_id' => $order->api_order_id
            ];
        }

        return response()->json($response);
    }

    /**
     * Create refill
     */
    public function createRefill(Request $request)
    {
        $user = $this->validateApiKey($request);
        if ($user instanceof \Illuminate\Http\JsonResponse) {
            return $user;
        }

        $request->validate([
            'order' => 'required|integer|exists:orders,id,user_id,' . $user->id
        ]);

        $order = Order::with('service')->findOrFail($request->order);

        // Check if service exists and has refill enabled
        if (!$order->service) {
            return response()->json(['error' => 'Service not found for this order'], 400);
        }

        $isRefillEnabled = $order->service->refill ?? false;
        if (!$isRefillEnabled) {
            return response()->json(['error' => 'Refill not available for this service'], 400);
        }

        if ($order->refill_status === 'pending') {
            return response()->json(['error' => 'Refill already requested for this order'], 400);
        }

        // Call provider API for refill
        if ($order->api_order_id) {
            $providerResponse = $this->callProviderApi('/order/refill', [
                'order_id' => $order->api_order_id
            ]);

            if ($providerResponse && isset($providerResponse['refill_id'])) {
                $order->refill_status = 'pending';
                $order->api_refill_id = $providerResponse['refill_id'];
                $order->save();

                return response()->json([
                    'refill' => $order->id,
                    'provider_refill_id' => $providerResponse['refill_id']
                ]);
            } else {
                return response()->json([
                    'error' => 'Failed to request refill from provider'
                ], 502);
            }
        }

        return response()->json(['error' => 'Original order not found with provider'], 400);
    }

    /**
     * Get user balance
     */
    public function getBalance(Request $request)
    {
        $user = $this->validateApiKey($request);

        if ($user instanceof \Illuminate\Http\JsonResponse) {
            return $user;
        }

        if (!$user || !isset($user->balance)) {
            return response()->json(['error' => 'User not found or balance not set'], 404);
        }

        return response()->json([
            'balance' => $user->balance,
            'currency' => 'USD'
        ]);
    }


    /**
     * Get order history
     */
    public function getOrderHistory(Request $request)
    {
        $user = $this->validateApiKey($request);
        if ($user instanceof \Illuminate\Http\JsonResponse) {
            return $user;
        }

        $status = $request->input('status');
        $search = $request->input('search');
        $page = $request->input('page', 1);
        $perPage = 20;

        $query = Order::with(['service', 'category'])
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc');

        if ($status) {
            $query->where('status', $status);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('link', 'like', "%{$search}%")
                    ->orWhereHas('service', function ($q) use ($search) {
                        $q->where('service_title', 'like', "%{$search}%");
                    });
            });
        }

        $orders = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => $orders->items(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ]
        ]);
    }

    /**
     * Sync all orders with provider (can be called via cron)
     */
    public function syncOrdersWithProvider()
    {
        $orders = Order::whereNotNull('api_order_id')
            ->whereNotIn('status', ['completed', 'canceled', 'refunded', 'failed'])
            ->get();

        $results = [
            'total' => $orders->count(),
            'updated' => 0,
            'failed' => 0
        ];

        foreach ($orders as $order) {
            try {
                $providerResponse = $this->callProviderApi('/order/status', [
                    'order_id' => $order->api_order_id
                ]);

                if ($providerResponse) {
                    $order->status = $providerResponse['status'] ?? $order->status;
                    $order->start_counter = $providerResponse['start_count'] ?? $order->start_counter;
                    $order->remains = $providerResponse['remains'] ?? $order->remains;
                    $order->status_description = $providerResponse['status_description'] ?? null;

                    if ($order->isDirty()) {
                        $order->save();
                        $results['updated']++;
                    }
                } else {
                    $results['failed']++;
                }
            } catch (\Exception $e) {
                Log::error('Failed to sync order with provider', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage()
                ]);
                $results['failed']++;
            }
        }

        return $results;
    }



    // In SmmApiController
    public function testProviderConfig(Request $request)
    {
        try {
            $provider = ApiProvider::first();

            if (!$provider) {
                return response()->json(['error' => 'No provider configured'], 400);
            }

            return response()->json([
                'provider' => $provider,
                'test_endpoint' => $provider->url . '/services'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Config check failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
