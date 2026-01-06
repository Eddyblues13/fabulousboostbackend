<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Models\Category;
use App\Models\ApiProvider;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ManageServiceController extends Controller
{
    public function getPriceIncreaseStats(Request $request): JsonResponse
    {
        try {
            Log::info('Price stats request received', $request->all());

            $request->validate([
                'category_id' => 'sometimes|exists:categories,id',
                'provider_id' => 'sometimes|exists:api_providers,id',
                'service_ids' => 'sometimes|array',
                'service_ids.*' => 'sometimes|exists:services,id'
            ]);

            $query = Service::query();

            // Apply filters if provided
            if ($request->has('category_id') && $request->category_id) {
                $query->where('category_id', $request->input('category_id'));
            }

            if ($request->has('provider_id') && $request->provider_id) {
                $query->where('api_provider_id', $request->input('provider_id'));
            }

            if ($request->has('service_ids') && !empty($request->service_ids)) {
                $query->whereIn('id', $request->input('service_ids'));
            }

            $stats = $query->selectRaw('
                COUNT(*) as total_services,
                AVG(price) as average_price,
                AVG(api_provider_price) as average_provider_price,
                MIN(price) as min_price,
                MAX(price) as max_price,
                SUM(price) as total_price_value
            ')->first();

            Log::info('Price stats calculated', [
                'total_services' => $stats->total_services,
                'average_price' => $stats->average_price
            ]);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'total_services' => (int) $stats->total_services,
                    'average_price' => (float) $stats->average_price,
                    'average_provider_price' => (float) $stats->average_provider_price,
                    'min_price' => (float) $stats->min_price,
                    'max_price' => (float) $stats->max_price,
                    'total_price_value' => (float) $stats->total_price_value
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Price stats error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch price statistics: ' . $e->getMessage(),
                'debug' => config('app.debug') ? $e->getTraceAsString() : null
            ], 500);
        }
    }

    public function increasePrices(Request $request): JsonResponse
    {
        Log::info('Increase prices request received', $request->all());

        $request->validate([
            'percentage' => 'required|numeric|min:0.1|max:1000',
            'scope' => 'required|string|in:all,category,provider,custom',
            'service_ids' => 'required_if:scope,custom|array',
            'service_ids.*' => 'exists:services,id',
            'category_id' => 'required_if:scope,category|exists:categories,id',
            'provider_id' => 'required_if:scope,provider|exists:api_providers,id'
        ]);

        DB::beginTransaction();

        try {
            $percentage = (float) $request->input('percentage');
            $scope = $request->input('scope');

            $conditions = [];
            $scopeDescription = '';
            $affectedCount = 0;

            switch ($scope) {
                case 'all':
                    $affectedCount = Service::increaseAllPrices($percentage);
                    $scopeDescription = 'all services';
                    break;

                case 'category':
                    $categoryId = $request->input('category_id');
                    $conditions['category_id'] = $categoryId;
                    $category = Category::find($categoryId);
                    if (!$category) {
                        throw new \Exception('Category not found');
                    }
                    $affectedCount = Service::bulkIncreasePrices($percentage, $conditions);
                    $scopeDescription = "category: {$category->category_title}";
                    break;

                case 'provider':
                    $providerId = $request->input('provider_id');
                    $conditions['provider_id'] = $providerId;
                    $provider = ApiProvider::find($providerId);
                    if (!$provider) {
                        throw new \Exception('API Provider not found');
                    }
                    $affectedCount = Service::bulkIncreasePrices($percentage, $conditions);
                    $scopeDescription = "API provider: {$provider->api_name}";
                    break;

                case 'custom':
                    $serviceIds = $request->input('service_ids', []);
                    if (empty($serviceIds)) {
                        throw new \Exception('No services selected');
                    }
                    $conditions['service_ids'] = $serviceIds;
                    $affectedCount = Service::bulkIncreasePrices($percentage, $conditions);
                    $scopeDescription = count($serviceIds) . ' selected services';
                    break;

                default:
                    throw new \Exception('Invalid scope specified');
            }

            DB::commit();

            Log::info('Price increase successful', [
                'affected_count' => $affectedCount,
                'percentage' => $percentage,
                'scope' => $scope
            ]);

            return response()->json([
                'status' => 'success',
                'message' => "Successfully increased prices by {$percentage}% for {$scopeDescription}",
                'data' => [
                    'affected_services' => $affectedCount,
                    'percentage_increase' => $percentage,
                    'scope' => $scope
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Price increase failed: ' . $e->getMessage(), [
                'request' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to increase prices: ' . $e->getMessage(),
                'debug' => config('app.debug') ? $e->getTraceAsString() : null
            ], 500);
        }
    }

    // ... your other methods (store, update, destroy, etc.) ...
}
