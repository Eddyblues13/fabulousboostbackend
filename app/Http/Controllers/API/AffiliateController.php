<?php

namespace App\Http\Controllers\API;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Models\AffiliatePayout;
use App\Models\AffiliateProgram;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AffiliateController extends Controller
{
    public function index()
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'has_program' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            $affiliate = $user->affiliateProgram;

            $response = [
                'has_program' => (bool)$affiliate,
            ];

            if ($affiliate) {
                try {
                    $stats = $affiliate->stats;
                    if (!$stats) {
                        $stats = $affiliate->stats()->create($this->emptyStats());
                    }
                } catch (\Exception $e) {
                    \Log::error('Error fetching/creating affiliate stats: ' . $e->getMessage());
                    // Continue without stats if there's an error
                }

                $response = array_merge($response, [
                    'has_program' => true,
                    'referral_link' => url('/ref/' . $affiliate->referral_code),
                    'commission_rate' => $affiliate->commission_rate ?? 4.0,
                    'minimum_payout' => $affiliate->minimum_payout ?? 2000.00,
                    'referral_code' => $affiliate->referral_code ?? ''
                ]);
            }

            return response()->json($response);
        } catch (\Exception $e) {
            \Log::error('Affiliate index error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'has_program' => false,
                'error' => config('app.debug') ? $e->getMessage() : 'Failed to load affiliate data'
            ], 500);
        }
    }

    public function generateLink(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            // Check if user already has an affiliate program
            try {
                $existingAffiliate = $user->affiliateProgram;
                if ($existingAffiliate) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You already have an affiliate program',
                        'referral_link' => url('/ref/' . $existingAffiliate->referral_code),
                        'referral_code' => $existingAffiliate->referral_code
                    ], 400);
                }
            } catch (\Exception $e) {
                \Log::error('Error checking existing affiliate: ' . $e->getMessage());
                // Continue to create new one if check fails
            }

            try {
                $affiliate = AffiliateProgram::create([
                    'user_id' => $user->id,
                    'referral_code' => Str::random(8),
                    'commission_rate' => 4.0, // Default commission rate
                    'minimum_payout' => 2000.00 // Default minimum payout
                ]);

                // Create stats record with default values
                try {
                    $affiliate->stats()->create($this->emptyStats());
                } catch (\Exception $e) {
                    \Log::error('Error creating affiliate stats: ' . $e->getMessage());
                    // Continue even if stats creation fails
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Affiliate program created successfully',
                    'referral_link' => url('/ref/' . $affiliate->referral_code),
                    'commission_rate' => $affiliate->commission_rate,
                    'minimum_payout' => $affiliate->minimum_payout,
                    'referral_code' => $affiliate->referral_code
                ]);
            } catch (\Exception $e) {
                \Log::error('Error creating affiliate program: ' . $e->getMessage(), [
                    'trace' => $e->getTraceAsString(),
                    'user_id' => $user->id
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to create affiliate program',
                    'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
                ], 500);
            }
        } catch (\Exception $e) {
            \Log::error('Affiliate generateLink error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to create affiliate program',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function getStats()
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json($this->emptyStats(), 401);
            }

            $affiliate = $user->affiliateProgram;

            if (!$affiliate) {
                return response()->json($this->emptyStats());
            }

            try {
                $stats = $affiliate->stats;
                if (!$stats) {
                    // Create stats if they don't exist
                    $stats = $affiliate->stats()->create($this->emptyStats());
                }

                // Calculate conversion rate if not set
                if (isset($stats->visits) && $stats->visits > 0 && (!isset($stats->conversion_rate) || !$stats->conversion_rate)) {
                    $conversionRate = ($stats->registrations / $stats->visits) * 100;
                    $stats->update(['conversion_rate' => $conversionRate]);
                    $stats->refresh();
                }
            } catch (\Exception $e) {
                \Log::error('Error fetching affiliate stats: ' . $e->getMessage());
                return response()->json($this->emptyStats());
            }

            return response()->json([
                'visits' => $stats->visits ?? 0,
                'registrations' => $stats->registrations ?? 0,
                'referrals' => $stats->referrals ?? 0,
                'conversion_rate' => $stats->conversion_rate ?? 0,
                'total_earnings' => $stats->total_earnings ?? 0,
                'available_earnings' => $stats->available_earnings ?? 0,
                'paid_earnings' => $stats->paid_earnings ?? 0
            ]);
        } catch (\Exception $e) {
            \Log::error('Affiliate getStats error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json($this->emptyStats(), 500);
        }
    }

    public function getPayouts()
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([], 401);
            }

            $affiliate = $user->affiliateProgram;

            if (!$affiliate) {
                return response()->json([]);
            }

            try {
                $payouts = $affiliate->payouts()->orderBy('created_at', 'desc')->get();
                return response()->json($payouts);
            } catch (\Exception $e) {
                \Log::error('Error fetching affiliate payouts: ' . $e->getMessage());
                return response()->json([]);
            }
        } catch (\Exception $e) {
            \Log::error('Affiliate getPayouts error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([], 500);
        }
    }

    public function requestPayout(Request $request)
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            $affiliate = $user->affiliateProgram;

            if (!$affiliate) {
                return response()->json([
                    'success' => false,
                    'message' => 'No affiliate program found'
                ], 404);
            }

            try {
                $stats = $affiliate->stats;
                
                if (!$stats) {
                    $stats = $affiliate->stats()->create($this->emptyStats());
                }

                $availableEarnings = $stats->available_earnings ?? 0;
                $minimumPayout = $affiliate->minimum_payout ?? 2000.00;

                if ($availableEarnings < $minimumPayout) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You need at least ₦' . number_format($minimumPayout, 2) . ' to request a payout',
                        'minimum_required' => $minimumPayout,
                        'current_balance' => $availableEarnings
                    ], 400);
                }

                $payout = AffiliatePayout::create([
                    'affiliate_program_id' => $affiliate->id,
                    'amount' => $availableEarnings,
                    'status' => 'pending',
                    'payment_method' => $request->payment_method ?? 'bank_transfer'
                ]);

                // Reset available earnings and update paid earnings
                $paidEarnings = ($stats->paid_earnings ?? 0) + $availableEarnings;
                $stats->update([
                    'available_earnings' => 0,
                    'paid_earnings' => $paidEarnings
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Payout requested successfully',
                    'payout' => $payout,
                    'new_balance' => 0
                ]);
            } catch (\Exception $e) {
                \Log::error('Error processing payout request: ' . $e->getMessage(), [
                    'trace' => $e->getTraceAsString(),
                    'user_id' => $user->id
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to request payout',
                    'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
                ], 500);
            }
        } catch (\Exception $e) {
            \Log::error('Affiliate requestPayout error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to request payout',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function trackVisit($code)
    {
        $affiliate = AffiliateProgram::where('referral_code', $code)->first();

        if (!$affiliate) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid referral code'
            ], 404);
        }

        // Get or create stats
        $stats = $affiliate->stats;
        if (!$stats) {
            $stats = $affiliate->stats()->create($this->emptyStats());
        }

        // Increment visit count
        $stats->increment('visits');

        // Calculate conversion rate
        $visits = $stats->visits;
        $registrations = $stats->registrations;
        $conversionRate = $visits > 0 ? ($registrations / $visits) * 100 : 0;
        $stats->update(['conversion_rate' => $conversionRate]);

        // Return success response - frontend will handle redirect
        return response()->json([
            'success' => true,
            'referral_code' => $code,
            'message' => 'Visit tracked successfully'
        ]);
    }

    protected function emptyStats()
    {
        return [
            'visits' => 0,
            'registrations' => 0,
            'referrals' => 0,
            'conversion_rate' => 0,
            'available_earnings' => 0,
            'total_earnings' => 0,
            'paid_earnings' => 0
        ];
    }
}
