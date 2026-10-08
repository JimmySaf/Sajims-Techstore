<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminOrderController extends Controller
{
    /**
     * Get all orders.
     */
    public function index(Request $request): JsonResponse
    {
        $orders = Order::with([
            'user:id,name,email',
            'items',
        ])
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', $request->status)
            )
            ->when(
                $request->filled('payment_status'),
                fn ($query) => $query->where(
                    'payment_status',
                    $request->payment_status
                )
            )
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'message' => 'Orders retrieved successfully.',
            'data' => $orders,
        ]);
    }

    /**
     * View an order.
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
            'data' => [
                'order' => $order,
            ],
        ]);
    }

    /**
     * Update order status.
     */
    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        $validated = $request->validate([
            'status' => [
                'required',
                'in:PENDING,CONFIRMED,PROCESSING,SHIPPED,DELIVERED,CANCELLED',
            ],
        ]);

        $order->update([
            'status' => $validated['status'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Order status updated successfully.',
            'data' => [
                'order' => $order->fresh(['user', 'items']),
            ],
        ]);
    }

    /**
     * Update payment status.
     */
    public function updatePaymentStatus(
        Request $request,
        Order $order
    ): JsonResponse {
        $validated = $request->validate([
            'payment_status' => [
                'required',
                'in:PENDING,PAID,FAILED,REFUNDED',
            ],
        ]);

        $order->update([
            'payment_status' => $validated['payment_status'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Payment status updated successfully.',
            'data' => [
                'order' => $order->fresh(['user', 'items']),
            ],
        ]);
    }
}