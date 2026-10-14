<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Product::with('category');

        if ($request->filled('search')) {
            $search = $request->input('search');

            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $products = $query->latest()->paginate(12);

        return response()->json([
            'success' => true,
            'message' => 'Products retrieved successfully.',
            'data' => $products,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'brand' => ['nullable', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:100', 'unique:products,sku'],
            'price' => ['required', 'numeric', 'min:0'],
            'stock' => ['required', 'integer', 'min:0'],
            'status' => [
                'sometimes',
                'in:ACTIVE,INACTIVE,OUT_OF_STOCK',
            ],
            'image' => ['nullable', 'string', 'max:255'],
        ]);

        $stock = (int) $validated['stock'];

        $product = DB::transaction(function () use ($validated, $stock, $request) {
            $status = $stock === 0
                ? 'OUT_OF_STOCK'
                : ($validated['status'] ?? 'ACTIVE');

            $product = Product::create([
                ...$validated,
                'stock' => $stock,
                'status' => $status,
                'slug' => Str::slug($validated['name'])
                    . '-' . Str::lower(Str::random(6)),
            ]);

            if ($stock > 0) {
                InventoryAdjustment::create([
                    'product_id' => $product->id,
                    'user_id' => $request->user()->id,
                    'quantity_change' => $stock,
                    'previous_stock' => 0,
                    'new_stock' => $stock,
                    'type' => 'ADJUSTMENT',
                    'reason' => 'Initial stock recorded when product was created.',
                ]);
            }

            return $product;
        });

        return response()->json([
            'success' => true,
            'message' => 'Product created successfully.',
            'data' => $product->load('category'),
        ], 201);
    }

    public function show(Product $product): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Product retrieved successfully.',
            'data' => $product->load('category'),
        ]);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => ['sometimes', 'required', 'exists:categories,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'brand' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sku' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('products', 'sku')->ignore($product->id),
            ],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
            // Stock must be changed through the inventory adjustment endpoint.
            'stock' => ['prohibited'],
            'status' => [
                'sometimes',
                Rule::in(['ACTIVE', 'INACTIVE', 'OUT_OF_STOCK']),
            ],
            'image' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        if (isset($validated['name'])) {
            $validated['slug'] = Str::slug($validated['name'])
                . '-' . Str::lower(Str::random(6));
        }

        $updatedProduct = DB::transaction(function () use (
            $product,
            $validated
        ) {
            $lockedProduct = Product::whereKey($product->id)
                ->lockForUpdate()
                ->firstOrFail();

            $newStatus = $validated['status'] ?? $lockedProduct->status;

            // A product with no stock cannot be made available for sale.
            if (
                (int) $lockedProduct->stock === 0
                && $newStatus === 'ACTIVE'
            ) {
                abort(response()->json([
                    'success' => false,
                    'message' => 'A product with zero stock cannot be marked ACTIVE.',
                ], 422));
            }

            // Keep stock-derived status consistent when stock is positive.
            if (
                (int) $lockedProduct->stock > 0
                && $newStatus === 'OUT_OF_STOCK'
            ) {
                abort(response()->json([
                    'success' => false,
                    'message' => 'A product with available stock cannot be marked OUT_OF_STOCK.',
                ], 422));
            }

            $lockedProduct->update($validated);

            return $lockedProduct->fresh('category');
        });

        return response()->json([
            'success' => true,
            'message' => 'Product updated successfully.',
            'data' => $updatedProduct,
        ]);
    }

    public function destroy(Product $product): JsonResponse
    {
        $product->delete();

        return response()->json([
            'success' => true,
            'message' => 'Product deleted successfully.',
        ]);
    }
}