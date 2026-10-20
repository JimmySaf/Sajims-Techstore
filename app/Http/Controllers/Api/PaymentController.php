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
            $lockedOrder = Order::whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

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

            if ($lockedOrder->status === 'DELIVERED') {
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

            // Lock this order's existing payments before checking them.
            $existingPayments = Payment::where('order_id', $lockedOrder->id)
                ->lockForUpdate()
                ->get();

            $activePayment = $existingPayments->first(
                fn (Payment $existing) => in_array(
                    $existing->status,
                    ['PENDING', 'PROCESSING'],
                    true
                )
            );

            if ($activePayment) {
                abort(response()->json([
                    'success' => false,
                    'message' => 'A payment is already pending for this order.',
                    'payment_reference' => $activePayment->payment_reference,
                ], 409));
            }

            if ($existingPayments->contains('status', 'COMPLETED')) {
                abort(response()->json([
                    'success' => false,
                    'message' => 'This order already has a completed payment.',
                ], 409));
            }

            $paymentReference = $this->generatePaymentReference();

            // Failed or cancelled attempts stay on record; a retry always
            // creates a fresh payment with its own reference.
            $payment = Payment::create([
                'order_id' => $lockedOrder->id,
                'user_id' => $request->user()->id,
                'payment_reference' => $paymentReference,
                'amount' => $lockedOrder->total,
                'method' => $validated['method'],
                'status' => 'PENDING',
                'phone_number' => $validated['phone_number'] ?? null,
            ]);

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