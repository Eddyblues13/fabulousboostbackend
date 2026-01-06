<?php

namespace App\Http\Controllers\Api\Admin;


use App\Http\Controllers\Controller;
use App\Models\Fund;
use App\Models\Order;
use App\Models\ApiProvider;
use App\Models\User;
use App\Models\Transaction;
use App\Models\Ticket;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Collection;

class AdminController extends Controller
{
    public function dashboard(): JsonResponse
    {
        try {
            $last30Days = Carbon::now()->subDays(30)->toDateString();

            $data = [
                'totalAmountReceived' => Fund::where('status', 1)->sum('amount'),
                'totalOrder' => Order::count(),
                'totalProviders' => ApiProvider::count(),
                'userRecord' => $this->getUserStatistics(),
                'transactionProfit' => $this->getTransactionProfit($last30Days),
                'tickets' => $this->getTicketStatistics(),
                'orders' => $this->getOrderStatistics(),
                'bestSale' => $this->getBestSellingServices(),
                'latestUser' => User::latest()->limit(5)->get(),
            ];

            return response()->json([
                'success' => true,
                'data' => $data,
                'statistics' => $this->getOrderStatisticsGraph(),
            ]);
        } catch (\Exception $e) {
            Log::error('Dashboard Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Server Error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function getUserStatistics(): array
    {
        return User::query()
            ->selectRaw('COUNT(id) AS totalUser')
            ->selectRaw('SUM(balance) AS totalUserBalance')
            ->selectRaw('COUNT((CASE WHEN created_at >= CURDATE() THEN id END)) AS todayJoin')
            ->first()
            ->toArray();
    }

    private function getTransactionProfit(string $last30Days): array
    {
        return Transaction::query()
            ->selectRaw(
                'SUM(CASE 
                WHEN description LIKE "DEPOSIT Via%" AND created_at >= ? 
                    THEN charge 
                WHEN description LIKE "Place order%" AND created_at >= ? 
                    THEN amount 
                ELSE 0 
            END) AS profit_30_days',
                [$last30Days, $last30Days]
            )
            ->selectRaw(
                'SUM(CASE 
                WHEN description LIKE "DEPOSIT Via%" AND created_at >= CURDATE() 
                    THEN charge 
                WHEN description LIKE "Place order%" AND created_at >= CURDATE() 
                    THEN amount 
                ELSE 0 
            END) AS profit_today'
            )
            ->first()
            ->toArray();
    }


    private function getTicketStatistics(): array
    {
        return Ticket::query()
            ->where('created_at', '>', Carbon::now()->subDays(30))
            ->selectRaw('count(CASE WHEN status = 3 THEN status END) AS closed')
            ->selectRaw('count(CASE WHEN status = 2 THEN status END) AS replied')
            ->selectRaw('count(CASE WHEN status = 1 THEN status END) AS answered')
            ->selectRaw('count(CASE WHEN status = 0 THEN status END) AS pending')
            ->first()
            ->toArray();
    }

    private function getOrderStatistics(): array
    {
        $orderStats = Order::query()
            ->where('created_at', '>', Carbon::now()->subDays(30))
            ->selectRaw('count(id) as totalOrder')
            ->selectRaw('count(CASE WHEN status = "completed" THEN status END) AS completed')
            ->selectRaw('count(CASE WHEN status = "processing" THEN status END) AS processing')
            ->selectRaw('count(CASE WHEN status = "pending" THEN status END) AS pending')
            ->selectRaw('count(CASE WHEN status = "progress" THEN status END) AS inProgress')
            ->selectRaw('count(CASE WHEN status = "partial" THEN status END) AS partial')
            ->selectRaw('count(CASE WHEN status = "canceled" THEN status END) AS canceled')
            ->selectRaw('count(CASE WHEN status = "refunded" THEN status END) AS refunded')
            ->selectRaw('COUNT((CASE WHEN created_at >= CURDATE() THEN id END)) AS todaysOrder')
            ->first();

        return [
            'records' => $orderStats->toArray(),
            'percent' => $this->calculateOrderPercentages($orderStats)
        ];
    }

    private function calculateOrderPercentages(Order $orderStats): array
    {
        $total = $orderStats->totalOrder ?: 1;

        return [
            'complete' => round(($orderStats->completed / $total) * 100, 2),
            'processing' => round(($orderStats->processing / $total) * 100, 2),
            'pending' => round(($orderStats->pending / $total) * 100, 2),
            'inProgress' => round(($orderStats->inProgress / $total) * 100, 2),
            'partial' => round(($orderStats->partial / $total) * 100, 2),
            'canceled' => round(($orderStats->canceled / $total) * 100, 2),
            'refunded' => round(($orderStats->refunded / $total) * 100, 2),
        ];
    }

    private function getBestSellingServices(): Collection
    {
        return Order::with('service')
            ->whereHas('service')
            ->selectRaw('service_id, COUNT(service_id) as count, sum(quantity) as quantity')
            ->groupBy('service_id')
            ->orderBy('count', 'DESC')
            ->take(10)
            ->get();
    }

    private function getOrderStatisticsGraph(): array
    {
        $orderStatistics = Order::query()
            ->where('created_at', '>', Carbon::now()->subDays(30))
            ->selectRaw('count(CASE WHEN status = "completed" THEN status END) AS completed')
            ->selectRaw('count(CASE WHEN status = "processing" THEN status END) AS processing')
            ->selectRaw('count(CASE WHEN status = "pending" THEN status END) AS pending')
            ->selectRaw('count(CASE WHEN status = "progress" THEN status END) AS progress')
            ->selectRaw('count(CASE WHEN status = "partial" THEN status END) AS partial')
            ->selectRaw('count(CASE WHEN status = "canceled" THEN status END) AS canceled')
            ->selectRaw('count(CASE WHEN status = "refunded" THEN status END) AS refunded')
            ->selectRaw('DATE_FORMAT(created_at, "%d %b") as date')
            ->orderBy('date')
            ->groupBy('date')
            ->get();

        return $this->formatGraphData($orderStatistics);
    }

    private function formatGraphData(Collection $orderStatistics): array
    {
        $statistics = [
            'date' => [],
            'completed' => [],
            'processing' => [],
            'pending' => [],
            'progress' => [],
            'partial' => [],
            'canceled' => [],
            'refunded' => [],
        ];

        $orderStatistics->each(function ($item) use (&$statistics) {
            $statistics['date'][] = trim($item->date);
            $statistics['completed'][] = $item->completed ?? 0;
            $statistics['processing'][] = $item->processing ?? 0;
            $statistics['pending'][] = $item->pending ?? 0;
            $statistics['progress'][] = $item->progress ?? 0;
            $statistics['partial'][] = $item->partial ?? 0;
            $statistics['canceled'][] = $item->canceled ?? 0;
            $statistics['refunded'][] = $item->refunded ?? 0;
        });

        return $statistics;
    }
}
