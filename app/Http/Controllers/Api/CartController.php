<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    /**
     * Get the authenticated user's cart.
     */
    public function index(Request $request): JsonResponse
    {
        $cart = $this->getCart($request);

        return $this->cartResponse($cart);
    }

    /**
     * Add a product to the cart.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $product = Product::findOrFail($validated['product_id']);

        if ($product->status !== 'ACTIVE') {
            return response()->json([
                'success' => false,
                'message' => 'This product is not currently available.',
            ], 422);
        }

        $cart = $this->getCart($request);

        $existingItem = $cart->items()
            ->where('product_id', $product->id)
            ->first();

        $newQuantity = $existingItem
            ? $existingItem->quantity + $validated['quantity']
            : $validated['quantity'];

        if ($newQuantity > $product->stock) {
            return response()->json([
                'success' => false,
                'message' => 'Requested quantity exceeds available stock.',
                'available_stock' => $product->stock,
            ], 422);
        }

        if ($existingItem) {
            $existingItem->update([
                'quantity' => $newQuantity,
                'price' => $product->price,
            ]);
        } else {
            $cart->items()->create([
                'product_id' => $product->id,
                'quantity' => $validated['quantity'],
                'price' => $product->price,
            ]);
        }

        $cart->load('items.product');

        return response()->json([
            'success' => true,
            'message' => 'Product added to cart successfully.',
            'data' => [
                'cart' => $this->formatCart($cart),
            ],
        ], 201);
    }

    /**
     * Update cart item quantity.
     */
    public function update(Request $request, CartItem $cartItem): JsonResponse
    {
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $cart = $this->getCart($request);

        if ($cartItem->cart_id !== $cart->id) {
            return response()->json([
                'success' => false,
                'message' => 'Cart item does not belong to your cart.',
            ], 403);
        }

        $product = $cartItem->product;

        if ($product->status !== 'ACTIVE') {
            return response()->json([
                'success' => false,
                'message' => 'This product is no longer available.',
            ], 422);
        }

        if ($validated['quantity'] > $product->stock) {
            return response()->json([
                'success' => false,
                'message' => 'Requested quantity exceeds available stock.',
                'available_stock' => $product->stock,
            ], 422);
        }

        $cartItem->update([
            'quantity' => $validated['quantity'],
            'price' => $product->price,
        ]);

        $cart->load('items.product');

        return response()->json([
            'success' => true,
            'message' => 'Cart updated successfully.',
            'data' => [
                'cart' => $this->formatCart($cart),
            ],
        ]);
    }

    /**
     * Remove an item from the cart.
     */
    public function destroy(Request $request, CartItem $cartItem): JsonResponse
    {
        $cart = $this->getCart($request);

        if ($cartItem->cart_id !== $cart->id) {
            return response()->json([
                'success' => false,
                'message' => 'Cart item does not belong to your cart.',
            ], 403);
        }

        $cartItem->delete();

        $cart->load('items.product');

        return response()->json([
            'success' => true,
            'message' => 'Cart item removed successfully.',
            'data' => [
                'cart' => $this->formatCart($cart),
            ],
        ]);
    }

    /**
     * Clear the authenticated user's cart.
     */
    public function clear(Request $request): JsonResponse
    {
        $cart = $this->getCart($request);

        $cart->items()->delete();

        $cart->load('items.product');

        return response()->json([
            'success' => true,
            'message' => 'Cart cleared successfully.',
            'data' => [
                'cart' => $this->formatCart($cart),
            ],
        ]);
    }

    /**
     * Get or create the user's cart.
     */
    private function getCart(Request $request): Cart
    {
        $cart = Cart::firstOrCreate([
            'user_id' => $request->user()->id,
        ]);

        return $cart->load('items.product');
    }

    /**
     * Format cart response.
     */
    private function formatCart(Cart $cart): array
    {
        return [
            'id' => $cart->id,
            'user_id' => $cart->user_id,
            'items' => $cart->items->map(function (CartItem $item) {
                return [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'product' => $item->product,
                    'quantity' => $item->quantity,
                    'price' => (float) $item->price,
                    'subtotal' => $item->subtotal(),
                ];
            })->values(),
            'item_count' => $cart->itemCount(),
            'total' => $cart->total(),
            'created_at' => $cart->created_at,
            'updated_at' => $cart->updated_at,
        ];
    }

    /**
     * Return formatted cart response.
     */
    private function cartResponse(Cart $cart): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Cart retrieved successfully.',
            'data' => [
                'cart' => $this->formatCart($cart),
            ],
        ]);
    }
}