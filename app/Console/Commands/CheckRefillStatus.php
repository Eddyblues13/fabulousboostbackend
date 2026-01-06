<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\ApiProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CheckRefillStatus extends Command
{
    protected $signature = 'orders:check-refill-status';
    protected $description = 'Check status of pending refills';

    public function handle()
    {
        $this->info('🔄 Checking refill statuses...');
        
        // Get pending/processing refills
        $orders = Order::whereIn('refill_status', ['pending', 'processing'])
            ->whereNotNull('api_refill_id')
            ->with(['service'])
            ->get();

        if ($orders->isEmpty()) {
            $this->info('✅ No refills to check.');
            return 0;
        }

        $this->info("📦 Found {$orders->count()} refills to check");

        $completed = 0;
        $stillProcessing = 0;
        $failed = 0;

        $provider = ApiProvider::where('status', 1)->first();
        
        if (!$provider) {
            $this->warn('⚠ No active provider found');
            return 0;
        }

        foreach ($orders as $order) {
            try {
                $refillStatus = $this->checkProviderRefillStatus($provider, $order);
                
                if ($refillStatus === 'completed') {
                    $order->refill_status = 'completed';
                    $order->refilled_at = Carbon::now();
                    $order->save();
                    
                    $this->line("  ✓ Refill completed for order #{$order->id}");
                    $completed++;
                    
                    // Create notification
                    \App\Models\GeneralNotification::create([
                        'user_id' => $order->user_id,
                        'type' => 'refill',
                        'title' => 'Refill Completed',
                        'message' => "The refill for order #{$order->id} has been completed successfully!",
                        'is_read' => false
                    ]);
                    
                } elseif ($refillStatus === 'processing') {
                    $order->refill_status = 'processing';
                    $order->save();
                    $stillProcessing++;
                } elseif ($refillStatus === 'failed') {
                    $order->refill_status = 'failed';
                    $order->save();
                    $failed++;
                    
                    $this->line("  ✗ Refill failed for order #{$order->id}");
                }
                
            } catch (\Exception $e) {
                $failed++;
                Log::error("Error checking refill status for order #{$order->id}", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                $this->error("  ✗ Failed to check refill #{$order->id}: {$e->getMessage()}");
            }
        }

        $this->info("✅ Completed: {$completed} | Processing: {$stillProcessing} | Failed: {$failed}");
        return 0;
    }

    private function checkProviderRefillStatus($provider, Order $order)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $provider->api_key,
                'Accept' => 'application/json',
            ])
                ->timeout(30)
                ->post($provider->url . '/order/refill/status', [
                    'refill_id' => $order->api_refill_id
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $status = strtolower($data['status'] ?? 'processing');
                
                if (in_array($status, ['completed', 'complete', 'done'])) {
                    return 'completed';
                } elseif (in_array($status, ['failed', 'canceled', 'cancelled'])) {
                    return 'failed';
                } else {
                    return 'processing';
                }
            }

            return 'processing'; // Default to processing if API call fails
            
        } catch (\Exception $e) {
            Log::error("Provider API exception for refill #{$order->api_refill_id}", [
                'error' => $e->getMessage()
            ]);
            return 'processing';
        }
    }
}

