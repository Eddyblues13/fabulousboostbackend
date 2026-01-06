<?php

namespace App\Console\Commands;

use App\Models\GeneralNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendPendingNotifications extends Command
{
    protected $signature = 'notifications:send {--limit=50 : Number of notifications to send}';
    protected $description = 'Send pending email notifications to users';

    public function handle()
    {
        $this->info('📧 Sending pending notifications...');
        
        $limit = (int) $this->option('limit');
        
        // This command mainly processes notifications that need email sending
        // For now, we'll just ensure notifications are created properly
        // You can extend this to send actual emails if needed
        
        $notifications = GeneralNotification::where('is_read', false)
            ->whereNull('sent_at') // If you add this column
            ->limit($limit)
            ->with('user')
            ->get();

        if ($notifications->isEmpty()) {
            $this->info('✅ No pending notifications.');
            return 0;
        }

        $this->info("📧 Found {$notifications->count()} notifications to process");

        $sent = 0;
        $failed = 0;

        foreach ($notifications as $notification) {
            try {
                if ($notification->user && $notification->user->email) {
                    // Send email notification if configured
                    // Mail::to($notification->user->email)->send(new UserNotification($notification));
                    
                    // For now, just mark as processed
                    // You can uncomment the Mail line above when email is configured
                    $sent++;
                }
            } catch (\Exception $e) {
                $failed++;
                Log::error("Error sending notification #{$notification->id}", [
                    'error' => $e->getMessage()
                ]);
                $this->error("  ✗ Failed to send notification #{$notification->id}");
            }
        }

        $this->info("✅ Processed: {$sent} notifications | Failed: {$failed}");
        return 0;
    }
}

