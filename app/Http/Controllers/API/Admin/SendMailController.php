<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Mail\AdminNotificationMail;
use Illuminate\Support\Facades\Mail;

class SendMailController extends Controller
{
    public function sendEmailToAll(Request $request)
    {
        $request->validate([
            'subject' => 'required|string|max:255',
            'message' => 'required|string',
        ]);

        $users = User::all();

        foreach ($users as $user) {
            // Queue the email instead of sending immediately
            Mail::to($user->email)->queue(new AdminNotificationMail($request->subject, $request->message));
        }

        return response()->json([
            'message' => 'Emails queued for sending successfully!',
        ]);
    }
}

