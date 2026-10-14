<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
            $query->where('stock', '<=', 5);
        }

        $products = $query->orderBy('stock')->paginate(20);

        return response()->json([
            'success' => true,
            'message' => 'Inventory retrieved successfully.',
            'data' => $products,
        ]);
    }

    /**
     * Adjust stock and record the change.
     *
     * The product is identified by "product_id" in the request body,
     * because the route (POST api/admin/inventory/adjust) has no {product}.
     */
    public function adjust(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity_change' => ['required', 'integer', 'not_in:0'],
            'type' => [
                'required',
                'in:RESTOCK,SALE,RETURN,ADJUSTMENT,DAMAGE',
            ],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $quantityChange = (int) $validated['quantity_change'];
        $type = $validated['type'];

        if (
            in_array($type, ['RESTOCK', 'RETURN'], true)
            && $quantityChange < 0
        ) {
            throw ValidationException::withMessages([
                'quantity_change' => 'RESTOCK and RETURN must increase stock.',
            ]);
        }

        if (
            in_array($type, ['SALE', 'DAMAGE'], true)
            && $quantityChange > 0
        ) {
            throw ValidationException::withMessages([
                'quantity_change' => 'SALE and DAMAGE must decrease stock.',
            ]);
        }

        $result = DB::transaction(function () use (
            $validated,
            $quantityChange,
            $request
        ) {
            $lockedProduct = Product::whereKey($validated['product_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $previousStock = (int) $lockedProduct->stock;
            $newStock = $previousStock + $quantityChange;

            if ($newStock < 0) {
                return null;
            }

            $lockedProduct->stock = $newStock;

            // Preserve an administrator's explicit INACTIVE setting.
            if ($lockedProduct->status !== 'INACTIVE') {
                $lockedProduct->status = $newStock > 0
                    ? 'ACTIVE'
                    : 'OUT_OF_STOCK';
            }

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
     * Display inventory adjustment history for a product.
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
     * Display products with five or fewer units, including zero stock.
     */
    public function lowStock(): JsonResponse
    {
        $products = Product::with('category')
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