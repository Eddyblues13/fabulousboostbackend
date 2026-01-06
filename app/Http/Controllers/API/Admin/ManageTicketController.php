<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use App\Mail\TicketReplyUserMail;

class ManageTicketController extends Controller
{
    // List all tickets with filtering
    public function index(Request $request)
    {
        try {
            $query = Ticket::with(['user', 'replies'])->latest();

            // Filter by status
            if ($request->has('status') && $request->status !== 'all') {
                $query->where('status', $request->status);
            }

            // Filter by priority
            if ($request->has('priority') && $request->priority !== 'all') {
                $query->where('priority', $request->priority);
            }

            // Search
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('subject', 'like', "%{$search}%")
                        ->orWhere('id', 'like', "%{$search}%")
                        ->orWhereHas('user', function($q) use ($search) {
                            $q->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            }

            $tickets = $query->get();

            return response()->json([
                'status' => 'success',
                'tickets' => $tickets,
            ]);
        } catch (\Exception $e) {
            Log::error('ManageTicketController index error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch tickets'
            ], 500);
        }
    }

    // Show a specific ticket with all replies
    public function show($id)
    {
        try {
            $ticket = Ticket::with(['user', 'replies.user', 'replies.admin'])
                ->findOrFail($id);

            return response()->json([
                'status' => 'success',
                'ticket' => $ticket,
            ]);
        } catch (\Exception $e) {
            Log::error('ManageTicketController show error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Ticket not found'
            ], 404);
        }
    }

    // Update ticket status
    public function updateStatus(Request $request, $id)
    {
        try {
            $request->validate([
                'status' => 'required|in:0,1,2,3', // 0=Pending, 1=Answered, 2=In Progress, 3=Closed
            ]);

            $ticket = Ticket::with('user')->findOrFail($id);
            $oldStatus = $ticket->status;
            $ticket->status = $request->status;
            
            // If reopening a closed ticket, update last_reply to prevent immediate auto-close
            if ($oldStatus === 3 && $request->status !== 3) {
                $ticket->last_reply = now();
            }
            
            $ticket->updated_at = now();
            $ticket->save();

            // Send email notification if status changed to closed (resolved)
            if ($oldStatus !== $request->status && $request->status === 3 && $ticket->user && $ticket->user->email) {
                try {
                    // Get the latest admin reply or create a notification message
                    $latestReply = TicketReply::where('ticket_id', $ticket->id)
                        ->whereNotNull('admin_id')
                        ->where('is_internal', false)
                        ->latest()
                        ->first();
                    
                    if (!$latestReply) {
                        // If no reply exists, create a temporary one for email
                        $latestReply = (object)[
                            'message' => "Your ticket has been marked as resolved. If you have any further questions, please feel free to create a new ticket.",
                            'created_at' => now(),
                        ];
                    }
                    
                    Mail::to($ticket->user->email)->send(new TicketReplyUserMail($ticket, $latestReply, Auth::user()));
                } catch (\Exception $e) {
                    Log::warning('Failed to send ticket closed email: ' . $e->getMessage());
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Ticket status updated successfully',
                'ticket' => $ticket,
            ]);
        } catch (\Exception $e) {
            Log::error('ManageTicketController updateStatus error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update ticket status'
            ], 500);
        }
    }

    // Update ticket priority
    public function updatePriority(Request $request, $id)
    {
        try {
            $request->validate([
                'priority' => 'required|in:1,2,3', // 1=Low, 2=Medium, 3=High
            ]);

            $ticket = Ticket::findOrFail($id);
            $ticket->priority = $request->priority;
            $ticket->save();

            return response()->json([
                'status' => 'success',
                'message' => 'Ticket priority updated successfully',
                'ticket' => $ticket,
            ]);
        } catch (\Exception $e) {
            Log::error('ManageTicketController updatePriority error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update ticket priority'
            ], 500);
        }
    }

    // Add reply to ticket (admin)
    public function reply(Request $request, $id)
    {
        try {
            $request->validate([
                'message' => 'required|string|min:5',
                'is_internal' => 'sometimes|boolean',
            ]);

            $admin = Auth::user();
            $ticket = Ticket::with('user')->findOrFail($id);

            // If ticket is closed, automatically reopen it when admin replies
            // This allows conversation to continue until ticket is manually closed
            $wasClosed = $ticket->status === 3;
            if ($wasClosed) {
                $ticket->status = 1; // Reopen as answered
            }

            $reply = TicketReply::create([
                'ticket_id' => $ticket->id,
                'user_id' => null,
                'admin_id' => $admin->id,
                'message' => $request->message,
                'is_internal' => $request->is_internal ?? false,
            ]);

            // Update ticket status and last_reply
            if (!$request->is_internal) {
                // Ticket stays open until manually closed - allows conversation to continue
                if ($wasClosed) {
                    $ticket->status = 1; // Reopen if it was closed
                } else {
                    $ticket->status = 1; // Answered
                }
                $ticket->last_reply = now();
                $ticket->updated_at = now(); // Update timestamp to reset auto-close timer
                $ticket->save();

                // Send beautiful email notification to user
                if ($ticket->user && $ticket->user->email) {
                    try {
                        Mail::to($ticket->user->email)->send(new TicketReplyUserMail($ticket, $reply, $admin));
                    } catch (\Exception $e) {
                        Log::warning('Failed to send ticket reply email to user: ' . $e->getMessage());
                    }
                }
            }

            $reply->load('admin');

            return response()->json([
                'status' => 'success',
                'message' => 'Reply added successfully',
                'reply' => $reply,
            ]);
        } catch (\Exception $e) {
            Log::error('ManageTicketController reply error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to add reply'
            ], 500);
        }
    }

    // Delete a ticket
    public function destroy($id)
    {
        try {
            $ticket = Ticket::findOrFail($id);
            $ticket->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Ticket deleted successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('ManageTicketController destroy error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete ticket'
            ], 500);
        }
    }
}
