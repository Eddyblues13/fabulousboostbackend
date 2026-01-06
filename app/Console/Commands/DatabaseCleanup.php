<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class DatabaseCleanup extends Command
{
    protected $signature = 'database:cleanup {--days=90 : Days to keep records}';
    protected $description = 'Clean up old database records and optimize tables';

    public function handle()
    {
        $this->info('🧹 Starting database cleanup...');
        
        $days = (int) $this->option('days');
        $cutoffDate = Carbon::now()->subDays($days);

        try {
            // Clean old read notifications (older than X days)
            $deletedNotifications = \App\Models\GeneralNotification::where('is_read', true)
                ->where('created_at', '<', $cutoffDate)
                ->delete();
                
            $this->line("  ✓ Deleted {$deletedNotifications} old read notifications");

            // Clean old failed orders (optional - keep for audit)
            // Uncomment if you want to clean failed orders
            /*
            $deletedFailedOrders = \App\Models\Order::where('status', 'failed')
                ->where('created_at', '<', $cutoffDate)
                ->delete();
            $this->line("  ✓ Deleted {$deletedFailedOrders} old failed orders");
            */

            // Optimize tables (MySQL specific)
            try {
                DB::statement('OPTIMIZE TABLE general_notifications');
                $this->line('  ✓ Optimized general_notifications table');
            } catch (\Exception $e) {
                // Ignore if OPTIMIZE is not supported
            }

            // Update statistics
            try {
                DB::statement('ANALYZE TABLE orders, general_notifications, transactions');
                $this->line('  ✓ Updated table statistics');
            } catch (\Exception $e) {
                // Ignore if ANALYZE is not supported
            }

            $this->info("✅ Database cleanup completed successfully");
            return 0;
            
        } catch (\Exception $e) {
            Log::error('Database cleanup error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->error("✗ Cleanup failed: {$e->getMessage()}");
            return 1;
        }
    }
}

