<?php

namespace App\Http\Controllers\API;

use App\Models\Order;
use App\Models\Service;
use App\Mail\CustomMail;
use App\Models\ApiProvider;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\GeneralNotification;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use App\Jobs\CreateGeneralNotificationJob;

class OrderController extends Controller
{
    public function store(Request $request)
    {
        DB::beginTransaction();

        try {
            // Validation rules
            $rules = [
                'category' => 'required|integer|min:1|not_in:0',
                'service' => 'required|integer|min:1|not_in:0',
                'link' => 'required|url',
                'quantity' => 'required|integer|min:1',
                'check' => 'required|accepted',
            ];

            if ($request->has('runs') || $request->has('interval')) {
                $rules['runs'] = 'required|integer|min:1';
                $rules['interval'] = 'required|integer|min:1';
            }

            $validator = Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => $validator->errors()->first()
                ], 422);
            }

            $user = Auth::user();

            // 1. Check if user is active/verified
            if ($user->status !== 'active') {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Your account is not active. Please contact support.'
                ], 403);
            }

            // 2. Check for duplicate active order
            $activeStatuses = ['pending', 'processing', 'inprogress'];
            $duplicateOrder = Order::where('user_id', $user->id)
                ->where('service_id', $request->service)
                ->where('link', $request->link)
                ->whereIn('status', $activeStatuses)
                ->first();

            if ($duplicateOrder) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'You have active order with this link. Please wait until order being completed.'
                ], 422);
            }

            // 3. Check if service exists and is active
            $service = Service::userRate()->where('id', $request->service)
                ->where('service_status', 1) // Active service
                ->first();

            if (!$service) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Service not found or currently unavailable.'
                ], 404);
            }

            // 4. Validate quantity against service limits
            $quantity = $request->quantity;
            if ($service->drip_feed == 1 && $request->has('runs')) {
                $quantity = $request->quantity * $request->runs;
            }

            if ($quantity < $service->min_amount) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => "Minimum quantity for this service is {$service->min_amount}"
                ], 422);
            }

            if ($quantity > $service->max_amount) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => "Maximum quantity for this service is {$service->max_amount}"
                ], 422);
            }

            // 5. Calculate price and check balance
            $userRate = $service->user_rate ?? $service->price;
            $price = round(($quantity * $userRate), 2);

            if ($user->balance < $price) {
                DB::rollBack();
                $needed = $price - $user->balance;
                return response()->json([
                    'status' => 'error',
                    'message' => "Insufficient balance. You need \${$needed} more to place this order.",
                    'required_amount' => $price,
                    'current_balance' => $user->balance,
                    'shortfall' => $needed
                ], 400);
            }

            // 6. Check if API provider is available (if service uses API)
            if ($service->api_provider_id) {
                $apiProvider = ApiProvider::where('id', $service->api_provider_id)
                    ->where('status', 1)
                    ->first();

                if (!$apiProvider) {
                    DB::rollBack();
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Service provider is currently unavailable. Please try again later.'
                    ], 503);
                }
            }

            // 7. Deduct balance
            $user->decrement('balance', $price);

            // 8. Create order
            $order = new Order();
            $order->user_id = $user->id;
            $order->category_id = $request->category;
            $order->service_id = $request->service;
            $order->link = $request->link;
            $order->quantity = $request->quantity;
            $order->status = Order::STATUS_PROCESSING;
            $order->price = $price;
            $order->runs = $request->runs ?? null;
            $order->interval = $request->interval ?? null;
            $order->drip_feed = $service->drip_feed;

            // 9. Process with API provider if applicable
            if ($service->api_provider_id && $apiProvider) {
                $postData = [
                    'key' => $apiProvider->api_key,
                    'action' => 'add',
                    'service' => $service->api_service_id,
                    'link' => $request->link,
                    'quantity' => $request->quantity,
                ];

                if ($request->has('runs')) $postData['runs'] = $request->runs;
                if ($request->has('interval')) $postData['interval'] = $request->interval;

                try {
                    $response = Http::timeout(30)->asForm()->post($apiProvider->url, $postData);
                    $apiData = $response->json();

                    if (isset($apiData['order'])) {
                        $order->status_description = "order: {$apiData['order']}";
                        $order->api_order_id = $apiData['order'];
                    } else {
                        // API returned error
                        $apiError = $apiData['error'] ?? 'Unknown API error';
                        $order->status_description = "error: {$apiError}";
                        $order->status = Order::STATUS_CANCELLED;

                        // Refund user for API failure
                        $user->increment('balance', $price);

                        DB::commit();

                        return response()->json([
                            'status' => 'error',
                            'message' => "Service provider error: {$apiError}"
                        ], 422);
                    }
                } catch (\Exception $e) {
                    // API connection failed
                    $order->status_description = "error: API connection failed - " . $e->getMessage();
                    $order->status = Order::STATUS_CANCELLED;

                    // Refund user for API connection failure
                    $user->increment('balance', $price);

                    DB::commit();

                    return response()->json([
                        'status' => 'error',
                        'message' => 'Service provider is temporarily unavailable. Please try again later.'
                    ], 503);
                }
            }

            $order->save();

            // 10. Create transaction record
            Transaction::create([
                'user_id' => $user->id,
                'transaction_id' => 'ORD_' . time() . '_' . str()->random(10),
                'transaction_type' => 'Debit',
                'amount' => $price,
                'charge' => 0,
                'description' => "Order #{$order->id} - {$service->service_title}",
                'status' => $order->status == Order::STATUS_CANCELLED ? 'refunded' : 'completed',
                'meta' => json_encode([
                    'order_id' => $order->id,
                    'service_id' => $service->id,
                    'service_name' => $service->service_title,
                    'quantity' => $request->quantity,
                    'link' => $request->link
                ]),
            ]);

            // 11. Send notification for successful order
            if ($order->status !== Order::STATUS_CANCELLED) {
                CreateGeneralNotificationJob::dispatchSync([
                    'user_id' => $user->id,
                    'type' => 'order',
                    'title' => 'Order Placed Successfully',
                    'message' => "Your order #{$order->id} for {$service->service_title} has been placed successfully. Amount charged: \${$price}.",
                ]);
            }

            DB::commit();

            $user->refresh();

            return response()->json([
                'status' => 'success',
                'message' => 'Order submitted successfully',
                'order_id' => $order->id,
                'balance' => $user->balance,
                'order_status' => $order->status,
                'charged_amount' => $price
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Order creation failed', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            // Return detailed error in development
            $errorMessage = 'System error occurred. Please try again or contact support if problem persists.';
            if (config('app.debug')) {
                $errorMessage .= ' Error: ' . $e->getMessage();
            }

            return response()->json([
                'status' => 'error',
                'message' => $errorMessage,
                'debug' => config('app.debug') ? [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ] : null
            ], 500);
        }
    }


    public function history(Request $request)
    {
        $user = Auth::user();

        $status = $request->input('status', 'all');
        $search = $request->input('search', '');
        $perPage = $request->input('per_page', 15);
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');

        $query = Order::with(['service:id,service_title', 'category:id,category_title'])
            ->where('user_id', $user->id);

        // Filter by status
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        // Search functionality - FIXED: use correct column name
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('link', 'like', "%{$search}%")
                    ->orWhere('id', 'like', "%{$search}%")
                    ->orWhereHas('service', function ($serviceQuery) use ($search) {
                        $serviceQuery->where('service_title', 'like', "%{$search}%"); // FIXED: was 'name'
                    });
            });
        }

        // Sorting
        $validSortColumns = ['id', 'created_at', 'price', 'quantity', 'status', 'user_id', 'service_id'];
        if (in_array($sortBy, $validSortColumns)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $orders = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $orders->map(function ($order) {
                return [
                    'id' => $order->id,
                    'user_id' => $order->user_id,
                    'service_id' => $order->service_id,
                    'api_order_id' => $order->api_order_id,
                    'category_id' => $order->category_id,
                    'link' => $order->link,
                    'price' => (float) $order->price,
                    'quantity' => (int) $order->quantity,
                    'start_counter' => (int) $order->start_counter,
                    'remains' => (int) $order->remains,
                    'status' => $order->status,
                    'status_description' => $order->status_description,
                    'reason' => $order->reason,
                    'runs' => $order->runs,
                    'interval' => $order->interval,
                    'drip_feed' => $order->drip_feed,
                    'refilled_at' => $order->refilled_at,
                    'created_at' => $order->created_at,
                    'updated_at' => $order->updated_at,
                    // Additional helpful fields
                    'service_name' => $order->service->service_title ?? 'N/A',
                    'category_name' => $order->category->category_title ?? 'N/A',
                ];
            }),
            'meta' => [
                'total' => $orders->total(),
                'current_page' => $orders->currentPage(),
                'per_page' => $orders->perPage(),
                'last_page' => $orders->lastPage(),
            ],
            'status_counts' => [
                'all' => Order::where('user_id', $user->id)->count(),
                'pending' => Order::where('user_id', $user->id)->where('status', 'pending')->count(),
                'processing' => Order::where('user_id', $user->id)->where('status', 'processing')->count(),
                'completed' => Order::where('user_id', $user->id)->where('status', 'completed')->count(),
                'partial' => Order::where('user_id', $user->id)->where('status', 'partial')->count(),
                'cancelled' => Order::where('user_id', $user->id)->where('status', 'cancelled')->count(),
                'failed' => Order::where('user_id', $user->id)->where('status', 'failed')->count(),
            ]
        ]);
    }
}
