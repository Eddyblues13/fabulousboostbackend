<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;

class AccountController extends Controller
{
    public function getAccount()
    {
        try {
            $user = Auth::user();

            return response()->json([
                'success' => true,
                'data' => [
                    'username' => $user->username,
                    'email' => $user->email,
                    'language' => $user->language ?? 'en',
                    'timezone' => $user->timezone ?? 'utc+1',
                    'two_factor_enabled' => $user->two_factor_enabled ?? false,
                    'api_key' => $user->api_key ?? ''
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch account data: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch account data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getNotifications()
    {
        try {
            $user = Auth::user();

            return response()->json([
                'success' => true,
                'data' => [
                    'emailOrders' => $user->email_orders ?? true,
                    'emailPromotions' => $user->email_promotions ?? false,
                    'emailUpdates' => $user->email_updates ?? true,
                    'pushOrders' => $user->push_orders ?? true,
                    'pushPromotions' => $user->push_promotions ?? false,
                    'pushUpdates' => $user->push_updates ?? false,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch notification settings: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch notification settings',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updatePassword(Request $request)
    {
        try {
            $request->validate([
                'current_password' => 'required|current_password',
                'new_password' => 'required|min:8|confirmed',
            ]);

            $user = Auth::user();
            $user->password = Hash::make($request->new_password);
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Password updated successfully'
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Password update failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update password',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updateEmail(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email|unique:users,email,' . Auth::id(),
            ]);

            $user = Auth::user();
            $user->email = $request->email;
            $user->email_verified_at = null;
            $user->save();

            // Send verification email here if needed

            return response()->json([
                'success' => true,
                'message' => 'Email updated successfully. Please verify your new email address.'
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Email update failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update email',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function updateUsername(Request $request)
    {
        try {
            $request->validate([
                'username' => 'required|string|max:255|unique:users,username,' . Auth::id(),
            ]);

            $user = Auth::user();
            $user->username = $request->username;
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Username updated successfully'
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Username update failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update username',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updateTwoFactor(Request $request)
    {
        try {
            $request->validate([
                'enabled' => 'required|boolean',
            ]);

            $user = Auth::user();
            $user->two_factor_enabled = $request->enabled;
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Two factor authentication updated successfully',
                'enabled' => $user->two_factor_enabled
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Two factor update failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update two factor authentication',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function generateApiKey()
    {
        try {
            $user = Auth::user();
            $user->api_key = Str::random(40);
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'API key generated successfully',
                'api_key' => $user->api_key
            ]);
        } catch (\Exception $e) {
            Log::error('API key generation failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate API key',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updatePreferences(Request $request)
    {
        try {
            $validated = $request->validate([
                'language' => ['nullable', Rule::in(['en', 'es', 'fr', 'de', 'it', 'pt', 'ru', 'zh'])],
                'timezone' => ['nullable', Rule::in(['utc+1', 'utc+0', 'utc-5', 'utc-8', 'utc+3', 'utc+8', 'utc+9'])],
            ]);

            $user = Auth::user();

            if (isset($validated['language'])) {
                $user->language = $validated['language'];
            }

            if (isset($validated['timezone'])) {
                $user->timezone = $validated['timezone'];
            }

            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Preferences updated successfully'
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Preferences update failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update preferences',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updateNotifications(Request $request)
    {
        try {
            $validated = $request->validate([
                'emailOrders' => 'sometimes|boolean',
                'emailPromotions' => 'sometimes|boolean',
                'emailUpdates' => 'sometimes|boolean',
                'pushOrders' => 'sometimes|boolean',
                'pushPromotions' => 'sometimes|boolean',
                'pushUpdates' => 'sometimes|boolean',
            ]);

            $user = Auth::user();
            $user->fill($validated);
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Notification preferences updated successfully'
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Notification preferences update failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update notification preferences',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
