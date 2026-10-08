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
     * Display inventory.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Product::with('category');

        if ($request->filled('search')) {
            $search = $request->search;

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
     * Adjust product inventory.
     */
    public function adjust(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'quantity_change' => ['required', 'integer'],
            'type' => [
                'required',
                'in:RESTOCK,SALE,RETURN,ADJUSTMENT,DAMAGE',
            ],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if (
            $validated['quantity_change'] < 0 &&
            $product->stock < abs($validated['quantity_change'])
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient stock for this adjustment.',
            ], 422);
        }

        $result = DB::transaction(function () use ($product, $validated, $request) {
            $previousStock = $product->stock;

            $newStock = $previousStock + $validated['quantity_change'];

            if ($newStock < 0) {
                throw new \RuntimeException('Stock cannot be negative.');
            }

            $product->stock = $newStock;

            $product->status = $newStock > 0
                ? 'ACTIVE'
                : 'OUT_OF_STOCK';

            $product->save();

            $adjustment = InventoryAdjustment::create([
                'product_id' => $product->id,
                'user_id' => $request->user()->id,
                'quantity_change' => $validated['quantity_change'],
                'previous_stock' => $previousStock,
                'new_stock' => $newStock,
                'type' => $validated['type'],
                'reason' => $validated['reason'] ?? null,
            ]);

            return [
                'product' => $product->fresh('category'),
                'adjustment' => $adjustment->load('user'),
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Inventory adjusted successfully.',
            'data' => $result,
        ]);
    }

    /**
     * Display inventory history for a product.
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
     * Display products with low stock.
     */
    public function lowStock(): JsonResponse
    {
        $products = Product::with('category')
            ->whereBetween('stock', [1, 5])
            ->orderBy('stock')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Low stock products retrieved successfully.',
            'data' => $products,
        ]);
    }
     public function lowStock(): JsonResponse
{
    $products = Product::where('stock', '>', 0)
        ->where('stock', '<=', 5)
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