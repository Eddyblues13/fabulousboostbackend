<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServiceUpdate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ManageServiceUpdateController extends Controller
{
    //

    public function UpdateService(Request $request)
    {
        try {
            // Validate incoming request
            $validated = $request->validate([
                'service'  => 'required|string|max:255',
                'details'  => 'required|string',
                'date'     => 'required|date',
                'update'   => 'required|string|max:255',
                'category' => 'required|string|max:100',
            ]);

            // Create new service update (don't update existing, allow duplicates)
            $serviceUpdate = ServiceUpdate::create([
                'service'  => $validated['service'],
                'details'  => $validated['details'],
                'date'     => $validated['date'],
                'update'   => $validated['update'],
                'category' => $validated['category'],
            ]);

            // Clear cache
            \Illuminate\Support\Facades\Cache::forget('service_updates');

            return response()->json([
                'status'  => true,
                'message' => 'Service update saved successfully.',
                'data'    => $serviceUpdate
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed.',
                'errors'  => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('UpdateService error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'status'  => false,
                'message' => 'Failed to save service update.',
                'error'   => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get all service update history
     */
    public function index()
    {
        try {
            $updates = ServiceUpdate::orderBy('date', 'desc')
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($update) {
                    return [
                        'id' => $update->id,
                        'service' => $update->service ?? '',
                        'details' => $update->details ?? '',
                        'update' => $update->update ?? '',
                        'category' => $update->category ?? '',
                        'date' => $update->date ? $update->date->format('Y-m-d') : ($update->created_at ? $update->created_at->format('Y-m-d') : ''),
                        'created_at' => $update->created_at ? $update->created_at->format('Y-m-d H:i:s') : null,
                    ];
                });

            return response()->json([
                'status' => true,
                'data' => $updates
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('ServiceUpdateHistory error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to load service update history',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
                'data' => []
            ], 500);
        }
    }

    /**
     * Get all service update history (alias for backward compatibility)
     */
    public function ServiceUpdateHistory()
    {
        return $this->index();
    }
}