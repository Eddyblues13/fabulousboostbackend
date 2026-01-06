<?php

namespace App\Http\Controllers\API\Admin;

use App\Models\Order;
use App\Models\Service;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ManageOrderController extends Controller
{
    public function destroy($id)
    {
        try {
            $order = Order::findOrFail($id);
            $order->delete();

            return response()->json([
                'success' => true,
                'message' => 'Order deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Delete Order Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete order',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $request->validate([
                'status' => 'sometimes|string|max:50',
                'refill_status' => 'sometimes|string|max:50',
                'status_description' => 'nullable|string|max:500',
                'reason' => 'nullable|string|max:500',
                'link' => 'sometimes|string|max:500',
                'quantity' => 'sometimes|integer|min:1',
                'price' => 'sometimes|numeric|min:0',
                'start_counter' => 'nullable|integer',
                'remains' => 'nullable|integer',
                'runs' => 'nullable|integer',
                'interval' => 'nullable|integer',
                'drip_feed' => 'sometimes|boolean',
            ]);

            $order = Order::findOrFail($id);
            $order->update($request->only([
                'status',
                'refill_status',
                'status_description',
                'reason',
                'link',
                'quantity',
                'price',
                'start_counter',
                'remains',
                'runs',
                'interval',
                'drip_feed',
            ]));

            $order->load(['user', 'service', 'category']);

            return response()->json([
                'success' => true,
                'message' => 'Order updated successfully',
                'data' => $order
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Update Order Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to update order',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function updateStatus(Request $request, $id)
    {
        try {
            $request->validate([
                'status' => 'required|string|max:50',
                'statusDescription' => 'nullable|string|max:500',
                'reason' => 'nullable|string|max:500',
            ]);

            $order = Order::findOrFail($id);
            $order->status = $request->status;
            $order->status_description = $request->statusDescription;
            $order->reason = $request->reason; // Fixed: was status_reason
            $order->save();

            $order->load(['user', 'service', 'category']);

            return response()->json([
                'success' => true,
                'message' => 'Order status updated successfully',
                'data' => $order
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Update Order Status Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to update order status',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $order = Order::with(['user', 'service', 'category'])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $order
            ]);
        } catch (\Exception $e) {
            Log::error('Show Order Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Order not found',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 404);
        }
    }

    public function getUserCategories()
    {
        $categories = Category::all();

        return response()->json([
            'success' => true,
            'data' => $categories
        ]);
    }


    public function getUserServices()
    {
        $services = Service::all();

        return response()->json([
            'success' => true,
            'data' => $services
        ]);
    }

    public function allOrders(Request $request): JsonResponse
    {
        try {
            $query = Order::with(['user', 'service', 'category'])
                ->latest();

            // Apply filters
            if ($request->has('status') && $request->status !== 'All Status') {
                $query->where('status', $request->status);
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('id', 'like', "%{$search}%")
                        ->orWhere('link', 'like', "%{$search}%")
                        ->orWhere('status', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($q) use ($search) {
                            $q->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('username', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        })
                        ->orWhereHas('service', function ($q) use ($search) {
                            $q->where('service_title', 'like', "%{$search}%");
                        });
                });
            }

            // Pagination
            $perPage = $request->per_page ?? 15;
            $orders = $query->paginate($perPage);

            // Format the response to include service_name
            $formattedOrders = $orders->getCollection()->map(function ($order) {
                return [
                    'id' => $order->id,
                    'user_id' => $order->user_id,
                    'category_id' => $order->category_id,
                    'service_id' => $order->service_id,
                    'api_order_id' => $order->api_order_id,
                    'api_refill_id' => $order->api_refill_id,
                    'link' => $order->link,
                    'quantity' => $order->quantity,
                    'price' => $order->price,
                    'status' => $order->status,
                    'refill_status' => $order->refill_status,
                    'status_description' => $order->status_description,
                    'reason' => $order->reason,
                    'start_counter' => $order->start_counter,
                    'remains' => $order->remains,
                    'runs' => $order->runs,
                    'interval' => $order->interval,
                    'drip_feed' => $order->drip_feed,
                    'refilled_at' => $order->refilled_at,
                    'created_at' => $order->created_at,
                    'updated_at' => $order->updated_at,
                    'added_on' => $order->added_on,
                    'service_name' => $order->service->service_title ?? null,
                    'user' => $order->user ? [
                        'id' => $order->user->id,
                        'first_name' => $order->user->first_name,
                        'last_name' => $order->user->last_name,
                        'username' => $order->user->username,
                        'email' => $order->user->email,
                    ] : null,
                    'service' => $order->service ? [
                        'id' => $order->service->id,
                        'service_title' => $order->service->service_title,
                    ] : null,
                    'category' => $order->category ? [
                        'id' => $order->category->id,
                        'category_title' => $order->category->category_title,
                    ] : null,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formattedOrders,
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ]);
        } catch (\Exception $e) {
            Log::error('ManageOrderController allOrders error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch orders',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
                'data' => []
            ], 500);
        }
    }
}
