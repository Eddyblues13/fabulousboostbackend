<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ProcessRefunds extends Command
{
    protected $signature = 'orders:process-refunds {--dry-run : Run without making changes}';
    protected $description = 'Process refunds for failed and cancelled orders';

    public function handle()
    {
        $this->info('💰 Processing refunds...');
        
        $dryRun = $this->option('dry-run');
        
        if ($dryRun) {
            $this->warn('⚠ DRY RUN MODE - No changes will be made');
        }

        // Get failed/cancelled orders that haven't been refunded
        $orders = Order::whereIn('status', ['failed', 'cancelled'])
            ->where('status', '!=', 'refunded')
            ->whereNotNull('price')
            ->where('price', '>', 0)
            ->whereDoesntHave('user', function($q) {
                // Exclude already refunded (check if user balance was adjusted)
            })
            ->with('user')
            ->get();

        if ($orders->isEmpty()) {
            $this->info('✅ No orders to refund.');
            return 0;
        }

        $this->info("📦 Found {$orders->count()} orders to refund");

        $refunded = 0;
        $failed = 0;

        DB::beginTransaction();
        
        try {
            foreach ($orders as $order) {
                try {
                    if (!$order->user) {
                        $this->warn("  ⚠ Order #{$order->id} has no user, skipping");
                        continue;
                    }

                    $refundAmount = $order->price ?? 0;
                    
                    if ($refundAmount <= 0) {
                        continue;
                    }

                    $this->line("  💵 Refunding Order #{$order->id}: {$order->user->username} - {$refundAmount}");

                    if (!$dryRun) {
                        // Update order status
                        $order->status = 'refunded';
                        $order->status_description = 'Automatically refunded by system';
                        $order->save();

                        // Refund to user balance
                        $order->user->increment('balance', $refundAmount);

                        // Create transaction record
                        \App\Models\Transaction::create([
                            'user_id' => $order->user_id,
                            'transaction_type' => 'refund',
                            'amount' => $refundAmount,
                            'status' => 'completed',
                            'description' => "Refund for order #{$order->id}",
                            'transaction_id' => 'ORDER_REFUND_' . $order->id,
                        ]);

                        // Create notification
                        \App\Models\GeneralNotification::create([
                            'user_id' => $order->user_id,
                            'type' => 'refund',
                            'title' => 'Order Refunded',
                            'message' => "Your order #{$order->id} has been refunded. Amount: {$refundAmount} has been added to your balance.",
                            'is_read' => false
                        ]);
                    }

                    $refunded++;
                    
                } catch (\Exception $e) {
                    $failed++;
                    Log::error("Error processing refund for order #{$order->id}", [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    $this->error("  ✗ Failed to refund order #{$order->id}: {$e->getMessage()}");
                }
            }

            if (!$dryRun) {
                DB::commit();
            } else {
                DB::rollBack();
            }

            $this->info("✅ Refunded: {$refunded} orders | Failed: {$failed}");
            return 0;
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error processing refunds', [
                'error' => $e->getMessage()
            ]);
            $this->error("✗ Refund process failed: {$e->getMessage()}");
            return 1;
        }
    }
}

