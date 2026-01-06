<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Service;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class ManageServiceController extends Controller
{
    /**
     * Display a listing of services with their relationships.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        try {
            $services = Service::with(['category', 'provider'])->get();
            
            return response()->json([
                'success' => true,
                'data' => $services,
                'message' => 'Services retrieved successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch services: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve services',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created service.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'service_title' => 'required|string|max:255|unique:services,service_title',
                'category_id' => 'required|exists:categories,id',
                'link' => 'nullable|url|max:255',
                'username' => 'nullable|string|max:255',
                'min_amount' => 'required|integer|min:1',
                'max_amount' => 'required|integer|gte:min_amount',
                'price' => 'required|numeric|min:0',
                'price_percentage_increase' => 'nullable|numeric|min:0|max:100',
                'service_status' => 'required|boolean',
                'service_type' => 'nullable|string|max:255',
                'api_provider_id' => 'nullable|exists:api_providers,id',
                'api_service_id' => 'nullable|integer|min:1',
                'api_provider_price' => 'nullable|numeric|min:0',
                'drip_feed' => 'nullable|boolean',
                'refill' => 'nullable|boolean',
                'is_refill_automatic' => 'nullable|boolean',
                'description' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422);
            }

            $service = Service::create($validator->validated());

            return response()->json([
                'success' => true,
                'data' => $service->load('category', 'provider'),
                'message' => 'Service created successfully'
            ], 201);

        } catch (\Exception $e) {
            Log::error('Service creation failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create service',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified service.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        try {
            $service = Service::with(['category', 'provider'])->find($id);
            
            if (!$service) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $service,
                'message' => 'Service retrieved successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch service: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve service',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified service.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        try {
            $service = Service::find($id);
            
            if (!$service) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'service_title' => [
                    'sometimes',
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('services')->ignore($service->id)
                ],
                'category_id' => 'sometimes|required|exists:categories,id',
                'link' => 'nullable|url|max:255',
                'username' => 'nullable|string|max:255',
                'min_amount' => 'sometimes|required|integer|min:1',
                'max_amount' => 'sometimes|required|integer|gte:min_amount',
                'price' => 'sometimes|required|numeric|min:0',
                'price_percentage_increase' => 'nullable|numeric|min:0|max:100',
                'service_status' => 'sometimes|boolean',
                'service_type' => 'nullable|string|max:255',
                'api_provider_id' => 'nullable|exists:api_providers,id',
                'api_service_id' => 'nullable|integer|min:1',
                'api_provider_price' => 'nullable|numeric|min:0',
                'drip_feed' => 'nullable|boolean',
                'refill' => 'nullable|boolean',
                'is_refill_automatic' => 'nullable|boolean',
                'description' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422);
            }

            $service->update($validator->validated());

            return response()->json([
                'success' => true,
                'data' => $service->fresh(['category', 'provider']),
                'message' => 'Service updated successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update service: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update service',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified service.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $service = Service::find($id);
            
            if (!$service) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service not found'
                ], 404);
            }

            $service->delete();

            return response()->json([
                'success' => true,
                'message' => 'Service deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to delete service: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete service',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Activate the specified service.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function activate($id)
    {
        try {
            $service = Service::find($id);
            
            if (!$service) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service not found'
                ], 404);
            }

            $service->update(['service_status' => true]);

            return response()->json([
                'success' => true,
                'message' => 'Service activated successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to activate service: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to activate service',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Deactivate the specified service.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function deactivate($id)
    {
        try {
            $service = Service::find($id);
            
            if (!$service) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service not found'
                ], 404);
            }

            $service->update(['service_status' => false]);

            return response()->json([
                'success' => true,
                'message' => 'Service deactivated successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to deactivate service: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to deactivate service',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Deactivate multiple services.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function deactivateMultiple(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'ids' => 'required|array',
                'ids.*' => 'exists:services,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422);
            }

            $updatedCount = Service::whereIn('id', $request->ids)
                ->update(['service_status' => false]);

            return response()->json([
                'success' => true,
                'message' => "$updatedCount services deactivated successfully"
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to deactivate multiple services: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to deactivate services',
                'error' => $e->getMessage()
            ], 500);
        }
    }



    
}