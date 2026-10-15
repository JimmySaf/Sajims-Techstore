<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryAdjustment;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminOrderController extends Controller
{
    /**
     * Display all orders for the administrator.
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
            'payment',
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
            'payment',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Order retrieved successfully.',
            'data' => $order,
        ]);
    }

    /**
     * Update an order's fulfilment status.
     *
     * Payment status is managed separately by AdminPaymentController.
     * Cancellation is handled here transactionally so stock is restored
     * exactly once.
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

        $newStatus = $validated['status'];

        $updatedOrder = DB::transaction(function () use ($order, $newStatus) {
            $lockedOrder = Order::whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $currentStatus = $lockedOrder->status;

            if ($currentStatus === $newStatus) {
                return $lockedOrder;
            }

            $allowedTransitions = [
                'PENDING' => ['CONFIRMED', 'CANCELLED'],
                'CONFIRMED' => ['PROCESSING', 'CANCELLED'],
                'PROCESSING' => ['SHIPPED', 'CANCELLED'],
                'SHIPPED' => ['DELIVERED'],
                'DELIVERED' => [],
                'CANCELLED' => [],
            ];

            if (!in_array(
                $newStatus,
                $allowedTransitions[$currentStatus] ?? [],
                true
            )) {
                abort(response()->json([
                    'success' => false,
                    'message' => "Order status cannot change from {$currentStatus} to {$newStatus}.",
                ], 422));
            }

            if ($newStatus === 'CANCELLED') {
                if (in_array(
                    $lockedOrder->payment_status,
                    ['PAID', 'REFUNDED'],
                    true
                )) {
                    abort(response()->json([
                        'success' => false,
                        'message' => 'This order has been paid or refunded. Resolve its payment status before cancelling the order.',
                    ], 422));
                }

                $lockedOrder->load('items');

                foreach ($lockedOrder->items as $item) {
                    $product = Product::whereKey($item->product_id)
                        ->lockForUpdate()
                        ->first();

                    // Products can be absent if historical records are retained
                    // after a product was removed by an administrator.
                    if (!$product) {
                        continue;
                    }

                    $previousStock = (int) $product->stock;
                    $quantity = (int) $item->quantity;

                    $product->increment('stock', $quantity);
                    $product->refresh();

                    $newStock = (int) $product->stock;

                    InventoryAdjustment::create([
                        'product_id' => $product->id,
                        'user_id' => $lockedOrder->user_id,
                        'quantity_change' => $quantity,
                        'previous_stock' => $previousStock,
                        'new_stock' => $newStock,
                        'type' => 'RETURN',
                        'reason' => 'Stock restored after admin cancellation of order '
                            . $lockedOrder->order_number,
                    ]);

                    if (
                        $product->status === 'OUT_OF_STOCK'
                        && $newStock > 0
                    ) {
                        $product->update([
                            'status' => 'ACTIVE',
                        ]);
                    }
                }
            }

            $lockedOrder->update([
                'status' => $newStatus,
            ]);

            return $lockedOrder;
        });

        $updatedOrder->load([
            'user:id,name,email',
            'items.product',
            'payment',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Order status updated successfully.',
            'data' => $updatedOrder,
        ]);
    }
}