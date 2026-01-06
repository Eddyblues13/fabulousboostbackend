<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\Ticket;
use App\Models\TicketReply;

class TicketReplyUserMail extends Mailable
{
    use Queueable, SerializesModels;

    public $ticket;
    public $reply;
    public $admin;

    public function __construct(Ticket $ticket, $reply, $admin = null)
    {
        $this->ticket = $ticket;
        $this->reply = $reply; // Can be TicketReply model or object with message and created_at
        $this->admin = $admin;
    }

    public function build()
    {
        $subject = $this->ticket->status === 3 
            ? "Ticket #{$this->ticket->id} Has Been Resolved" 
            : "New Reply on Ticket #{$this->ticket->id}: {$this->ticket->subject}";

        return $this->subject($subject)
            ->view('emails.ticket-reply-user')
            ->with([
                'ticket' => $this->ticket,
                'reply' => $this->reply,
                'admin' => $this->admin,
            ]);
    }
}

