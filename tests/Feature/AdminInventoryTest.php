<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminInventoryTest extends TestCase
{
    use RefreshDatabase;

    private function createProduct(int $stock = 10): Product
    {
        $category = Category::create([
            'name' => 'Test Electronics',
            'slug' => 'test-electronics',
            'description' => 'Test category',
            'is_active' => true,
        ]);

        return Product::create([
            'category_id' => $category->id,
            'name' => 'Test Laptop',
            'slug' => 'test-laptop-' . uniqid(),
            'description' => 'Inventory test product',
            'brand' => 'Test Brand',
            'sku' => 'SKU-' . strtoupper(bin2hex(random_bytes(4))),
            'price' => 50000,
            'stock' => $stock,
            'status' => $stock > 0 ? 'ACTIVE' : 'OUT_OF_STOCK',
        ]);
    }

    private function createAdmin(): User
    {
        return User::factory()->create([
            'role' => 'ADMIN',
        ]);
    }

    public function test_guest_cannot_access_admin_inventory(): void
    {
        $this->getJson('/api/admin/inventory')
            ->assertUnauthorized();
    }

    public function test_customer_cannot_adjust_inventory(): void
    {
        $customer = User::factory()->create([
            'role' => 'CUSTOMER',
        ]);

        $product = $this->createProduct();

        Sanctum::actingAs($customer);

        $this->postJson('/api/admin/inventory/adjust', [
            'product_id' => $product->id,
            'quantity_change' => 5,
            'type' => 'RESTOCK',
        ])->assertForbidden();

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 10,
        ]);
    }

    public function test_admin_can_restock_product_and_record_history(): void
    {
        $admin = $this->createAdmin();
        $product = $this->createProduct(10);

        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/inventory/adjust', [
            'product_id' => $product->id,
            'quantity_change' => 5,
            'type' => 'RESTOCK',
            'reason' => 'New shipment received',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.product.stock', 15)
            ->assertJsonPath('data.adjustment.quantity_change', 5)
            ->assertJsonPath('data.adjustment.previous_stock', 10)
            ->assertJsonPath('data.adjustment.new_stock', 15);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 15,
        ]);

        $this->assertDatabaseHas('inventory_adjustments', [
            'product_id' => $product->id,
            'user_id' => $admin->id,
            'quantity_change' => 5,
            'previous_stock' => 10,
            'new_stock' => 15,
            'type' => 'RESTOCK',
        ]);
    }

    public function test_admin_cannot_reduce_stock_below_zero(): void
    {
        $admin = $this->createAdmin();
        $product = $this->createProduct(3);

        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/inventory/adjust', [
            'product_id' => $product->id,
            'quantity_change' => -5,
            'type' => 'SALE',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 3,
        ]);

        $this->assertDatabaseMissing('inventory_adjustments', [
            'product_id' => $product->id,
        ]);
    }

    public function test_sale_cannot_use_a_positive_quantity_change(): void
    {
        $admin = $this->createAdmin();
        $product = $this->createProduct(10);

        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/inventory/adjust', [
            'product_id' => $product->id,
            'quantity_change' => 2,
            'type' => 'SALE',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity_change');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 10,
        ]);
    }
}
