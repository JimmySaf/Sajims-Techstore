<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    /**
     * Display inventory with optional search and low-stock filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Product::with('category');

        if ($request->filled('search')) {
            $search = $request->input('search');

            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('low_stock')) {
            $query->whereBetween('stock', [1, 5]);
        }

        $products = $query
            ->orderBy('stock')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'message' => 'Inventory retrieved successfully.',
            'data' => $products,
        ]);
    }

    /**
     * Adjust the stock of a product and record the adjustment.
     */
    public function adjust(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'quantity_change' => ['required', 'integer', 'not_in:0'],
            'type' => [
                'required',
                'in:RESTOCK,SALE,RETURN,ADJUSTMENT,DAMAGE',
            ],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $quantityChange = (int) $validated['quantity_change'];

        $result = DB::transaction(function () use (
            $product,
            $validated,
            $quantityChange,
            $request
        ) {
            $lockedProduct = Product::whereKey($product->id)
                ->lockForUpdate()
                ->firstOrFail();

            $previousStock = (int) $lockedProduct->stock;
            $newStock = $previousStock + $quantityChange;

            if ($newStock < 0) {
                return null;
            }

            $lockedProduct->stock = $newStock;
            $lockedProduct->status = $newStock > 0
                ? 'ACTIVE'
                : 'OUT_OF_STOCK';

            $lockedProduct->save();

            $adjustment = InventoryAdjustment::create([
                'product_id' => $lockedProduct->id,
                'user_id' => $request->user()->id,
                'quantity_change' => $quantityChange,
                'previous_stock' => $previousStock,
                'new_stock' => $newStock,
                'type' => $validated['type'],
                'reason' => $validated['reason'] ?? null,
            ]);

            return [
                'product' => $lockedProduct->fresh('category'),
                'adjustment' => $adjustment->load('user'),
            ];
        });

        if ($result === null) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient stock. Stock cannot be negative.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Inventory adjusted successfully.',
            'data' => $result,
        ]);
    }

    /**
     * Display the inventory adjustment history for a product.
     */
    public function history(Product $product): JsonResponse
    {
        $history = InventoryAdjustment::with('user')
            ->where('product_id', $product->id)
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'message' => 'Inventory history retrieved successfully.',
            'product' => $product,
            'data' => $history,
        ]);
    }

    /**
     * Display products with low stock (between 1 and 5 units).
     */
    public function lowStock(): JsonResponse
    {
        $products = Product::with('category')
            ->whereBetween('stock', [1, 5])
            ->orderBy('stock')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Low-stock products retrieved successfully.',
            'data' => [
                'count' => $products->count(),
                'products' => $products,
            ],
        ]);
    }
}
