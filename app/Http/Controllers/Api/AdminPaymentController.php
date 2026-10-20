<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminPaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => [
                'sometimes',
                'string',
                'in:PENDING,PROCESSING,COMPLETED,FAILED,CANCELLED,REFUNDED',
            ],
            'method' => [
                'sometimes',
                'string',
                'in:MPESA,CARD,CASH,BANK_TRANSFER',
            ],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Payment::query()
            ->with([
                'user:id,name,email',
                'order:id,user_id,order_number,total,status,payment_status',
            ])
            ->latest();

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (!empty($validated['method'])) {
            $query->where('method', $validated['method']);
        }

        if (!empty($validated['search'])) {
            $search = $validated['search'];

            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('payment_reference', 'like', "%{$search}%")
                    ->orWhere('transaction_reference', 'like', "%{$search}%")
                    ->orWhere('phone_number', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    })
                    ->orWhereHas('order', function ($orderQuery) use ($search) {
                        $orderQuery->where(
                            'order_number',
                            'like',
                            "%{$search}%"
                        );
                    });
            });
        }

        $payments = $query
            ->paginate($validated['per_page'] ?? 20)
            ->withQueryString();

        return response()->json([
            'success' => true,
            'message' => 'Payments retrieved successfully.',
            'data' => $payments,
        ]);
    }

    public function show(Payment $payment): JsonResponse
    {
        $payment->load([
            'user:id,name,email',
            'order.items',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Payment details retrieved successfully.',
            'data' => [
                'payment' => $payment,
            ],
        ]);
    }

    /**
     * Record an administrative payment status change. This does not verify
     * a transaction with a payment provider.
     */
    public function updateStatus(
        Request $request,
        Payment $payment
    ): JsonResponse {
        $validated = $request->validate([
            'status' => [
                'required',
                'string',
                'in:PENDING,PROCESSING,COMPLETED,FAILED,CANCELLED,REFUNDED',
            ],
            'transaction_reference' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],
            'failure_reason' => [
                'sometimes',
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        $updatedPayment = DB::transaction(function () use (
            $payment,
            $validated
        ) {
            $lockedPayment = Payment::whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $order = $lockedPayment->order()
                ->lockForUpdate()
                ->firstOrFail();

            $currentStatus = $lockedPayment->status;
            $newStatus = $validated['status'];

            if ($currentStatus === 'REFUNDED' && $newStatus !== 'REFUNDED') {
                throw ValidationException::withMessages([
                    'status' => [
                        'A refunded payment cannot be reopened or changed.',
                    ],
                ]);
            }

            if (
                $currentStatus === 'COMPLETED'
                && !in_array($newStatus, ['COMPLETED', 'REFUNDED'], true)
            ) {
                throw ValidationException::withMessages([
                    'status' => [
                        'A completed payment can only remain completed or be refunded.',
                    ],
                ]);
            }

            // Failed or cancelled payments are final; the customer creates
            // a new payment instead.
            if (
                in_array($currentStatus, ['FAILED', 'CANCELLED'], true)
                && $newStatus !== $currentStatus
            ) {
                throw ValidationException::withMessages([
                    'status' => [
                        'A failed or cancelled payment cannot be reopened. Ask the customer to create a new payment.',
                    ],
                ]);
            }

            if (
                $newStatus === 'COMPLETED'
                && $order->status === 'CANCELLED'
            ) {
                throw ValidationException::withMessages([
                    'status' => [
                        'A payment for a cancelled order cannot be completed.',
                    ],
                ]);
            }

            // An order can only have one completed payment.
            if ($newStatus === 'COMPLETED') {
                $alreadyPaid = Payment::where('order_id', $lockedPayment->order_id)
                    ->where('id', '!=', $lockedPayment->id)
                    ->where('status', 'COMPLETED')
                    ->exists();

                if ($alreadyPaid) {
                    throw ValidationException::withMessages([
                        'status' => [
                            'This order already has a completed payment.',
                        ],
                    ]);
                }
            }

            if (
                $newStatus === 'REFUNDED'
                && $currentStatus !== 'COMPLETED'
            ) {
                throw ValidationException::withMessages([
                    'status' => [
                        'Only a completed payment can be marked as refunded.',
                    ],
                ]);
            }

            $lockedPayment->status = $newStatus;

            if (array_key_exists('transaction_reference', $validated)) {
                $lockedPayment->transaction_reference =
                    $validated['transaction_reference'];
            }

            if ($newStatus === 'COMPLETED') {
                $lockedPayment->paid_at ??= now();
                $lockedPayment->failure_reason = null;
            } elseif ($newStatus === 'REFUNDED') {
                $lockedPayment->failure_reason = null;
            } elseif (in_array($newStatus, ['FAILED', 'CANCELLED'], true)) {
                $lockedPayment->failure_reason =
                    $validated['failure_reason'] ?? null;

                $lockedPayment->paid_at = null;
            } else {
                $lockedPayment->failure_reason = null;
                $lockedPayment->paid_at = null;
            }

            $lockedPayment->save();

            // Don't let an unrelated payment record un-pay a settled order.
            $orderAlreadySettled = in_array(
                $order->payment_status,
                ['PAID', 'REFUNDED'],
                true
            );

            if (
                !$orderAlreadySettled
                || in_array($newStatus, ['COMPLETED', 'REFUNDED'], true)
            ) {
                $order->payment_status = match ($newStatus) {
                    'COMPLETED' => 'PAID',
                    'REFUNDED' => 'REFUNDED',
                    'FAILED', 'CANCELLED' => 'FAILED',
                    default => 'PENDING',
                };
            }

            if (
                $newStatus === 'COMPLETED'
                && $order->payment_reference === null
            ) {
                $order->payment_reference =
                    $lockedPayment->payment_reference;
            }

            $order->save();

            return $lockedPayment->fresh()->load([
                'user:id,name,email',
                'order:id,user_id,order_number,total,status,payment_status',
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Payment status updated successfully.',
            'data' => [
                'payment' => $updatedPayment,
            ],
        ]);
    }
}