<?php

namespace App\Http\Controllers\API\Admin;

use App\Models\User;
use App\Models\Transaction;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;

class TransactionController extends Controller
{
    public function index(Request $request)
    {
        $query = Transaction::with('user')
            ->latest();

        // Apply filters
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('type')) {
            $query->where('transaction_type', $request->type);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('transaction_id', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $transactions = $query->paginate($request->per_page ?? 15);

        return response()->json([
            'data' => $transactions,
            'message' => 'Transactions retrieved successfully'
        ]);
    }

    public function stats()
    {
        $stats = [
            'total_transactions' => Transaction::count(),
            'total_credit' => Transaction::where('transaction_type', 'credit')->sum('amount'),
            'total_debit' => Transaction::where('transaction_type', 'debit')->sum('amount'),
            'total_fees' => Transaction::sum('charge'),
            'status_counts' => Transaction::select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->get()
                ->pluck('count', 'status'),
            'type_counts' => Transaction::select('transaction_type', DB::raw('count(*) as count'))
                ->groupBy('transaction_type')
                ->get()
                ->pluck('count', 'transaction_type'),
        ];

        return response()->json([
            'data' => $stats,
            'message' => 'Transaction stats retrieved successfully'
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'amount' => 'required|numeric|min:0',
            'charge' => 'required|numeric|min:0',
            'transaction_type' => 'required|in:credit,debit',
            'description' => 'required|string|max:255',
            'status' => 'required|in:pending,completed,failed',
        ]);

        // Generate transaction ID
        $validated['transaction_id'] = 'TXN' . time() . strtoupper(Str::random(4));

        $transaction = Transaction::create($validated);

        // Update user balance if transaction is completed
        if ($transaction->status === 'completed') {
            $this->updateUserBalance($transaction);
        }

        return response()->json([
            'data' => $transaction->load('user'),
            'message' => 'Transaction created successfully'
        ], 201);
    }

    public function show(Transaction $transaction)
    {
        return response()->json([
            'data' => $transaction->load('user'),
            'message' => 'Transaction retrieved successfully'
        ]);
    }

    public function update(Request $request, Transaction $transaction)
    {
        $validated = $request->validate([
            'amount' => 'sometimes|numeric|min:0',
            'charge' => 'sometimes|numeric|min:0',
            'transaction_type' => 'sometimes|in:credit,debit',
            'description' => 'sometimes|string|max:255',
            'status' => 'sometimes|in:pending,completed,failed',
        ]);

        $originalStatus = $transaction->status;
        $transaction->update($validated);

        // Update user balance if status changed to/from completed
        if (
            $originalStatus !== $transaction->status &&
            ($transaction->status === 'completed' || $originalStatus === 'completed')
        ) {
            $this->updateUserBalance($transaction, $originalStatus);
        }

        return response()->json([
            'data' => $transaction->refresh()->load('user'),
            'message' => 'Transaction updated successfully'
        ]);
    }

    public function destroy(Transaction $transaction)
    {
        // Reverse the transaction if it was completed
        if ($transaction->status === 'completed') {
            $this->reverseTransaction($transaction);
        }

        $transaction->delete();

        return response()->json([
            'message' => 'Transaction deleted successfully'
        ]);
    }

    public function changeStatus(Request $request, Transaction $transaction)
    {
        $request->validate([
            'status' => 'required|in:pending,completed,failed',
        ]);

        $originalStatus = $transaction->status;
        $transaction->update(['status' => $request->status]);

        // Update user balance if status changed to/from completed
        if (
            $originalStatus !== $transaction->status &&
            ($transaction->status === 'completed' || $originalStatus === 'completed')
        ) {
            $this->updateUserBalance($transaction, $originalStatus);
        }

        return response()->json([
            'data' => $transaction->refresh()->load('user'),
            'message' => 'Transaction status updated successfully'
        ]);
    }

    protected function updateUserBalance(Transaction $transaction, $originalStatus = null)
    {
        $user = User::find($transaction->user_id);

        if ($transaction->status === 'completed') {
            // Apply transaction
            if ($transaction->transaction_type === 'credit') {
                $user->balance += $transaction->amount;
            } else {
                $user->balance -= $transaction->amount;
            }
        } elseif ($originalStatus === 'completed') {
            // Reverse transaction
            if ($transaction->transaction_type === 'credit') {
                $user->balance -= $transaction->amount;
            } else {
                $user->balance += $transaction->amount;
            }
        }

        $user->save();
    }

    protected function reverseTransaction(Transaction $transaction)
    {
        $user = User::find($transaction->user_id);

        if ($transaction->transaction_type === 'credit') {
            $user->balance -= $transaction->amount;
        } else {
            $user->balance += $transaction->amount;
        }

        $user->save();
    }
}
