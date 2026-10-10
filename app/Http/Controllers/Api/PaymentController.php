<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    /**
     * Create a payment record for the authenticated customer's order.
     *
     * This records payment intent only. A payment provider must confirm
     * the transaction before the order can be marked as paid.
     */
    public function store(Request $request, Order $order): JsonResponse
    {
        $validated = $request->validate([
            'method' => [
                'required',
                'string',
                'in:MPESA,CARD,CASH,BANK_TRANSFER',
            ],
            'phone_number' => [
                'required_if:method,MPESA',
                'nullable',
                'string',
                'max:30',
            ],
        ]);

        $payment = DB::transaction(function () use (
            $request,
            $order,
            $validated
        ) {
            // Lock the order before checking and creating its payment.
            $lockedOrder = Order::whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Do not allow customers to pay for another customer's order.
            if ((int) $lockedOrder->user_id !== (int) $request->user()->id) {
                abort(response()->json([
                    'success' => false,
                    'message' => 'Order not found.',
                ], 404));
            }

            if ($lockedOrder->status === 'CANCELLED') {
                abort(response()->json([
                    'success' => false,
                    'message' => 'Payment cannot be created for a cancelled order.',
                ], 422));
            }

            if (in_array($lockedOrder->status, ['DELIVERED'], true)) {
                abort(response()->json([
                    'success' => false,
                    'message' => 'This order has already been delivered.',
                ], 422));
            }

            if ($lockedOrder->payment_status === 'PAID') {
                abort(response()->json([
                    'success' => false,
                    'message' => 'This order has already been paid.',
                ], 422));
            }

            if ($lockedOrder->payment_status === 'REFUNDED') {
                abort(response()->json([
                    'success' => false,
                    'message' => 'This order has been refunded.',
                ], 422));
            }

            // Reuse a failed or cancelled payment record for a retry.
            $existingPayment = Payment::where('order_id', $lockedOrder->id)
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($existingPayment && in_array(
                $existingPayment->status,
                ['PENDING', 'PROCESSING'],
                true
            )) {
                abort(response()->json([
                    'success' => false,
                    'message' => 'A payment is already pending for this order.',
                    'payment_reference' => $existingPayment->payment_reference,
                ], 409));
            }

            if ($existingPayment && $existingPayment->status === 'COMPLETED') {
                abort(response()->json([
                    'success' => false,
                    'message' => 'This order already has a completed payment.',
                ], 409));
            }

            $paymentReference = $this->generatePaymentReference();

            if ($existingPayment) {
                $existingPayment->update([
                    'payment_reference' => $paymentReference,
                    'amount' => $lockedOrder->total,
                    'method' => $validated['method'],
                    'status' => 'PENDING',
                    'transaction_reference' => null,
                    'phone_number' => $validated['phone_number'] ?? null,
                    'failure_reason' => null,
                    'paid_at' => null,
                ]);

                $payment = $existingPayment;
            } else {
                $payment = Payment::create([
                    'order_id' => $lockedOrder->id,
                    'user_id' => $request->user()->id,
                    'payment_reference' => $paymentReference,
                    'amount' => $lockedOrder->total,
                    'method' => $validated['method'],
                    'status' => 'PENDING',
                    'phone_number' => $validated['phone_number'] ?? null,
                ]);
            }

            $lockedOrder->update([
                'payment_method' => $validated['method'],
                'payment_reference' => $paymentReference,
                'payment_status' => 'PENDING',
            ]);

            return $payment->fresh();
        });

        return response()->json([
            'success' => true,
            'message' => 'Payment record created. Payment confirmation is pending.',
            'data' => [
                'payment' => $payment,
            ],
        ], 201);
    }

    /**
     * View a payment belonging to the authenticated customer.
     */
    public function show(Request $request, Payment $payment): JsonResponse
    {
        if ((int) $payment->user_id !== (int) $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Payment not found.',
            ], 404);
        }

        $payment->load('order.items');

        return response()->json([
            'success' => true,
            'message' => 'Payment retrieved successfully.',
            'data' => [
                'payment' => $payment,
            ],
        ]);
    }

    /**
     * Generate a unique payment reference.
     */
    
private function generatePaymentReference(): string
{
    do {
        $reference = 'PAY-' . strtoupper(Str::random(10));
    } while (
        Payment::where('payment_reference', $reference)->exists()
    );

    return $reference;
}
}