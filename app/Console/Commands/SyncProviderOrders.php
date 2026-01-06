<?php

namespace App\Console\Commands;

use App\Models\ApiProvider;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncProviderOrders extends Command
{
    protected $signature = 'provider:sync-orders';
    protected $description = 'Sync order statuses with provider API in batch';

    public function handle()
    {
        $this->info('🔄 Syncing orders with provider...');
        
        $provider = ApiProvider::where('status', 1)->first();
        
        if (!$provider) {
            $this->warn('⚠ No active provider found');
            return 0;
        }

        // Get orders that need syncing (pending, in-progress, partial)
        $orders = Order::whereIn('status', ['processing', 'in-progress', 'partial'])
            ->whereNotNull('api_order_id')
            ->limit(50) // Process in batches
            ->pluck('api_order_id')
            ->toArray();

        if (empty($orders)) {
            $this->info('✅ No orders to sync.');
            return 0;
        }

        try {
            // Call provider's multi-status endpoint if available
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $provider->api_key,
                'Accept' => 'application/json',
            ])
                ->timeout(60)
                ->post($provider->url . '/orders/status', [
                    'order_ids' => implode(',', $orders)
                ]);

            if ($response->successful()) {
                $statuses = $response->json();
                
                $updated = 0;
                foreach ($statuses as $apiOrderId => $statusData) {
                    $order = Order::where('api_order_id', $apiOrderId)->first();
                    
                    if ($order) {
                        $oldStatus = $order->status;
                        $order->status = $this->mapStatus($statusData['status'] ?? $order->status);
                        $order->remains = $statusData['remains'] ?? $order->remains;
                        $order->start_counter = $statusData['start_count'] ?? $statusData['start_counter'] ?? $order->start_counter;
                        
                        if ($oldStatus !== $order->status) {
                            $order->status_description = $statusData['status_description'] ?? $statusData['status'] ?? null;
                        }
                        
                        if ($order->status === 'completed') {
                            $order->remains = 0;
                        }
                        
                        $order->save();
                        
                        if ($oldStatus !== $order->status) {
                            $updated++;
                        }
                    }
                }

                $this->info("✅ Synced {$updated} orders");
                return 0;
            } else {
                // Fallback to individual checks if batch not supported
                $this->warn('⚠ Batch sync not supported, use orders:check-status instead');
                return 0;
            }
            
        } catch (\Exception $e) {
            Log::error('Provider sync error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->error("✗ Sync failed: {$e->getMessage()}");
            return 1;
        }
    }

    private function mapStatus($status)
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

        return $statusMap[strtolower($status)] ?? 'processing';
    }
}

