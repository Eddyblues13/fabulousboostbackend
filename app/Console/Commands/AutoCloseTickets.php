<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;
use App\Mail\TicketReplyUserMail;

class AutoCloseTickets extends Command
{
    protected $signature = 'tickets:auto-close';
    protected $description = 'Automatically close tickets that have been inactive for 2 days or more';

    public function handle()
    {
        $this->info('Checking for inactive tickets to auto-close...');

        // Find tickets that:
        // 1. Are not already closed (status !== 3)
        // 2. Have not been updated or replied to in the last 2 days
        $twoDaysAgo = Carbon::now()->subDays(2);

        $ticketsToClose = Ticket::where('status', '!=', 3) // Not already closed
            ->where(function ($query) use ($twoDaysAgo) {
                $query->where('last_reply', '<', $twoDaysAgo)
                    ->orWhere(function ($q) use ($twoDaysAgo) {
                        $q->whereNull('last_reply')
                            ->where('updated_at', '<', $twoDaysAgo);
                    });
            })
            ->get();

        if ($ticketsToClose->isEmpty()) {
            $this->info('No tickets to auto-close.');
            return Command::SUCCESS;
        }

        $closedCount = 0;
        foreach ($ticketsToClose as $ticket) {
            try {
                $oldStatus = $ticket->status;
                $ticket->status = 3; // Closed
                $ticket->save();

                // Optionally send email notification to user
                if ($ticket->user && $ticket->user->email) {
                    try {
                        // Get the latest reply or create a notification
                        $latestReply = TicketReply::where('ticket_id', $ticket->id)
                            ->whereNotNull('admin_id')
                            ->where('is_internal', false)
                            ->latest()
                            ->first();

                        if (!$latestReply) {
                            $latestReply = new \stdClass();
                            $latestReply->message = "Your ticket has been automatically closed due to inactivity (no activity for 2 days). If you need further assistance, please create a new ticket.";
                            $latestReply->created_at = now();
                        }

                        Mail::to($ticket->user->email)->send(
                            new TicketReplyUserMail($ticket, $latestReply, null)
                        );
                    } catch (\Exception $e) {
                        Log::warning("Failed to send auto-close email for ticket #{$ticket->id}: " . $e->getMessage());
                    }
                }

                $closedCount++;
                $this->info("Auto-closed ticket #{$ticket->id}");
            } catch (\Exception $e) {
                Log::error("Failed to auto-close ticket #{$ticket->id}: " . $e->getMessage());
                $this->error("Failed to close ticket #{$ticket->id}: " . $e->getMessage());
            }
        }

        $this->info("Successfully auto-closed {$closedCount} ticket(s).");
        return Command::SUCCESS;
    }
}

