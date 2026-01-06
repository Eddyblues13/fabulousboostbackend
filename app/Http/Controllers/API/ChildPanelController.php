<?php

namespace App\Http\Controllers\API;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Models\ChildPanel;
use App\Models\ChildPanelUser;
use App\Models\ChildPanelOrder;
use App\Models\ChildPanelTransaction;
use App\Models\ChildPanelSubscription;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class ChildPanelController extends Controller
{
    public function index()
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'message' => 'User not authenticated'
                ], 401);
            }

            $panels = ChildPanel::where('parent_user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'data' => $panels,
                'message' => 'Child panels retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('ChildPanel index error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Failed to load child panels',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $user = Auth::user();
            
            $panel = ChildPanel::where('id', $id)
                ->where('parent_user_id', $user->id)
                ->firstOrFail();

            $stats = $this->calculateStats($panel);

            return response()->json([
                'data' => $panel,
                'stats' => $stats,
                'message' => 'Child panel retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('ChildPanel show error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Child panel not found',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 404);
        }
    }

    public function store(Request $request)
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'message' => 'User not authenticated'
                ], 401);
            }

            $validator = Validator::make($request->all(), [
                'domain' => 'required|string|max:255|unique:child_panels,domain',
                'panel_name' => 'required|string|max:255',
                'admin_username' => 'required|string|min:3|max:255',
                'admin_email' => 'required|email|max:255',
                'admin_password' => 'required|string|min:8',
                'currency' => 'required|string|in:USD,NGN,EUR,GBP,usd,ngn,eur,gbp',
                'price_per_month' => 'required|numeric|min:0',
                'markup_percentage' => 'nullable|numeric|min:0|max:100',
                'nameservers' => 'nullable|array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Check if domain already exists
            $existingPanel = ChildPanel::where('domain', $request->domain)->first();
            if ($existingPanel) {
                return response()->json([
                    'message' => 'Domain already in use',
                    'errors' => ['domain' => ['This domain is already registered']]
                ], 422);
            }

            DB::beginTransaction();

            try {
                // Generate API key
                $apiKey = 'cp_' . Str::random(40);

                $panel = ChildPanel::create([
                    'parent_user_id' => $user->id,
                    'domain' => $request->domain,
                    'panel_name' => $request->panel_name,
                    'admin_username' => $request->admin_username,
                    'admin_email' => $request->admin_email,
                    'admin_password' => $request->admin_password, // Will be hashed by model
                    'currency' => strtoupper($request->currency),
                    'price_per_month' => $request->price_per_month,
                    'markup_percentage' => $request->markup_percentage ?? 0,
                    'status' => 'pending',
                    'api_key' => $apiKey,
                    'balance' => 0,
                    'nameservers' => $request->nameservers ?? [],
                    'expires_at' => now()->addMonth(),
                    'next_payment_date' => now()->addMonth(),
                ]);

                DB::commit();

                // Send email notifications (can be implemented later)
                // Mail::to($user->email)->send(new ChildPanelCreatedMail($panel));
                // Mail::to($panel->admin_email)->send(new ChildPanelCredentialsMail($panel, $request->admin_password));

                return response()->json([
                    'data' => $panel,
                    'message' => 'Child panel created successfully'
                ], 201);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('ChildPanel store error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $user->id ?? null
            ]);
            return response()->json([
                'message' => 'Failed to create child panel',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $user = Auth::user();
            
            $panel = ChildPanel::where('id', $id)
                ->where('parent_user_id', $user->id)
                ->firstOrFail();

            $validator = Validator::make($request->all(), [
                'panel_name' => 'sometimes|string|max:255',
                'currency' => 'sometimes|string|in:USD,NGN,EUR,GBP,usd,ngn,eur,gbp',
                'price_per_month' => 'sometimes|numeric|min:0',
                'markup_percentage' => 'nullable|numeric|min:0|max:100',
                'status' => 'sometimes|string|in:pending,active,suspended,expired',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $panel->update($request->only([
                'panel_name',
                'currency',
                'price_per_month',
                'markup_percentage',
                'status'
            ]));

            return response()->json([
                'data' => $panel,
                'message' => 'Child panel updated successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('ChildPanel update error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to update child panel',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $user = Auth::user();
            
            $panel = ChildPanel::where('id', $id)
                ->where('parent_user_id', $user->id)
                ->firstOrFail();

            $panel->delete();

            return response()->json([
                'message' => 'Child panel deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('ChildPanel destroy error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to delete child panel',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function getStats($id)
    {
        try {
            $user = Auth::user();
            
            $panel = ChildPanel::where('id', $id)
                ->where('parent_user_id', $user->id)
                ->firstOrFail();

            $stats = $this->calculateStats($panel);

            return response()->json([
                'data' => $stats,
                'message' => 'Statistics retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('ChildPanel getStats error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to get statistics',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function getUsers($id, Request $request)
    {
        try {
            $user = Auth::user();
            
            $panel = ChildPanel::where('id', $id)
                ->where('parent_user_id', $user->id)
                ->firstOrFail();

            $query = $panel->users();

            if ($request->has('search')) {
                $query->where(function($q) use ($request) {
                    $q->where('username', 'like', '%' . $request->search . '%')
                      ->orWhere('email', 'like', '%' . $request->search . '%');
                });
            }

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            $users = $query->paginate($request->get('per_page', 20));

            return response()->json([
                'data' => $users->items(),
                'meta' => [
                    'current_page' => $users->currentPage(),
                    'last_page' => $users->lastPage(),
                    'per_page' => $users->perPage(),
                    'total' => $users->total(),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('ChildPanel getUsers error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to get users',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function getOrders($id, Request $request)
    {
        try {
            $user = Auth::user();
            
            $panel = ChildPanel::where('id', $id)
                ->where('parent_user_id', $user->id)
                ->firstOrFail();

            $query = $panel->orders();

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('search')) {
                $query->where('link', 'like', '%' . $request->search . '%');
            }

            $orders = $query->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 20));

            return response()->json([
                'data' => $orders->items(),
                'meta' => [
                    'current_page' => $orders->currentPage(),
                    'last_page' => $orders->lastPage(),
                    'per_page' => $orders->perPage(),
                    'total' => $orders->total(),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('ChildPanel getOrders error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to get orders',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function getTransactions($id, Request $request)
    {
        try {
            $user = Auth::user();
            
            $panel = ChildPanel::where('id', $id)
                ->where('parent_user_id', $user->id)
                ->firstOrFail();

            $query = $panel->transactions();

            if ($request->has('type')) {
                $query->where('type', $request->type);
            }

            $transactions = $query->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 20));

            return response()->json([
                'data' => $transactions->items(),
                'meta' => [
                    'current_page' => $transactions->currentPage(),
                    'last_page' => $transactions->lastPage(),
                    'per_page' => $transactions->perPage(),
                    'total' => $transactions->total(),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('ChildPanel getTransactions error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to get transactions',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function addBalance(Request $request, $id)
    {
        try {
            $user = Auth::user();
            
            $panel = ChildPanel::where('id', $id)
                ->where('parent_user_id', $user->id)
                ->firstOrFail();

            $validator = Validator::make($request->all(), [
                'amount' => 'required|numeric|min:0.01',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            try {
                $balanceBefore = $panel->balance;
                $panel->balance += $request->amount;
                $panel->save();

                ChildPanelTransaction::create([
                    'child_panel_id' => $panel->id,
                    'type' => 'deposit',
                    'amount' => $request->amount,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $panel->balance,
                    'description' => 'Balance added by parent user',
                    'status' => 'completed',
                ]);

                DB::commit();

                return response()->json([
                    'data' => $panel,
                    'message' => 'Balance added successfully'
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('ChildPanel addBalance error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to add balance',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function paySubscription(Request $request, $id)
    {
        try {
            $user = Auth::user();
            
            $panel = ChildPanel::where('id', $id)
                ->where('parent_user_id', $user->id)
                ->firstOrFail();

            // Check if user has sufficient balance
            if ($user->balance < $panel->price_per_month) {
                return response()->json([
                    'message' => 'Insufficient balance',
                    'errors' => ['balance' => ['You do not have sufficient balance to pay for this subscription']]
                ], 422);
            }

            DB::beginTransaction();

            try {
                // Deduct from user balance
                $user->balance -= $panel->price_per_month;
                $user->save();

                // Update panel subscription
                $periodStart = now();
                $periodEnd = now()->addMonth();

                $panel->last_payment_date = $periodStart;
                $panel->next_payment_date = $periodEnd;
                $panel->expires_at = $periodEnd;
                $panel->status = 'active';
                $panel->save();

                // Create subscription record
                ChildPanelSubscription::create([
                    'child_panel_id' => $panel->id,
                    'amount' => $panel->price_per_month,
                    'payment_method' => $request->payment_method ?? 'balance',
                    'transaction_id' => $request->transaction_id,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'status' => 'paid',
                ]);

                DB::commit();

                return response()->json([
                    'data' => $panel,
                    'message' => 'Subscription paid successfully'
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('ChildPanel paySubscription error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to pay subscription',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function generateApiKey($id)
    {
        try {
            $user = Auth::user();
            
            $panel = ChildPanel::where('id', $id)
                ->where('parent_user_id', $user->id)
                ->firstOrFail();

            $apiKey = 'cp_' . Str::random(40);
            $panel->api_key = $apiKey;
            $panel->save();

            return response()->json([
                'data' => ['api_key' => $apiKey],
                'message' => 'API key generated successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('ChildPanel generateApiKey error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to generate API key',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function toggleStatus(Request $request, $id)
    {
        try {
            $user = Auth::user();
            
            $panel = ChildPanel::where('id', $id)
                ->where('parent_user_id', $user->id)
                ->firstOrFail();

            $validator = Validator::make($request->all(), [
                'status' => 'required|string|in:active,suspended',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $panel->status = $request->status;
            $panel->save();

            return response()->json([
                'data' => $panel,
                'message' => 'Status updated successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('ChildPanel toggleStatus error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to update status',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    private function calculateStats($panel)
    {
        return [
            'total_users' => $panel->users()->count(),
            'total_orders' => $panel->orders()->count(),
            'total_revenue' => $panel->orders()->sum('price'),
            'total_profit' => $panel->orders()->sum('profit'),
            'balance' => $panel->balance,
        ];
    }
}

