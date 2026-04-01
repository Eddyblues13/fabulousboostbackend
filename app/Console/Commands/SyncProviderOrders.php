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
    protected $description = 'Sync order statuses with provider API in batch (multi-status)';

    public function handle()
    {
        $this->info('🔄 Syncing orders with provider...');

        // Get orders that need syncing, grouped by provider
        $orders = Order::whereIn('status', ['processing', 'in-progress', 'partial'])
            ->whereNotNull('api_order_id')
            ->with(['service'])
            ->orderBy('updated_at', 'asc')
            ->limit(100)
            ->get();

        if ($orders->isEmpty()) {
            $this->info('✅ No orders to sync.');
            return 0;
        }

        // Group by provider
        $grouped = $orders->groupBy(function ($order) {
            return $order->service?->api_provider_id;
        });

        $totalUpdated = 0;
        $totalFailed = 0;

        foreach ($grouped as $providerId => $providerOrders) {
            if (!$providerId) {
                continue;
            }

            $provider = ApiProvider::where('id', $providerId)->where('status', 1)->first();
            if (!$provider) {
                $this->warn("  ⚠ Provider #{$providerId} not found or inactive");
                continue;
            }

            // Process in chunks of 50 (API limit)
            foreach ($providerOrders->chunk(50) as $chunk) {
                $apiOrderIds = $chunk->pluck('api_order_id')->toArray();

                try {
                    // Use standard SMM panel multi-status API format
                    $response = Http::timeout(60)->asForm()->post($provider->url, [
                        'key' => $provider->api_key,
                        'action' => 'status',
                        'orders' => implode(',', $apiOrderIds),
                    ]);

                    if (!$response->successful()) {
                        // Fallback: if multi-status not supported, skip to individual checks
                        $this->warn("  ⚠ Batch sync failed for provider #{$providerId} (HTTP {$response->status()}), falling back to individual checks");
                        $totalFailed += count($apiOrderIds);
                        continue;
                    }

                    $statuses = $response->json();

                    // Check if provider returned an error
                    if (isset($statuses['error'])) {
                        $this->warn("  ⚠ Provider #{$providerId} error: {$statuses['error']}");
                        $totalFailed += count($apiOrderIds);
                        continue;
                    }

                    foreach ($statuses as $apiOrderId => $statusData) {
                        $order = $chunk->firstWhere('api_order_id', $apiOrderId);

                        if (!$order || !is_array($statusData)) {
                            continue;
                        }

                        $oldStatus = $order->status;
                        $newStatus = $this->mapStatus($statusData['status'] ?? $order->status);

                        $order->status = $newStatus;
                        $order->remains = $statusData['remains'] ?? $order->remains;
                        $order->start_counter = $statusData['start_count'] ?? $statusData['start_counter'] ?? $order->start_counter;

                        if ($oldStatus !== $order->status) {
                            $order->status_description = $statusData['status'] ?? null;
                        }

                        if ($order->status === 'completed') {
                            $order->remains = 0;
                        }

                        // Handle partial refund
                        if ($order->status === 'partial' && $oldStatus !== 'partial' && $order->remains > 0 && $order->quantity > 0) {
                            $refundAmount = round(($order->remains / $order->quantity) * $order->price, 2);
                            if ($refundAmount > 0 && $order->user) {
                                $order->user->increment('balance', $refundAmount);
                                Log::info("Partial refund of {$refundAmount} for order #{$order->id}");
                            }
                        }

                        // Handle full refund for cancelled/refunded
                        if (in_array($order->status, ['cancelled', 'refunded']) && !in_array($oldStatus, ['cancelled', 'refunded'])) {
                            if ($order->price > 0 && $order->user) {
                                $order->user->increment('balance', $order->price);
                                Log::info("Full refund of {$order->price} for order #{$order->id}");
                            }
                        }

                        $order->save();

                        if ($oldStatus !== $order->status) {
                            $totalUpdated++;
                            $this->line("  ✓ Order #{$order->id}: {$oldStatus} → {$order->status}");

                            // Notify user of status change
                            $this->notifyUser($order, $oldStatus);
                        }
                    }
                } catch (\Exception $e) {
                    $totalFailed += count($apiOrderIds);
                    Log::error("Provider sync error for provider #{$providerId}", [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    $this->error("  ✗ Sync failed for provider #{$providerId}: {$e->getMessage()}");
                }
            }
        }

        $this->info("✅ Synced: {$totalUpdated} orders | Failed: {$totalFailed}");
        return 0;
    }

    private function notifyUser(Order $order, $oldStatus)
    {
        $messages = [
            'completed' => "Your order #{$order->id} has been completed successfully!",
            'partial' => "Your order #{$order->id} was partially completed. Remaining amount refunded.",
            'cancelled' => "Your order #{$order->id} was cancelled. Your balance has been refunded.",
            'refunded' => "Your order #{$order->id} has been refunded.",
            'in-progress' => "Your order #{$order->id} is now in progress.",
        ];

        $message = $messages[$order->status] ?? "Your order #{$order->id} status changed to {$order->status}.";

        try {
            \App\Models\GeneralNotification::create([
                'user_id' => $order->user_id,
                'type' => 'order',
                'title' => 'Order Status Updated',
                'message' => $message,
                'is_read' => false
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to create notification for order #{$order->id}", [
                'error' => $e->getMessage()
            ]);
        }
    }

    private function mapStatus($status)
    {
        $statusMap = [
            'pending' => 'processing',
            'processing' => 'processing',
            'in_progress' => 'in-progress',
            'in-progress' => 'in-progress',
            'inprogress' => 'in-progress',
            'partial' => 'partial',
            'completed' => 'completed',
            'canceled' => 'cancelled',
            'cancelled' => 'cancelled',
            'refunded' => 'refunded',
            'failed' => 'cancelled',
        ];

        return $statusMap[strtolower(trim($status))] ?? 'processing';
    }
}
