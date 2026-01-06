<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\Ticket;

class TicketCreatedAdminMail extends Mailable
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
        return $this->subject("New Support Ticket #{$this->ticket->id}: {$this->ticket->subject}")
            ->view('emails.ticket-created-admin')
            ->with([
                'ticket' => $this->ticket,
                'user' => $this->user,
            ]);
    }
}

