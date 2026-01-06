<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'category_id',
        'order_ids',
        'request_type',
        'message',
        'email',
        'ticket',
        'subject',
        'status',
        'priority',
        'last_reply',
    ];

    protected $casts = [
        'status' => 'integer',
        'priority' => 'integer',
        'last_reply' => 'datetime',
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function replies()
    {
        return $this->hasMany(TicketReply::class)->orderBy('created_at', 'asc');
    }

    public function publicReplies()
    {
        return $this->hasMany(TicketReply::class)->where('is_internal', false)->orderBy('created_at', 'asc');
    }

    // Status helpers
    public function isOpen()
    {
        return $this->status === 0;
    }

    public function isAnswered()
    {
        return $this->status === 1;
    }

    public function isInProgress()
    {
        return $this->status === 2;
    }

    public function isClosed()
    {
        return $this->status === 3;
    }

    public function getStatusTextAttribute()
    {
        return match($this->status) {
            0 => 'Pending',
            1 => 'Answered',
            2 => 'In Progress',
            3 => 'Closed',
            default => 'Unknown',
        };
    }

    public function getPriorityTextAttribute()
    {
        return match($this->priority ?? 1) {
            1 => 'Low',
            2 => 'Medium',
            3 => 'High',
            default => 'Low',
        };
    }
}
