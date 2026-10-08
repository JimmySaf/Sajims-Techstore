<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    /**
     * Get authenticated customer's orders.
     */
    public function index(Request $request): JsonResponse
    {
        $orders = $request->user()
            ->orders()
            ->with('items')
            ->latest()
            ->paginate(10);

        return response()->json([
            'success' => true,
            'message' => 'Orders retrieved successfully.',
            'data' => $orders,
        ]);
    }

    /**
     * Checkout the current cart.
     */
    public function checkout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'shipping_name' => ['required', 'string', 'max:255'],
            'shipping_phone' => ['required', 'string', 'max:30'],
            'shipping_address' => ['required', 'string', 'max:255'],
            'shipping_city' => ['required', 'string', 'max:100'],
            'shipping_notes' => ['nullable', 'string', 'max:1000'],
            'payment_method' => ['nullable', 'string', 'max:50'],
        ]);

        $order = DB::transaction(function () use ($request, $validated) {

            $cart = Cart::where('user_id', $request->user()->id)
                ->with('items.product')
                ->lockForUpdate()
                ->first();

            if (!$cart || $cart->items->isEmpty()) {
                abort(response()->json([
                    'success' => false,
                    'message' => 'Your cart is empty.',
                ], 422));
            }

            /*
             * Re-check stock during checkout.
             */
            foreach ($cart->items as $cartItem) {
                $product = $cartItem->product;

                if (!$product || $product->status !== 'ACTIVE') {
                    abort(response()->json([
                        'success' => false,
                        'message' => "Product {$cartItem->product_id} is no longer available.",
                    ], 422));
                }

                if ($cartItem->quantity > $product->stock) {
                    abort(response()->json([
                        'success' => false,
                        'message' => "Insufficient stock for {$product->name}.",
                        'available_stock' => $product->stock,
                    ], 422));
                }
            }

            $subtotal = $cart->items->sum(function ($item) {
                return (float) $item->price * $item->quantity;
            });

            /*
             * Shipping can later become dynamic.
             */
            $shippingFee = 0;

            $total = $subtotal + $shippingFee;

            $order = Order::create([
                'user_id' => $request->user()->id,
                'order_number' => $this->generateOrderNumber(),
                'subtotal' => $subtotal,
                'shipping_fee' => $shippingFee,
                'total' => $total,
                'status' => 'PENDING',
                'payment_status' => 'PENDING',
                'payment_method' => $validated['payment_method'] ?? null,
                'shipping_name' => $validated['shipping_name'],
                'shipping_phone' => $validated['shipping_phone'],
                'shipping_address' => $validated['shipping_address'],
                'shipping_city' => $validated['shipping_city'],
                'shipping_notes' => $validated['shipping_notes'] ?? null,
            ]);

            foreach ($cart->items as $cartItem) {
                $product = $cartItem->product;

                $order->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_sku' => $product->sku,
                    'price' => $cartItem->price,
                    'quantity' => $cartItem->quantity,
                    'subtotal' => $cartItem->price * $cartItem->quantity,
                ]);

                /*
                 * Reduce stock.
                 */
                $product->decrement('stock', $cartItem->quantity);

                /*
                 * Automatically mark product out of stock.
                 */
                $product->refresh();

                if ($product->stock <= 0) {
                    $product->update([
                        'status' => 'OUT_OF_STOCK',
                    ]);
                }
            }

            /*
             * Empty cart after successful checkout.
             */
            $cart->items()->delete();

            return $order;
        });

        $order->load('items');

        return response()->json([
            'success' => true,
            'message' => 'Order placed successfully.',
            'data' => [
                'order' => $order,
            ],
        ], 201);
    }

    /**
     * Get one customer's order.
     */
    public function show(Request $request, Order $order): JsonResponse
    {
        if ($order->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to view this order.',
            ], 403);
        }

        $order->load('items');

        return response()->json([
            'success' => true,
            'message' => 'Order retrieved successfully.',
            'data' => [
                'order' => $order,
            ],
        ]);
    }

    /**
     * Cancel a customer's pending order.
     */
    public function cancel(Request $request, Order $order): JsonResponse
    {
        if ($order->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to cancel this order.',
            ], 403);
        }

        if (!in_array($order->status, ['PENDING', 'CONFIRMED'])) {
            return response()->json([
                'success' => false,
                'message' => 'This order can no longer be cancelled.',
            ], 422);
        }

        DB::transaction(function () use ($order) {

            foreach ($order->items as $item) {
                $product = $item->product;

                if ($product) {
                    $product->increment('stock', $item->quantity);

                    if ($product->stock > 0 && $product->status === 'OUT_OF_STOCK') {
                        $product->update([
                            'status' => 'ACTIVE',
                        ]);
                    }
                }
            }

            $order->update([
                'status' => 'CANCELLED',
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Order cancelled successfully.',
            'data' => [
                'order' => $order->fresh('items'),
            ],
        ]);
    }

    /**
     * Generate unique order number.
     */
    private function generateOrderNumber(): string
    {
        do {
            $number = 'SJ-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(5));
        } while (Order::where('order_number', $number)->exists());

        return $number;
    }
}