<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminOrderController extends Controller
{
    /**
     * Display all orders for the admin.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => [
                'nullable',
                Rule::in([
                    'PENDING',
                    'CONFIRMED',
                    'PROCESSING',
                    'SHIPPED',
                    'DELIVERED',
                    'CANCELLED',
                ]),
            ],
            'payment_status' => [
                'nullable',
                Rule::in([
                    'PENDING',
                    'PAID',
                    'FAILED',
                    'REFUNDED',
                ]),
            ],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $orders = Order::with([
            'user:id,name,email',
            'items.product',
        ])
            ->when(
                $validated['status'] ?? null,
                fn ($query, $status) => $query->where('status', $status)
            )
            ->when(
                $validated['payment_status'] ?? null,
                fn ($query, $paymentStatus) => $query->where(
                    'payment_status',
                    $paymentStatus
                )
            )
            ->latest()
            ->paginate($validated['per_page'] ?? 15);

        return response()->json([
            'success' => true,
            'message' => 'Orders retrieved successfully.',
            'data' => $orders,
        ]);
    }

    /**
     * Display a specific order.
     */
    public function show(Order $order): JsonResponse
    {
        $order->load([
            'user:id,name,email',
            'items.product',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Order retrieved successfully.',
            'data' => $order,
        ]);
    }

    /**
     * Update the order's fulfilment status.
     *
     * Payment status is managed separately by AdminPaymentController.
     */
    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        $validated = $request->validate([
            'status' => [
                'required',
                Rule::in([
                    'PENDING',
                    'CONFIRMED',
                    'PROCESSING',
                    'SHIPPED',
                    'DELIVERED',
                    'CANCELLED',
                ]),
            ],
        ]);

        $order->update([
            'status' => $validated['status'],
        ]);

        $order->load([
            'user:id,name,email',
            'items.product',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Order status updated successfully.',
            'data' => $order,
        ]);
    }
}