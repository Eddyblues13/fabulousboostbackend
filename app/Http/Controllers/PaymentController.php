<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use GuzzleHttp\Client;
use App\Models\Transaction;
use App\Models\User;
use App\Models\AffiliateProgram;
use App\Models\AffiliateReferral;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PaymentController extends Controller
{
    /**
     * Initiate a payment (API endpoint).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function initiatePayment(Request $request)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:1',
            'payment_method' => 'required|string|in:flutterwave,paystack,korapay',
        ]);

        // Validate payment method configuration
        if ($validated['payment_method'] === 'flutterwave' && empty(config('services.flutterwave.secret_key'))) {
            return response()->json([
                'success' => false,
                'message' => 'Flutterwave payment is not properly configured',
            ], 500);
        }

        if ($validated['payment_method'] === 'paystack' && empty(config('services.paystack.secret_key'))) {
            return response()->json([
                'success' => false,
                'message' => 'Paystack payment is not properly configured',
            ], 500);
        }

        if ($validated['payment_method'] === 'korapay' && empty(config('services.korapay.secret_key'))) {
            return response()->json([
                'success' => false,
                'message' => 'Korapay payment is not properly configured',
            ], 500);
        }

        try {
            $transactionRef = 'TX_' . uniqid();

            $payment = Transaction::create([
                'user_id' => Auth::id(),
                'transaction_id' => $transactionRef,
                'amount' => $validated['amount'],
                'currency' => Auth::user()->currency ?? 'NGN',
                'charge' => 0.00,
                'transaction_type' => 'deposit',
                'description' => 'Payment via ' . ucfirst($validated['payment_method']),
                'status' => 'pending',
                'payment_method' => $validated['payment_method'],
            ]);

            $paymentData = [
                'tx_ref' => $transactionRef,
                'amount' => $validated['amount'],
                'currency' => Auth::user()->currency ?? 'NGN',
                'redirect_url' => rtrim(config('app.frontend_url'), '/') . '/payment/callback',
                'customer' => [
                    'email' => Auth::user()->email,
                    'name' => Auth::user()->first_name . ' ' . Auth::user()->last_name,
                ],
                'payment_options' => 'card',
                'meta' => [
                    'user_id' => Auth::id(),
                    'transaction_id' => $payment->id,
                ],
            ];

            $paymentURL = $this->createPaymentLink($validated['payment_method'], $paymentData);

            if (!$paymentURL) {
                throw new \Exception('Failed to generate payment link');
            }

            return response()->json([
                'success' => true,
                'payment_url' => $paymentURL,
                'transaction_id' => $transactionRef,
                'message' => 'Payment initiated successfully',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Payment initiation failed: ' . $e->getMessage(), [
                'user_id' => Auth::id(),
                'exception' => $e
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Payment initiation failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle payment callback from payment gateway (Webhook).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function handleCallback(Request $request)
    {
        try {
            Log::info('🔄 Payment callback received', ['request' => $request->all()]);

            $transactionId = $request->input('transaction_id');
            $txRef = $request->input('tx_ref');
            $status = $request->input('status');

            // Use either transaction_id or tx_ref
            $reference = $transactionId ?? $txRef;

            if (!$reference) {
                throw new \Exception('Missing transaction reference');
            }

            // Find the transaction by either field
            $payment = Transaction::where('transaction_id', $reference)
                ->orWhere('meta->tx_ref', $reference)
                ->first();

            if (!$payment) {
                Log::error('❌ Transaction not found for reference: ' . $reference);
                throw new \Exception('Transaction not found for reference: ' . $reference);
            }

            // If already completed, return success
            if ($payment->status === 'completed') {
                Log::info('✅ Payment already completed', ['transaction_id' => $payment->id]);
                return response()->json([
                    'success' => true,
                    'payment' => $payment,
                    'message' => 'Payment already completed',
                ]);
            }

            $normalizedStatus = strtolower($status);

            // Handle Flutterwave payment verification
            if ($payment->payment_method === 'flutterwave') {
                if ($normalizedStatus === 'successful' || $normalizedStatus === 'completed') {
                    $verification = $this->verifyFlutterwavePayment($reference);

                    if (!$verification || $verification['status'] !== 'success') {
                        Log::error('❌ Flutterwave verification failed', ['response' => $verification]);
                        throw new \Exception('Payment verification failed');
                    }

                    // Check if payment is actually successful in Flutterwave
                    $flutterwaveStatus = strtolower($verification['data']['status'] ?? '');
                    $amountPaid = $verification['data']['amount'] ?? 0;
                    $expectedAmount = $payment->amount;

                    Log::info('🔍 Flutterwave verification result', [
                        'flutterwave_status' => $flutterwaveStatus,
                        'amount_paid' => $amountPaid,
                        'expected_amount' => $expectedAmount
                    ]);

                    if ($flutterwaveStatus !== 'successful') {
                        throw new \Exception('Payment not confirmed by Flutterwave. Status: ' . $flutterwaveStatus);
                    }

                    // Verify amount matches (with small tolerance for floating point)
                    if (abs($amountPaid - $expectedAmount) > 0.01) {
                        throw new \Exception('Payment amount mismatch. Paid: ' . $amountPaid . ', Expected: ' . $expectedAmount);
                    }

                    // Update transaction
                    $payment->update([
                        'transaction_id' => $verification['data']['id'] ?? $reference,
                        'status' => 'completed',
                        'payment_method' => $verification['data']['payment_type'] ?? $payment->payment_method,
                        'meta' => json_encode($verification['data'] ?? []),
                    ]);

                    // Credit user's balance
                    $user = $payment->user;
                    $user->balance += $payment->amount;
                    $user->save();

                    Log::info('💰 Payment completed successfully', [
                        'transaction_id' => $payment->id,
                        'user_id' => $user->id,
                        'amount' => $payment->amount,
                        'new_balance' => $user->balance
                    ]);

                    // 💰 Calculate and credit affiliate commission
                    $this->calculateAffiliateCommission($user, $payment->amount);
                } elseif (in_array($normalizedStatus, ['cancelled', 'failed'])) {
                    $payment->update(['status' => 'failed']);
                    Log::info('❌ Payment failed', ['transaction_id' => $payment->id]);
                } else {
                    $payment->update(['status' => 'pending']);
                    Log::info('⏳ Payment pending', ['transaction_id' => $payment->id, 'status' => $normalizedStatus]);
                }
            } elseif ($payment->payment_method === 'korapay') {
                // Handle Korapay payment verification
                if ($normalizedStatus === 'successful' || $normalizedStatus === 'completed' || $normalizedStatus === 'success') {
                    $verification = $this->verifyKorapayPayment($reference);

                    if (!$verification || !$verification['status']) {
                        Log::error('❌ Korapay verification failed', ['response' => $verification]);
                        throw new \Exception('Korapay payment verification failed');
                    }

                    $korapayStatus = strtolower($verification['data']['status'] ?? '');
                    $amountPaid = $verification['data']['amount'] ?? 0;
                    $expectedAmount = $payment->amount;

                    Log::info('🔍 Korapay verification result', [
                        'korapay_status' => $korapayStatus,
                        'amount_paid' => $amountPaid,
                        'expected_amount' => $expectedAmount
                    ]);

                    if ($korapayStatus !== 'success') {
                        throw new \Exception('Payment not confirmed by Korapay. Status: ' . $korapayStatus);
                    }

                    if (abs($amountPaid - $expectedAmount) > 0.01) {
                        throw new \Exception('Payment amount mismatch. Paid: ' . $amountPaid . ', Expected: ' . $expectedAmount);
                    }

                    $payment->update([
                        'status' => 'completed',
                        'meta' => json_encode($verification['data'] ?? []),
                    ]);

                    $user = $payment->user;
                    $user->balance += $payment->amount;
                    $user->save();

                    Log::info('💰 Korapay payment completed successfully', [
                        'transaction_id' => $payment->id,
                        'user_id' => $user->id,
                        'amount' => $payment->amount,
                        'new_balance' => $user->balance
                    ]);

                    // 💰 Calculate and credit affiliate commission
                    $this->calculateAffiliateCommission($user, $payment->amount);
                } elseif (in_array($normalizedStatus, ['cancelled', 'failed'])) {
                    $payment->update(['status' => 'failed']);
                    Log::info('❌ Korapay payment failed', ['transaction_id' => $payment->id]);
                } else {
                    $payment->update(['status' => 'pending']);
                    Log::info('⏳ Korapay payment pending', ['transaction_id' => $payment->id, 'status' => $normalizedStatus]);
                }
            } else {
                // For Paystack and other payment methods
                if ($normalizedStatus === 'successful' || $normalizedStatus === 'completed') {
                    $payment->update(['status' => 'completed']);

                    // Credit user's balance
                    $user = $payment->user;
                    $user->balance += $payment->amount;
                    $user->save();

                    Log::info('💰 Payment completed successfully', [
                        'transaction_id' => $payment->id,
                        'user_id' => $user->id,
                        'amount' => $payment->amount,
                        'new_balance' => $user->balance
                    ]);

                    // 💰 Calculate and credit affiliate commission
                    $this->calculateAffiliateCommission($user, $payment->amount);
                } elseif (in_array($normalizedStatus, ['cancelled', 'failed'])) {
                    $payment->update(['status' => 'failed']);
                    Log::info('❌ Payment failed', ['transaction_id' => $payment->id]);
                } else {
                    $payment->update(['status' => 'pending']);
                    Log::info('⏳ Payment pending', ['transaction_id' => $payment->id, 'status' => $normalizedStatus]);
                }
            }

            return response()->json([
                'success' => true,
                'payment' => $payment,
                'message' => 'Payment status updated successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('💥 Payment callback failed: ' . $e->getMessage(), [
                'request' => $request->all(),
                'exception' => $e
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error processing payment: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Verify payment from frontend (API endpoint).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyPayment(Request $request)
    {
        try {
            Log::info('🔍 Frontend payment verification request', ['request' => $request->all()]);

            $validated = $request->validate([
                'transaction_id' => 'required|string',
                'status' => 'required|string|in:successful,completed,failed,cancelled,pending',
            ]);

            // 🔎 Flexible transaction lookup
            $transaction = Transaction::where('transaction_id', $validated['transaction_id'])
                ->orWhere('meta->transaction_id', $validated['transaction_id'])
                ->orWhere('meta->id', $validated['transaction_id'])
                ->orWhere('meta->tx_ref', $validated['transaction_id'])
                ->first();

            if (!$transaction) {
                Log::error('❌ Transaction not found', [
                    'transaction_id' => $validated['transaction_id'],
                    'user_id' => Auth::id()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Transaction not found',
                ], 404);
            }

            // ✅ If already completed, return existing data
            if ($transaction->status === 'completed') {
                Log::info('✅ Payment already verified', ['transaction_id' => $transaction->id]);
                return response()->json([
                    'success' => true,
                    'data' => $transaction,
                    'message' => 'Payment already verified',
                ]);
            }

            Log::info('🔄 Processing payment verification', [
                'transaction_id' => $transaction->id,
                'current_status' => $transaction->status,
                'requested_status' => $validated['status']
            ]);

            // 🔍 Verify via Flutterwave if applicable
            if (
                $transaction->payment_method === 'flutterwave' &&
                in_array($validated['status'], ['successful', 'completed'])
            ) {
                $verification = $this->verifyFlutterwavePayment($validated['transaction_id']);

                if ($verification && $verification['status'] === 'success') {
                    $flutterwaveStatus = strtolower($verification['data']['status'] ?? '');

                    Log::info('🔍 Flutterwave verification result', [
                        'flutterwave_status' => $flutterwaveStatus,
                        'transaction_id' => $validated['transaction_id']
                    ]);

                    if ($flutterwaveStatus === 'successful') {
                        $transaction->update([
                            'status' => 'completed',
                            'meta' => json_encode($verification['data']),
                        ]);

                        // 🏦 Safely credit user balance
                        if ($transaction->user) {
                            $transaction->user->increment('balance', $transaction->amount);
                            Log::info('💰 User balance credited', [
                                'user_id' => $transaction->user_id,
                                'amount' => $transaction->amount,
                                'new_balance' => $transaction->user->balance
                            ]);

                            // 💰 Calculate and credit affiliate commission
                            $this->calculateAffiliateCommission($transaction->user, $transaction->amount);
                        }
                    } else {
                        $transaction->update(['status' => 'failed']);
                        Log::info('❌ Flutterwave payment failed', [
                            'transaction_id' => $transaction->id,
                            'flutterwave_status' => $flutterwaveStatus
                        ]);
                    }
                } else {
                    $transaction->update(['status' => 'failed']);
                    Log::error('❌ Flutterwave verification failed', [
                        'transaction_id' => $transaction->id,
                        'verification_response' => $verification
                    ]);
                }
            } elseif (
                $transaction->payment_method === 'korapay' &&
                in_array($validated['status'], ['successful', 'completed', 'success'])
            ) {
                // 🔍 Verify via Korapay
                $verification = $this->verifyKorapayPayment($validated['transaction_id']);

                if ($verification && $verification['status']) {
                    $korapayStatus = strtolower($verification['data']['status'] ?? '');

                    Log::info('🔍 Korapay verification result', [
                        'korapay_status' => $korapayStatus,
                        'transaction_id' => $validated['transaction_id']
                    ]);

                    if ($korapayStatus === 'success') {
                        $transaction->update([
                            'status' => 'completed',
                            'meta' => json_encode($verification['data']),
                        ]);

                        if ($transaction->user) {
                            $transaction->user->increment('balance', $transaction->amount);
                            Log::info('💰 User balance credited (Korapay)', [
                                'user_id' => $transaction->user_id,
                                'amount' => $transaction->amount,
                                'new_balance' => $transaction->user->balance
                            ]);

                            $this->calculateAffiliateCommission($transaction->user, $transaction->amount);
                        }
                    } else {
                        $transaction->update(['status' => 'failed']);
                        Log::info('❌ Korapay payment failed', [
                            'transaction_id' => $transaction->id,
                            'korapay_status' => $korapayStatus
                        ]);
                    }
                } else {
                    $transaction->update(['status' => 'failed']);
                    Log::error('❌ Korapay verification failed', [
                        'transaction_id' => $transaction->id,
                        'verification_response' => $verification
                    ]);
                }
            } else {
                // Other payment methods
                $newStatus = in_array($validated['status'], ['successful', 'completed'])
                    ? 'completed' : 'failed';

                $transaction->update(['status' => $newStatus]);

                if ($newStatus === 'completed' && $transaction->user) {
                    $transaction->user->increment('balance', $transaction->amount);
                    Log::info('💰 Balance credited (direct update)', [
                        'transaction_id' => $transaction->id,
                        'user_id' => $transaction->user_id,
                        'amount' => $transaction->amount
                    ]);

                    // 💰 Calculate and credit affiliate commission
                    $this->calculateAffiliateCommission($transaction->user, $transaction->amount);
                }
            }

            $transaction->refresh();

            return response()->json([
                'success' => true,
                'data' => $transaction,
                'message' => 'Payment verified successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('💥 Payment verification failed: ' . $e->getMessage(), [
                'request' => $request->all(),
                'exception' => $e
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Payment verification failed: ' . $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Create payment link based on payment method.
     *
     * @param string $method
     * @param array $data
     * @return string|null
     */
    private function createPaymentLink(string $method, array $data): ?string
    {
        switch ($method) {
            case 'flutterwave':
                return $this->createFlutterwavePaymentLink($data);
            case 'paystack':
                return $this->createPaystackPaymentLink($data);
            case 'korapay':
                return $this->createKorapayPaymentLink($data);
            default:
                throw new \InvalidArgumentException("Unsupported payment method: {$method}");
        }
    }

    /**
     * Create a Flutterwave payment link.
     *
     * @param array $data
     * @return string|null
     */
    private function createFlutterwavePaymentLink(array $data): ?string
    {
        try {
            $flutterwaveKey = config('services.flutterwave.secret_key');

            if (empty($flutterwaveKey)) {
                throw new \RuntimeException('Flutterwave secret key is not configured');
            }

            Log::debug('🔗 Creating Flutterwave payment link', ['data' => $data]);

            $client = new Client();
            $response = $client->post('https://api.flutterwave.com/v3/payments', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $flutterwaveKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $data,
            ]);

            $body = json_decode((string)$response->getBody(), true);

            if (!isset($body['status']) || $body['status'] !== 'success') {
                Log::error('❌ Flutterwave payment failed', ['response' => $body]);
                throw new \RuntimeException($body['message'] ?? 'Flutterwave payment failed');
            }

            Log::info('✅ Flutterwave payment link created', [
                'transaction_ref' => $data['tx_ref'],
                'payment_url' => $body['data']['link'] ?? null
            ]);

            return $body['data']['link'] ?? null;
        } catch (\Exception $e) {
            Log::error('💥 Flutterwave payment error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Create a Paystack payment link.
     *
     * @param array $data
     * @return string|null
     */
    private function createPaystackPaymentLink(array $data): ?string
    {
        try {
            $paystackKey = config('services.paystack.secret_key');

            if (empty($paystackKey)) {
                throw new \RuntimeException('Paystack secret key is not configured');
            }

            $client = new Client();
            $response = $client->post('https://api.paystack.co/transaction/initialize', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $paystackKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'email' => $data['customer']['email'],
                    'amount' => $data['amount'] * 100, // Paystack uses kobo
                    'reference' => $data['tx_ref'],
                    'callback_url' => $data['redirect_url'],
                    'metadata' => $data['meta'],
                ],
            ]);

            $body = json_decode($response->getBody(), true);

            if (!$body['status']) {
                Log::error('❌ Paystack payment failed', ['response' => $body]);
                throw new \RuntimeException($body['message'] ?? 'Paystack payment failed');
            }

            Log::info('✅ Paystack payment link created', [
                'transaction_ref' => $data['tx_ref'],
                'payment_url' => $body['data']['authorization_url'] ?? null
            ]);

            return $body['data']['authorization_url'] ?? null;
        } catch (\Exception $e) {
            Log::error('💥 Paystack payment error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Verify Flutterwave payment.
     *
     * @param string $transactionId
     * @return array|null
     */
    private function verifyFlutterwavePayment(string $transactionId): ?array
    {
        try {
            $flutterwaveKey = config('services.flutterwave.secret_key');

            if (empty($flutterwaveKey)) {
                throw new \RuntimeException('Flutterwave secret key is not configured');
            }

            Log::debug('🔍 Verifying Flutterwave payment', ['transaction_id' => $transactionId]);

            $client = new Client();
            $response = $client->get("https://api.flutterwave.com/v3/transactions/{$transactionId}/verify", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $flutterwaveKey,
                    'Content-Type' => 'application/json',
                ],
            ]);

            $body = json_decode($response->getBody(), true);

            if (!isset($body['status']) || $body['status'] !== 'success') {
                Log::error('❌ Flutterwave verification failed', ['response' => $body]);
                throw new \RuntimeException($body['message'] ?? 'Payment verification failed');
            }

            Log::info('✅ Flutterwave payment verified successfully', [
                'transaction_id' => $transactionId,
                'status' => $body['data']['status'] ?? 'unknown'
            ]);

            return $body;
        } catch (\Exception $e) {
            Log::error('💥 Flutterwave verification error: ' . $e->getMessage(), [
                'transaction_id' => $transactionId
            ]);
            throw $e;
        }
    }

    /**
     * Create a Korapay payment link using Checkout Redirect.
     *
     * @param array $data
     * @return string|null
     */
    private function createKorapayPaymentLink(array $data): ?string
    {
        try {
            $korapayKey = config('services.korapay.secret_key');

            if (empty($korapayKey)) {
                throw new \RuntimeException('Korapay secret key is not configured');
            }

            Log::debug('🔗 Creating Korapay payment link', ['data' => $data]);

            $client = new Client();
            $response = $client->post('https://api.korapay.com/merchant/api/v1/charges/initialize', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $korapayKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'amount' => $data['amount'],
                    'redirect_url' => $data['redirect_url'],
                    'currency' => $data['currency'] ?? 'NGN',
                    'reference' => $data['tx_ref'],
                    'narration' => 'Payment for ' . ($data['customer']['name'] ?? 'User'),
                    'channels' => ['card', 'bank_transfer', 'pay_with_bank', 'mobile_money'],
                    'default_channel' => 'card',
                    'customer' => [
                        'email' => $data['customer']['email'],
                        'name' => $data['customer']['name'] ?? '',
                    ],
                    'notification_url' => rtrim(config('app.url'), '/') . '/api/payment/korapay/webhook',
                    'merchant_bears_cost' => true,
                ],
            ]);

            $body = json_decode((string)$response->getBody(), true);

            if (!isset($body['status']) || !$body['status']) {
                Log::error('❌ Korapay payment failed', ['response' => $body]);
                throw new \RuntimeException($body['message'] ?? 'Korapay payment initialization failed');
            }

            Log::info('✅ Korapay payment link created', [
                'transaction_ref' => $data['tx_ref'],
                'checkout_url' => $body['data']['checkout_url'] ?? null
            ]);

            return $body['data']['checkout_url'] ?? null;
        } catch (\Exception $e) {
            Log::error('💥 Korapay payment error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Verify Korapay payment.
     *
     * @param string $reference
     * @return array|null
     */
    private function verifyKorapayPayment(string $reference): ?array
    {
        try {
            $korapayKey = config('services.korapay.secret_key');

            if (empty($korapayKey)) {
                throw new \RuntimeException('Korapay secret key is not configured');
            }

            Log::debug('🔍 Verifying Korapay payment', ['reference' => $reference]);

            $client = new Client();
            $response = $client->get("https://api.korapay.com/merchant/api/v1/charges/{$reference}", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $korapayKey,
                    'Content-Type' => 'application/json',
                ],
            ]);

            $body = json_decode($response->getBody(), true);

            if (!isset($body['status']) || !$body['status']) {
                Log::error('❌ Korapay verification failed', ['response' => $body]);
                throw new \RuntimeException($body['message'] ?? 'Korapay payment verification failed');
            }

            Log::info('✅ Korapay payment verified successfully', [
                'reference' => $reference,
                'status' => $body['data']['status'] ?? 'unknown'
            ]);

            return $body;
        } catch (\Exception $e) {
            Log::error('💥 Korapay verification error: ' . $e->getMessage(), [
                'reference' => $reference
            ]);
            throw $e;
        }
    }

    /**
     * Handle Korapay webhook notification.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function handleKorapayWebhook(Request $request)
    {
        try {
            Log::info('🔄 Korapay webhook received', ['payload' => $request->all()]);

            $event = $request->input('event');
            $data = $request->input('data');

            if ($event !== 'charge.success') {
                Log::info('⏭️ Ignoring non-success Korapay webhook event', ['event' => $event]);
                return response()->json(['status' => 'ignored'], 200);
            }

            $reference = $data['payment_reference'] ?? $data['reference'] ?? null;

            if (!$reference) {
                throw new \Exception('Missing reference in Korapay webhook');
            }

            $payment = Transaction::where('transaction_id', $reference)->first();

            if (!$payment) {
                Log::error('❌ Transaction not found for Korapay webhook', ['reference' => $reference]);
                return response()->json(['status' => 'not_found'], 404);
            }

            if ($payment->status === 'completed') {
                Log::info('✅ Korapay webhook: Payment already completed', ['transaction_id' => $payment->id]);
                return response()->json(['status' => 'already_completed'], 200);
            }

            // Verify the payment server-side
            $verification = $this->verifyKorapayPayment($reference);

            if ($verification && $verification['status']) {
                $korapayStatus = strtolower($verification['data']['status'] ?? '');

                if ($korapayStatus === 'success') {
                    $amountPaid = $verification['data']['amount'] ?? 0;
                    $expectedAmount = $payment->amount;

                    if (abs($amountPaid - $expectedAmount) > 0.01) {
                        Log::error('❌ Korapay amount mismatch', [
                            'paid' => $amountPaid,
                            'expected' => $expectedAmount
                        ]);
                        return response()->json(['status' => 'amount_mismatch'], 400);
                    }

                    $payment->update([
                        'status' => 'completed',
                        'meta' => json_encode($verification['data'] ?? []),
                    ]);

                    $user = $payment->user;
                    if ($user) {
                        $user->balance += $payment->amount;
                        $user->save();

                        Log::info('💰 Korapay webhook: Balance credited', [
                            'user_id' => $user->id,
                            'amount' => $payment->amount,
                            'new_balance' => $user->balance
                        ]);

                        $this->calculateAffiliateCommission($user, $payment->amount);
                    }
                } else {
                    $payment->update(['status' => 'failed']);
                    Log::info('❌ Korapay webhook: Payment failed', ['status' => $korapayStatus]);
                }
            }

            return response()->json(['status' => 'processed'], 200);
        } catch (\Exception $e) {
            Log::error('💥 Korapay webhook error: ' . $e->getMessage(), [
                'payload' => $request->all(),
                'exception' => $e
            ]);

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Initiate a manual payment (OPay bank transfer).
     * Creates a pending transaction and notifies admin via email.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function initiateManualPayment(Request $request)
    {
        try {
            $validated = $request->validate([
                'amount' => 'required|numeric|min:100',
                'payment_method' => 'required|string|in:opay_manual',
                'proof_method' => 'required|string|in:whatsapp,email',
            ]);

            $user = Auth::user();
            $transactionRef = 'MAN_' . uniqid();

            $payment = Transaction::create([
                'user_id' => $user->id,
                'transaction_id' => $transactionRef,
                'amount' => $validated['amount'],
                'currency' => $user->currency ?? 'NGN',
                'charge' => 0.00,
                'transaction_type' => 'deposit',
                'description' => 'Manual deposit via OPay bank transfer (pending admin approval)',
                'status' => 'pending',
                'payment_method' => 'opay_manual',
                'meta' => json_encode([
                    'proof_method' => $validated['proof_method'],
                    'user_email' => $user->email,
                    'user_name' => ($user->first_name ?? '') . ' ' . ($user->last_name ?? ''),
                ]),
            ]);

            Log::info('📝 Manual payment initiated', [
                'transaction_id' => $transactionRef,
                'user_id' => $user->id,
                'amount' => $validated['amount'],
                'proof_method' => $validated['proof_method'],
            ]);

            // Send admin email notification
            try {
                $adminEmail = config('mail.from.address');
                $frontendUrl = rtrim(config('app.frontend_url', config('app.url')), '/');
                $approveLink = $frontendUrl . '/admin/users/' . $user->id . '/transactions';

                $emailBody = "
                    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 30px; background-color: #f8f9fa;'>
                        <div style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 25px; border-radius: 12px 12px 0 0; text-align: center;'>
                            <h1 style='color: white; margin: 0; font-size: 24px;'>💰 New Manual Payment</h1>
                            <p style='color: rgba(255,255,255,0.85); margin: 8px 0 0 0;'>Pending Approval Required</p>
                        </div>
                        <div style='background: white; padding: 30px; border-radius: 0 0 12px 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.08);'>
                            <div style='background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 20px; margin-bottom: 20px;'>
                                <p style='margin: 0 0 5px 0; color: #666; font-size: 13px;'>Amount</p>
                                <p style='margin: 0; font-size: 28px; font-weight: bold; color: #16a34a;'>₦" . number_format($validated['amount'], 2) . "</p>
                            </div>
                            <table style='width: 100%; border-collapse: collapse; margin-bottom: 20px;'>
                                <tr style='border-bottom: 1px solid #f0f0f0;'>
                                    <td style='padding: 12px 0; color: #666;'>Customer</td>
                                    <td style='padding: 12px 0; text-align: right; font-weight: 600;'>{$user->first_name} {$user->last_name}</td>
                                </tr>
                                <tr style='border-bottom: 1px solid #f0f0f0;'>
                                    <td style='padding: 12px 0; color: #666;'>Email</td>
                                    <td style='padding: 12px 0; text-align: right; font-weight: 600;'>{$user->email}</td>
                                </tr>
                                <tr style='border-bottom: 1px solid #f0f0f0;'>
                                    <td style='padding: 12px 0; color: #666;'>Reference</td>
                                    <td style='padding: 12px 0; text-align: right; font-weight: 600; font-family: monospace;'>{$transactionRef}</td>
                                </tr>
                                <tr style='border-bottom: 1px solid #f0f0f0;'>
                                    <td style='padding: 12px 0; color: #666;'>Proof via</td>
                                    <td style='padding: 12px 0; text-align: right; font-weight: 600;'>" . ucfirst($validated['proof_method']) . "</td>
                                </tr>
                                <tr>
                                    <td style='padding: 12px 0; color: #666;'>Payment Method</td>
                                    <td style='padding: 12px 0; text-align: right; font-weight: 600;'>OPay Bank Transfer</td>
                                </tr>
                            </table>
                            <a href='{$approveLink}' style='display: block; text-align: center; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 14px 28px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 16px;'>Review & Approve Transaction</a>
                            <p style='text-align: center; color: #999; font-size: 12px; margin-top: 15px;'>Click the button above to go directly to the user's transactions page.</p>
                        </div>
                    </div>
                ";

                Mail::send([], [], function ($message) use ($adminEmail, $user, $validated, $transactionRef, $emailBody) {
                    $message->to($adminEmail)
                        ->subject('💰 New Manual Payment - ₦' . number_format($validated['amount'], 2) . ' from ' . $user->first_name . ' ' . $user->last_name)
                        ->html($emailBody);
                });

                Log::info('📧 Admin notified about manual payment', [
                    'admin_email' => $adminEmail,
                    'transaction_ref' => $transactionRef,
                ]);
            } catch (\Exception $e) {
                Log::warning('⚠️ Failed to send admin email for manual payment: ' . $e->getMessage());
                // Don't fail the request if email fails
            }

            return response()->json([
                'success' => true,
                'transaction_id' => $transactionRef,
                'message' => 'Manual payment recorded. Please submit your proof of payment.',
            ]);
        } catch (\Exception $e) {
            Log::error('💥 Manual payment initiation failed: ' . $e->getMessage(), [
                'user_id' => Auth::id(),
                'exception' => $e
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to record manual payment: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get payment history for authenticated user.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function paymentHistory()
    {
        try {
            $transactions = Transaction::where('user_id', Auth::id())
                ->latest()
                ->get();

            Log::info('📊 Fetched payment history', [
                'user_id' => Auth::id(),
                'transaction_count' => $transactions->count()
            ]);

            return response()->json($transactions);
        } catch (\Exception $e) {
            Log::error('💥 Payment history fetch failed: ' . $e->getMessage(), [
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payment history',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Calculate and credit affiliate commission.
     *
     * @param User $user
     * @param float $amount
     * @return void
     */
    private function calculateAffiliateCommission(User $user, float $amount): void
    {
        try {
            // Check if user was referred by an affiliate
            $referral = AffiliateReferral::where('referred_user_id', $user->id)
                ->where('status', 'active')
                ->first();

            if (!$referral) {
                Log::debug('No active affiliate referral found for user', ['user_id' => $user->id]);
                return;
            }

            // Get the affiliate program
            $affiliateProgram = AffiliateProgram::where('user_id', $referral->referrer_id)
                ->where('is_active', true)
                ->first();

            if (!$affiliateProgram) {
                Log::debug('No active affiliate program found for referrer', [
                    'referrer_id' => $referral->referrer_id
                ]);
                return;
            }

            // Calculate commission (default 5% if not specified)
            $commissionRate = $affiliateProgram->commission_rate ?? 5.0;
            $commissionAmount = ($amount * $commissionRate) / 100;

            // Credit the referrer's affiliate balance
            $referrer = User::find($referral->referrer_id);
            if ($referrer) {
                // Update affiliate program earnings
                $affiliateProgram->total_earnings += $commissionAmount;
                $affiliateProgram->available_balance += $commissionAmount;
                $affiliateProgram->save();

                // Update referral record
                $referral->total_commission += $commissionAmount;
                $referral->save();

                // Create a transaction record for the commission
                Transaction::create([
                    'user_id' => $referrer->id,
                    'transaction_id' => 'COMM_' . uniqid(),
                    'amount' => $commissionAmount,
                    'currency' => $user->currency ?? 'NGN',
                    'charge' => 0.00,
                    'transaction_type' => 'affiliate_commission',
                    'description' => "Affiliate commission from {$user->first_name} {$user->last_name}'s deposit",
                    'status' => 'completed',
                    'payment_method' => 'affiliate',
                    'meta' => json_encode([
                        'referred_user_id' => $user->id,
                        'deposit_amount' => $amount,
                        'commission_rate' => $commissionRate,
                    ]),
                ]);

                Log::info('💰 Affiliate commission credited', [
                    'referrer_id' => $referrer->id,
                    'referred_user_id' => $user->id,
                    'deposit_amount' => $amount,
                    'commission_amount' => $commissionAmount,
                    'commission_rate' => $commissionRate,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('💥 Affiliate commission calculation failed: ' . $e->getMessage(), [
                'user_id' => $user->id,
                'amount' => $amount,
                'exception' => $e
            ]);
            // Don't throw the exception - we don't want to fail the payment if commission fails
        }
    }
}
