<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\ApiProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CheckOrderStatus extends Command
{
    protected $signature = 'orders:check-status {--limit=100 : Number of orders to check}';
    protected $description = 'Check and update order status from provider API';

    public function handle()
    {
        $this->info('🔍 Checking order statuses...');
        
        $limit = (int) $this->option('limit');
        
        // Get all pending/in-progress/partial orders with API order IDs
        $orders = Order::whereIn('status', ['processing', 'in-progress', 'partial'])
            ->whereNotNull('api_order_id')
            ->with(['service.provider'])
            ->limit($limit)
            ->get();

        if ($orders->isEmpty()) {
            $this->info('✅ No orders to check.');
            return 0;
        }

        $this->info("📦 Found {$orders->count()} orders to check");

        $updated = 0;
        $failed = 0;

        foreach ($orders as $order) {
            try {
                $status = $this->checkProviderStatus($order);
                
                if ($status) {
                    $oldStatus = $order->status;
                    
                    $order->status = $status['status'] ?? $order->status;
                    $order->remains = $status['remains'] ?? $order->remains;
                    $order->start_counter = $status['start_count'] ?? $order->start_counter;
                    
                    if (isset($status['status_description'])) {
                        $order->status_description = $status['status_description'];
                    }
                    
                    // If completed, set remains to 0
                    if ($order->status === 'completed') {
                        $order->remains = 0;
                    }
                    
                    $order->save();
                    
                    if ($oldStatus !== $order->status) {
                        $updated++;
                        $this->line("  ✓ Order #{$order->id}: {$oldStatus} → {$order->status}");
                        
                        // Create notification for user
                        if ($order->user_id && $order->status === 'completed') {
                            $this->createNotification($order, 'Order completed', "Your order #{$order->id} has been completed successfully!");
                        }
                    }
                }
            } catch (\Exception $e) {
                $failed++;
                Log::error("Error checking order #{$order->id}", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                $this->error("  ✗ Failed to check order #{$order->id}: {$e->getMessage()}");
            }
        }

        $this->info("✅ Updated: {$updated} orders | Failed: {$failed}");
        return 0;
    }

    private function checkProviderStatus(Order $order)
    {
        $provider = ApiProvider::where('status', 1)->first();
        
        if (!$provider) {
            $this->warn('  ⚠ No active provider found');
            return null;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $provider->api_key,
                'Accept' => 'application/json',
            ])
                ->timeout(30)
                ->post($provider->url . '/order/status', [
                    'order_id' => $order->api_order_id
                ]);

            if ($response->successful()) {
                $data = $response->json();
                
                // Map provider response to our status format
                $status = $this->mapProviderStatus($data['status'] ?? 'processing');
                
                return [
                    'status' => $status,
                    'remains' => $data['remains'] ?? $order->remains,
                    'start_count' => $data['start_count'] ?? $data['start_counter'] ?? $order->start_counter,
                    'status_description' => $data['status_description'] ?? $data['status'] ?? null
                ];
            }

            Log::warning("Provider API returned error for order #{$order->id}", [
                'status' => $response->status(),
                'response' => $response->body()
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error("Provider API exception for order #{$order->id}", [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function mapProviderStatus($providerStatus)
    {
        $statusMap = [
            'pending' => 'processing',
            'processing' => 'processing',
            'in_progress' => 'in-progress',
            'in-progress' => 'in-progress',
            'partial' => 'partial',
            'completed' => 'completed',
            'canceled' => 'cancelled',
            'cancelled' => 'cancelled',
            'refunded' => 'refunded',
            'failed' => 'failed'
        ];

        $status = strtolower($providerStatus);
        return $statusMap[$status] ?? 'processing';
    }

    private function createNotification(Order $order, $title, $message)
    {
        try {
            \App\Models\GeneralNotification::create([
                'user_id' => $order->user_id,
                'type' => 'order',
                'title' => $title,
                'message' => $message,
                'is_read' => false
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to create notification for order #{$order->id}", [
                'error' => $e->getMessage()
            ]);
        }
    }
}

