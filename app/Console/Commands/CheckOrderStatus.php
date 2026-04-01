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
            ->orderBy('updated_at', 'asc')
            ->limit($limit)
            ->get();

        if ($orders->isEmpty()) {
            $this->info('✅ No orders to check.');
            return 0;
        }

        $this->info("📦 Found {$orders->count()} orders to check");

        // Group orders by provider for efficient API calls
        $grouped = $orders->groupBy(function ($order) {
            return $order->service?->api_provider_id;
        });

        $updated = 0;
        $failed = 0;
        $refunded = 0;

        foreach ($grouped as $providerId => $providerOrders) {
            if (!$providerId) {
                $this->warn("  ⚠ Skipping orders without provider");
                continue;
            }

            $provider = ApiProvider::where('id', $providerId)->where('status', 1)->first();
            if (!$provider) {
                $this->warn("  ⚠ Provider #{$providerId} not found or inactive");
                continue;
            }

            foreach ($providerOrders as $order) {
                try {
                    $status = $this->checkProviderStatus($order, $provider);

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

                        // Handle partial: refund the remaining amount
                        if ($order->status === 'partial' && $oldStatus !== 'partial' && $order->remains > 0) {
                            $this->processPartialRefund($order);
                            $refunded++;
                        }

                        // Handle cancelled/refunded from provider side
                        if (in_array($order->status, ['cancelled', 'refunded']) && !in_array($oldStatus, ['cancelled', 'refunded'])) {
                            $this->processFullRefund($order);
                            $refunded++;
                        }

                        $order->save();

                        if ($oldStatus !== $order->status) {
                            $updated++;
                            $this->line("  ✓ Order #{$order->id}: {$oldStatus} → {$order->status}");

                            // Create notification for status change
                            $this->notifyStatusChange($order, $oldStatus);
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
        }

        $this->info("✅ Updated: {$updated} | Refunded: {$refunded} | Failed: {$failed}");
        return 0;
    }

    private function checkProviderStatus(Order $order, ApiProvider $provider)
    {
        try {
            // Use standard SMM panel API format
            $response = Http::timeout(30)->asForm()->post($provider->url, [
                'key' => $provider->api_key,
                'action' => 'status',
                'order' => $order->api_order_id,
            ]);

            if ($response->successful()) {
                $data = $response->json();

                // Check for error response from provider
                if (isset($data['error'])) {
                    Log::warning("Provider returned error for order #{$order->id}: {$data['error']}");
                    return null;
                }

                // Map provider response to our status format
                $status = $this->mapProviderStatus($data['status'] ?? 'processing');

                return [
                    'status' => $status,
                    'remains' => $data['remains'] ?? $order->remains,
                    'start_count' => $data['start_count'] ?? $data['start_counter'] ?? $order->start_counter,
                    'status_description' => $data['status'] ?? null,
                ];
            }

            Log::warning("Provider API returned HTTP error for order #{$order->id}", [
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

    private function processPartialRefund(Order $order)
    {
        if (!$order->remains || $order->remains <= 0 || !$order->quantity) {
            return;
        }

        $refundAmount = round(($order->remains / $order->quantity) * $order->price, 2);
        if ($refundAmount > 0 && $order->user) {
            $order->user->increment('balance', $refundAmount);
            Log::info("Partial refund of {$refundAmount} for order #{$order->id} (remains: {$order->remains})");
        }
    }

    private function processFullRefund(Order $order)
    {
        if ($order->price > 0 && $order->user) {
            $order->user->increment('balance', $order->price);
            Log::info("Full refund of {$order->price} for cancelled/refunded order #{$order->id}");
        }
    }

    private function notifyStatusChange(Order $order, $oldStatus)
    {
        $messages = [
            'completed' => "Your order #{$order->id} has been completed successfully!",
            'partial' => "Your order #{$order->id} was partially completed. Remaining amount has been refunded.",
            'cancelled' => "Your order #{$order->id} was cancelled. Your balance has been refunded.",
            'refunded' => "Your order #{$order->id} has been refunded.",
            'in-progress' => "Your order #{$order->id} is now in progress.",
        ];

        $message = $messages[$order->status] ?? "Your order #{$order->id} status changed from {$oldStatus} to {$order->status}.";
        $title = match ($order->status) {
            'completed' => 'Order Completed',
            'partial' => 'Order Partially Completed',
            'cancelled' => 'Order Cancelled',
            'refunded' => 'Order Refunded',
            'in-progress' => 'Order In Progress',
            default => 'Order Status Updated',
        };

        $this->createNotification($order, $title, $message);
    }

    private function mapProviderStatus($providerStatus)
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

        $status = strtolower(trim($providerStatus));
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
