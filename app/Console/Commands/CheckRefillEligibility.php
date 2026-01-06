<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Service;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Models\ApiProvider;
use Carbon\Carbon;

class CheckRefillEligibility extends Command
{
    protected $signature = 'orders:check-refills {--dry-run : Run without making changes}';
    protected $description = 'Check if completed orders need refills and process them';

    public function handle()
    {
        $this->info('🔄 Checking refill eligibility...');
        
        $dryRun = $this->option('dry-run');
        
        if ($dryRun) {
            $this->warn('⚠ DRY RUN MODE - No changes will be made');
        }

        // Get completed orders with refill enabled services
        $orders = Order::where('status', 'completed')
            ->whereHas('service', function($q) {
                $q->where('refill', true);
            })
            ->where(function($q) {
                $q->whereNull('refill_status')
                  ->orWhere('refill_status', '!=', 'pending')
                  ->orWhere('refill_status', '!=', 'processing');
            })
            ->whereNotNull('api_order_id')
            ->with(['service'])
            ->get();

        if ($orders->isEmpty()) {
            $this->info('✅ No orders eligible for refill check.');
            return 0;
        }

        $this->info("📦 Found {$orders->count()} orders to check for refills");

        $refillRequested = 0;
        $noRefillNeeded = 0;
        $failed = 0;

        foreach ($orders as $order) {
            try {
                // Check if order is within guarantee period (default 30 days)
                $guaranteeDays = 30; // You can make this configurable
                $orderAge = Carbon::parse($order->created_at)->diffInDays(Carbon::now());
                
                if ($orderAge > $guaranteeDays) {
                    continue; // Order is outside guarantee period
                }

                // Check current count from provider
                $currentCount = $this->getCurrentCount($order);
                
                if ($currentCount === null) {
                    $this->warn("  ⚠ Could not get current count for order #{$order->id}");
                    continue;
                }

                // Calculate drop percentage
                $orderedQuantity = $order->quantity ?? 0;
                if ($orderedQuantity == 0) {
                    continue;
                }

                $dropCount = $orderedQuantity - $currentCount;
                $dropPercentage = ($dropCount / $orderedQuantity) * 100;

                // If dropped more than 10% or more than 50 units, request refill
                $refillThreshold = max(10, 50); // 10% or 50 units, whichever is higher
                
                if ($dropCount >= 50 || $dropPercentage >= 10) {
                    $this->line("  📉 Order #{$order->id}: Dropped {$dropCount} ({$dropPercentage}%) - Requesting refill");
                    
                    if (!$dryRun) {
                        $success = $this->requestRefill($order, $dropCount);
                        
                        if ($success) {
                            $refillRequested++;
                        } else {
                            $failed++;
                        }
                    } else {
                        $refillRequested++;
                    }
                } else {
                    $noRefillNeeded++;
                }
                
            } catch (\Exception $e) {
                $failed++;
                Log::error("Error checking refill for order #{$order->id}", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                $this->error("  ✗ Failed to check order #{$order->id}: {$e->getMessage()}");
            }
        }

        $this->info("✅ Refills requested: {$refillRequested} | No refill needed: {$noRefillNeeded} | Failed: {$failed}");
        return 0;
    }

    private function getCurrentCount(Order $order)
    {
        $provider = ApiProvider::where('status', 1)->first();
        
        if (!$provider) {
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
                // Return the current count (remains or start_count based on provider)
                return $data['start_count'] ?? $data['start_counter'] ?? null;
            }

            return null;
        } catch (\Exception $e) {
            Log::error("Error getting current count for order #{$order->id}", [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function requestRefill(Order $order, $dropCount)
    {
        try {
            $provider = ApiProvider::where('status', 1)->first();
            
            if (!$provider) {
                $this->error('  ✗ No active provider found');
                return false;
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $provider->api_key,
                'Accept' => 'application/json',
            ])
                ->timeout(30)
                ->post($provider->url . '/order/refill', [
                    'order_id' => $order->api_order_id
                ]);

            if ($response->successful()) {
                $data = $response->json();
                
                $order->refill_status = 'pending';
                $order->api_refill_id = $data['refill_id'] ?? $data['order_id'] ?? null;
                $order->refilled_at = Carbon::now();
                $order->save();

                // Create notification
                \App\Models\GeneralNotification::create([
                    'user_id' => $order->user_id,
                    'type' => 'refill',
                    'title' => 'Refill Requested',
                    'message' => "A refill has been requested for order #{$order->id} ({$dropCount} units)",
                    'is_read' => false
                ]);

                $this->line("  ✓ Refill requested for order #{$order->id}");
                return true;
            }

            $this->error("  ✗ Provider rejected refill request for order #{$order->id}");
            return false;
            
        } catch (\Exception $e) {
            Log::error("Error requesting refill for order #{$order->id}", [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}

