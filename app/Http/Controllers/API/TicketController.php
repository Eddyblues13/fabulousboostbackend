<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use App\Mail\TicketCreatedUserMail;
use App\Mail\TicketCreatedAdminMail;

class TicketController extends Controller
{
    public function index()
    {
        try {
            $user = Auth::user();

            $tickets = Ticket::where('user_id', $user->id)
                ->with(['replies' => function($query) {
                    $query->where('is_internal', false)->orderBy('created_at', 'asc');
                }])
                ->latest()
                ->get();

            return response()->json([
                'status' => 'success',
                'tickets' => $tickets
            ]);
        } catch (\Exception $e) {
            Log::error('TicketController index error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch tickets'
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $user = Auth::user();

            $ticket = Ticket::where('user_id', $user->id)
                ->with(['replies' => function($query) {
                    $query->where('is_internal', false)->orderBy('created_at', 'asc');
                }])
                ->findOrFail($id);

            return response()->json([
                'status' => 'success',
                'ticket' => $ticket
            ]);
        } catch (\Exception $e) {
            Log::error('TicketController show error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Ticket not found'
            ], 404);
        }
    }




    public function store(Request $request)
    {
        try {
            $request->validate([
                'category_id'   => 'required|string|max:191',
                'subject'       => 'required|string|max:191',
                'order_ids'     => 'nullable|string',
                'request_type'  => 'required|string|max:191',
                'message'       => 'nullable|string',
                'priority'      => 'nullable|integer|in:1,2,3',
            ]);

            $user = Auth::user();

            $ticket = Ticket::create([
                'user_id'       => $user?->id,
                'name'          => $user?->first_name . ' ' . $user?->last_name ?? null,
                'email'         => $user?->email ?? null,
                'category_id'   => $request->category_id,
                'subject'       => $request->subject,
                'order_ids'     => $request->order_ids,
                'request_type'  => $request->request_type,
                'message'       => $request->message,
                'status'        => 0,
                'priority'      => $request->priority ?? 1,
                'last_reply'    => now(),
            ]);

            // Send beautiful email to user
            try {
                if ($user && $user->email) {
                    Mail::to($user->email)->send(new TicketCreatedUserMail($ticket, $user));
                }
            } catch (\Exception $e) {
                Log::warning('Failed to send ticket creation email to user: ' . $e->getMessage());
            }

            // Send beautiful email to admin
            try {
                $adminEmail = config('mail.from.address');
                if ($adminEmail) {
                    Mail::to($adminEmail)->send(new TicketCreatedAdminMail($ticket, $user));
                }
            } catch (\Exception $e) {
                Log::warning('Failed to send ticket creation email to admin: ' . $e->getMessage());
            }

            return response()->json([
                'status'   => 'success',
                'message'  => 'Ticket submitted successfully',
                'ticket_id' => $ticket->id,
                'ticket'    => $ticket,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('TicketController store error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create ticket'
            ], 500);
        }
    }

    public function reply(Request $request, $id)
    {
        try {
            $request->validate([
                'message' => 'required|string|min:5',
            ]);

            $user = Auth::user();

            $ticket = Ticket::where('user_id', $user->id)->findOrFail($id);

            if ($ticket->status === 3) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot reply to a closed ticket. Please create a new ticket if you need further assistance.'
                ], 400);
            }

            $reply = TicketReply::create([
                'ticket_id' => $ticket->id,
                'user_id' => $user->id,
                'admin_id' => null,
                'message' => $request->message,
                'is_internal' => false,
            ]);

            // Update ticket status and last_reply - automatically reopen if closed
            $ticket->status = 0; // Set to pending/open when user replies
            $ticket->last_reply = now();
            $ticket->updated_at = now(); // Update timestamp to prevent auto-close
            $ticket->save();

            // Send email notification to admin (simple notification for user replies)
            try {
                $adminEmail = config('mail.from.address');
                if ($adminEmail) {
                    Mail::send([], [], function ($message) use ($ticket, $user, $reply, $adminEmail) {
                        $message->to($adminEmail)
                            ->subject("New Reply on Ticket #{$ticket->id}: {$ticket->subject}")
                            ->html("
                                <h2>New Reply on Support Ticket</h2>
                                <p><strong>Ticket ID:</strong> #{$ticket->id}</p>
                                <p><strong>User:</strong> {$user->first_name} {$user->last_name} ({$user->email})</p>
                                <p><strong>Subject:</strong> {$ticket->subject}</p>
                                <p><strong>Reply:</strong></p>
                                <p>{$reply->message}</p>
                                <p><a href='" . url("/admin/tickets") . "'>View Ticket</a></p>
                            ");
                    });
                }
            } catch (\Exception $e) {
                Log::warning('Failed to send ticket reply email to admin: ' . $e->getMessage());
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Reply submitted successfully',
                'reply' => $reply
            ]);
        } catch (\Exception $e) {
            Log::error('TicketController reply error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to submit reply'
            ], 500);
        }
    }
}

