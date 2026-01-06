<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket Created Successfully</title>
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
            background: linear-gradient(135deg, #2563eb 0%, #3b82f6 50%, #60a5fa 100%);
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
        .message-box {
            background-color: #f1f5f9;
            border-radius: 8px;
            padding: 20px;
            margin: 25px 0;
            border: 1px solid #e2e8f0;
        }
        .message-box p {
            margin: 0;
            color: #475569;
            white-space: pre-wrap;
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
        .footer p {
            margin: 8px 0;
        }
        .icon {
            font-size: 48px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="email-header">
            <div class="icon">✅</div>
            <h1>Ticket Created Successfully!</h1>
            <p style="margin: 10px 0 0 0; opacity: 0.95;">We've received your support request</p>
        </div>
        
        <div class="email-body">
            <p style="font-size: 16px; color: #1e293b;">Hello {{ $user->first_name ?? 'Valued Customer' }},</p>
            
            <p style="color: #475569; margin: 20px 0;">
                Thank you for contacting us! We've successfully created your support ticket and our team will get back to you as soon as possible.
            </p>

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
                    <span class="ticket-info-label">Category:</span>
                    <span class="ticket-info-value">{{ ucfirst($ticket->category_id) }}</span>
                </div>
                <div class="ticket-info-item">
                    <span class="ticket-info-label">Request Type:</span>
                    <span class="ticket-info-value">{{ $ticket->request_type }}</span>
                </div>
                <div class="ticket-info-item">
                    <span class="ticket-info-label">Status:</span>
                    <span class="ticket-info-value">
                        <span style="background-color: #fef3c7; color: #92400e; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 600;">
                            Pending
                        </span>
                    </span>
                </div>
                <div class="ticket-info-item">
                    <span class="ticket-info-label">Created:</span>
                    <span class="ticket-info-value">{{ $ticket->created_at->format('F d, Y \a\t g:i A') }}</span>
                </div>
            </div>

            @if($ticket->message)
            <div class="message-box">
                <p style="font-weight: 600; color: #1e293b; margin-bottom: 10px;">Your Message:</p>
                <p>{{ $ticket->message }}</p>
            </div>
            @endif

            <p style="color: #475569; margin: 25px 0;">
                <strong>What happens next?</strong><br>
                Our support team has been notified and will review your ticket. You'll receive an email notification as soon as we respond. Typically, we respond within 24 hours.
            </p>

            <div style="text-align: center;">
                <a href="{{ url('/dashboard/support') }}" class="cta-button">View Your Ticket</a>
            </div>

            <p style="color: #64748b; font-size: 14px; margin-top: 30px; padding-top: 20px; border-top: 1px solid #e2e8f0;">
                <strong>Need to add more information?</strong><br>
                You can reply to this ticket anytime by visiting your support dashboard. We'll keep you updated via email on any responses.
            </p>
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

