<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AdminSettingsController extends Controller
{
    /**
     * Get admin profile and security details.
     */
    public function index()
    {
        $admin = Auth::user();

        return response()->json([
            'profile' => [
                'name'       => $admin->name,
                'email'      => $admin->email,
                'type'       => $admin->type,
                'created_at' => $admin->created_at,
                'updated_at' => $admin->updated_at,
            ],
            'security' => [
                'last_login_at'       => $admin->last_login_at,
                'password_changed_at' => $admin->password_changed_at,
            ]
        ]);
    }

    /**
     * Update admin profile.
     */
    public function updateProfile(Request $request)
    {
        $admin = Auth::user();

        $validated = $request->validate([
            'name'  => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:admins,email,' . $admin->id,
        ]);

        $admin->update($validated);

        return response()->json([
            'message' => 'Profile updated successfully.',
            'admin'   => $admin->only(['name', 'email', 'type'])
        ]);
    }

    /**
     * Update admin password.
     */
    public function updateSecurity(Request $request)
    {
        $admin = Auth::user();

        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password'         => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $admin->update([
            'password'            => Hash::make($request->password),
            'password_changed_at' => now(),
        ]);

        return response()->json([
            'message'     => 'Password updated successfully.',
            'changed_at'  => $admin->password_changed_at
        ]);
    }

    /**
     * Get admin activity logs.
     */
    public function getActivityLogs()
    {
        // Placeholder - implement logging system if needed
        return response()->json([
            'activities' => [],
            'message'    => 'Activity logs fetched successfully.'
        ]);
    }
}
