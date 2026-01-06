<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Reply on Your Ticket</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .email-container {
            background-color: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .email-header {
            @if($ticket->status === 3)
            background: linear-gradient(135deg, #059669 0%, #10b981 100%);
            @else
            background: linear-gradient(135deg, #2563eb 0%, #3b82f6 50%, #60a5fa 100%);
            @endif
            color: #ffffff;
            padding: 40px 30px;
            text-align: center;
        }
        .email-header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 700;
        }
        .email-body {
            padding: 40px 30px;
        }
        .ticket-info {
            background-color: #f8fafc;
            border-left: 4px solid #3b82f6;
            padding: 20px;
            margin: 25px 0;
            border-radius: 8px;
        }
        .ticket-info-item {
            margin: 12px 0;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }
        .ticket-info-label {
            font-weight: 600;
            color: #64748b;
            min-width: 120px;
        }
        .ticket-info-value {
            color: #1e293b;
            text-align: right;
            flex: 1;
        }
        .reply-box {
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
            border-left: 4px solid #3b82f6;
            border-radius: 8px;
            padding: 25px;
            margin: 25px 0;
        }
        .reply-box p {
            margin: 0;
            color: #1e40af;
            white-space: pre-wrap;
            line-height: 1.8;
        }
        .reply-author {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid #bfdbfe;
        }
        .reply-author-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #2563eb 0%, #3b82f6 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 18px;
        }
        .reply-author-info {
            flex: 1;
        }
        .reply-author-name {
            font-weight: 600;
            color: #1e40af;
            margin: 0;
        }
        .reply-author-role {
            font-size: 12px;
            color: #2563eb;
            margin: 2px 0 0 0;
        }
        .cta-button {
            display: inline-block;
            background: linear-gradient(135deg, #2563eb 0%, #3b82f6 100%);
            color: #ffffff;
            padding: 14px 32px;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            margin: 25px 0;
            text-align: center;
        }
        .footer {
            background-color: #f8fafc;
            padding: 30px;
            text-align: center;
            color: #64748b;
            font-size: 14px;
            border-top: 1px solid #e2e8f0;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-resolved {
            background-color: #d1fae5;
            color: #065f46;
        }
        .status-answered {
            background-color: #dbeafe;
            color: #1e40af;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="email-header">
            @if($ticket->status === 3)
            <div style="font-size: 48px; margin-bottom: 20px;">✅</div>
            <h1>Ticket Resolved!</h1>
            <p style="margin: 10px 0 0 0; opacity: 0.95;">Your issue has been resolved</p>
            @else
            <div style="font-size: 48px; margin-bottom: 20px;">💬</div>
            <h1>New Reply on Your Ticket</h1>
            <p style="margin: 10px 0 0 0; opacity: 0.95;">We've responded to your ticket</p>
            @endif
        </div>
        
        <div class="email-body">
            <p style="font-size: 16px; color: #1e293b;">
                Hello {{ $ticket->user->first_name ?? 'Valued Customer' }},
            </p>
            
            @if($ticket->status === 3)
            <p style="color: #475569; margin: 20px 0;">
                Great news! Your support ticket has been resolved. We hope this resolves your issue completely.
            </p>
            @else
            <p style="color: #475569; margin: 20px 0;">
                Our support team has responded to your ticket. Please see the reply below:
            </p>
            @endif

            <div class="ticket-info">
                <div class="ticket-info-item">
                    <span class="ticket-info-label">Ticket ID:</span>
                    <span class="ticket-info-value"><strong>#{{ $ticket->id }}</strong></span>
                </div>
                <div class="ticket-info-item">
                    <span class="ticket-info-label">Subject:</span>
                    <span class="ticket-info-value">{{ $ticket->subject }}</span>
                </div>
                <div class="ticket-info-item">
                    <span class="ticket-info-label">Status:</span>
                    <span class="ticket-info-value">
                        @if($ticket->status === 3)
                        <span class="status-badge status-resolved">Resolved</span>
                        @else
                        <span class="status-badge status-answered">Answered</span>
                        @endif
                    </span>
                </div>
            </div>

            <div class="reply-box">
                <div class="reply-author">
                    <div class="reply-author-avatar">
                        {{ $admin ? ($admin->name[0] ?? 'A') : 'A' }}
                    </div>
                    <div class="reply-author-info">
                        <p class="reply-author-name">{{ $admin ? ($admin->name ?? 'Support Team') : 'Support Team' }}</p>
                        <p class="reply-author-role">Support Representative</p>
                    </div>
                    <div style="font-size: 12px; color: #2563eb;">
                        @if(is_object($reply->created_at) && method_exists($reply->created_at, 'format'))
                            {{ $reply->created_at->format('M d, g:i A') }}
                        @else
                            {{ now()->format('M d, g:i A') }}
                        @endif
                    </div>
                </div>
                <p>{{ $reply->message }}</p>
            </div>

            @if($ticket->status !== 3)
            <p style="color: #475569; margin: 25px 0;">
                <strong>Need to continue the conversation?</strong><br>
                If you have any additional questions or need further assistance, simply reply to this ticket from your support dashboard. We're here to help!
            </p>
            @else
            <p style="color: #475569; margin: 25px 0;">
                <strong>Was this helpful?</strong><br>
                If you have any other questions or concerns, feel free to create a new ticket. We're always here to assist you!
            </p>
            @endif

            <div style="text-align: center;">
                <a href="{{ url('/dashboard/support') }}" class="cta-button">
                    @if($ticket->status === 3)
                    View Resolved Ticket
                    @else
                    Reply to Ticket
                    @endif
                </a>
            </div>
        </div>

        <div class="footer">
            <p style="margin: 0; font-weight: 600; color: #1e293b;">{{ config('app.name') }} Support Team</p>
            <p style="margin: 8px 0 0 0;">We're here to help! 💬</p>
            <p style="margin: 20px 0 0 0; font-size: 12px; color: #94a3b8;">
                This is an automated email. Please do not reply directly to this message.
            </p>
        </div>
    </div>
</body>
</html>

