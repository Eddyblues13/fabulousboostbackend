<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class VerifyApiKey
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check for API key in multiple places: header, query parameter, or request body
        $apiKey = $request->header('X-API-KEY') 
            ?? $request->input('key') 
            ?? $request->query('key')
            ?? $request->query('api_key');

        if (!$apiKey) {
            return response()->json([
                'status' => 'error',
                'message' => 'API key is missing.'
            ], 401);
        }

        $user = User::where('api_key', $apiKey)->first();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid API key.'
            ], 401);
        }

        // Set the authenticated user so controllers can access it via Auth::user()
        Auth::setUser($user);

        return $next($request);
    }
}
