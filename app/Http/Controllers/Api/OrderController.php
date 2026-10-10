<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    /**
     * Get the authenticated customer's orders.
     */
    public function index(Request $request): JsonResponse
    {
        $orders = $request->user()
            ->orders()
            ->with(['items', 'payment'])
            ->latest()
            ->paginate(10);

        return response()->json([
            'success' => true,
            'message' => 'Orders retrieved successfully.',
            'data' => $orders,
        ]);
    }

    /**
     * Checkout the authenticated customer's cart.
     */
    public function checkout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'shipping_name' => ['required', 'string', 'max:255'],
            'shipping_phone' => ['required', 'string', 'max:30'],
            'shipping_address' => ['required', 'string', 'max:255'],
            'shipping_city' => ['required', 'string', 'max:100'],
            'shipping_notes' => ['nullable', 'string', 'max:1000'],
            'payment_method' => [
                'nullable',
                'string',
                'in:MPESA,CARD,CASH,BANK_TRANSFER',
            ],
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

            $subtotal = 0;

            /*
             * Lock and re-check each product to reduce the risk
             * of overselling when multiple checkouts happen together.
             */
            foreach ($cart->items as $cartItem) {
                $product = Product::whereKey($cartItem->product_id)
                    ->lockForUpdate()
                    ->first();

                if (!$product || $product->status !== 'ACTIVE') {
                    abort(response()->json([
                        'success' => false,
                        'message' => 'A product in your cart is no longer available.',
                        'product_id' => $cartItem->product_id,
                    ], 422));
                }

                if ($cartItem->quantity < 1 ||
                    $cartItem->quantity > $product->stock) {
                    abort(response()->json([
                        'success' => false,
                        'message' => "Insufficient stock for {$product->name}.",
                        'available_stock' => $product->stock,
                        'requested_quantity' => $cartItem->quantity,
                    ], 422));
                }

                // Use the current database price, not an old cart price.
                $cartItem->price = $product->price;
                $cartItem->setRelation('product', $product);

                $subtotal += (float) $product->price * $cartItem->quantity;
            }

            // Shipping is currently free; configurable shipping can be added later.
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
                /** @var Product $product */
                $product = $cartItem->product;

                $quantity = (int) $cartItem->quantity;
                $price = (float) $product->price;

                $order->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_sku' => $product->sku,
                    'price' => $price,
                    'quantity' => $quantity,
                    'subtotal' => $price * $quantity,
                ]);

                $product->decrement('stock', $quantity);
                $product->refresh();

                if ($product->stock <= 0) {
                    $product->update([
                        'status' => 'OUT_OF_STOCK',
                    ]);
                }
            }

            // Remove cart items only after the order is successfully created.
            $cart->items()->delete();

            return $order;
        });

        $order->load(['items', 'payment']);

        return response()->json([
            'success' => true,
            'message' => 'Order placed successfully.',
            'data' => [
                'order' => $order,
            ],
        ], 201);
    }

    /**
     * Get one order belonging to the authenticated customer.
     */
    public function show(Request $request, Order $order): JsonResponse
    {
        if ((int) $order->user_id !== (int) $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to view this order.',
            ], 403);
        }

        $order->load(['items.product', 'payment']);

        return response()->json([
            'success' => true,
            'message' => 'Order retrieved successfully.',
            'data' => [
                'order' => $order,
            ],
        ]);
    }

    /**
     * Cancel an eligible order and restore product stock.
     */
    public function cancel(Request $request, Order $order): JsonResponse
    {
        if ((int) $order->user_id !== (int) $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to cancel this order.',
            ], 403);
        }

        $cancelledOrder = DB::transaction(function () use ($order) {
            $lockedOrder = Order::whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (!in_array($lockedOrder->status, ['PENDING', 'CONFIRMED'], true)) {
                abort(response()->json([
                    'success' => false,
                    'message' => 'This order can no longer be cancelled.',
                ], 422));
            }

            if ($lockedOrder->payment_status === 'PAID') {
                abort(response()->json([
                    'success' => false,
                    'message' => 'This order has been paid. A refund must be handled before cancellation.',
                ], 422));
            }

            $lockedOrder->load('items');

            foreach ($lockedOrder->items as $item) {
                $product = Product::whereKey($item->product_id)
                    ->lockForUpdate()
                    ->first();

                if ($product) {
                    $product->increment('stock', $item->quantity);

                    if ($product->status === 'OUT_OF_STOCK' && $product->fresh()->stock > 0) {
                        $product->update([
                            'status' => 'ACTIVE',
                        ]);
                    }
                }
            }

            $lockedOrder->update([
                'status' => 'CANCELLED',
            ]);

            return $lockedOrder;
        });

        $cancelledOrder->load(['items', 'payment']);

        return response()->json([
            'success' => true,
            'message' => 'Order cancelled successfully.',
            'data' => [
                'order' => $cancelledOrder,
            ],
        ]);
    }

    /**
     * Generate a unique order number.
     */
    private function generateOrderNumber(): string
    {
        do {
            $number = 'SJ-'
                . now()->format('YmdHis')
                . '-'
                . strtoupper(Str::random(5));
        } while (Order::where('order_number', $number)->exists());

        return $number;
    }
}