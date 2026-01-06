<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\Ticket;

class TicketCreatedUserMail extends Mailable
{
    use Queueable, SerializesModels;

    public $ticket;
    public $user;

    public function __construct(Ticket $ticket, $user)
    {
        $this->ticket = $ticket;
        $this->user = $user;
    }

    public function build()
    {
        return $this->subject("Ticket #{$this->ticket->id} Created Successfully - We'll Get Back to You Soon!")
            ->view('emails.ticket-created-user')
            ->with([
                'ticket' => $this->ticket,
                'user' => $this->user,
            ]);
    }
}

