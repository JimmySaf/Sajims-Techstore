<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Category;
use App\Models\InventoryAdjustment;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderCancellationRulesTest extends TestCase
{
    use RefreshDatabase;

    private function createCustomer(): User
    {
        return User::factory()->create(['role' => 'CUSTOMER']);
    }

    private function createAdmin(): User
    {
        return User::factory()->create(['role' => 'ADMIN']);
    }

    private function createProduct(int $stock = 10): Product
    {
        $category = Category::create([
            'name' => 'Cancel Electronics',
            'slug' => 'cancel-electronics',
            'description' => 'Cancellation test category',
            'is_active' => true,
        ]);

        return Product::create([
            'category_id' => $category->id,
            'name' => 'Cancel Test Laptop',
            'slug' => 'cancel-test-laptop-' . uniqid(),
            'description' => 'Product for cancellation tests',
            'brand' => 'TestBrand',
            'sku' => 'CAN-' . strtoupper(uniqid()),
            'price' => 1500,
            'stock' => $stock,
            'status' => $stock > 0 ? 'ACTIVE' : 'OUT_OF_STOCK',
        ]);
    }

    /**
     * Place an order through the real checkout endpoint.
     */
    private function placeOrder(
        User $customer,
        Product $product,
        int $quantity = 2
    ): int {
        $cart = Cart::firstOrCreate(['user_id' => $customer->id]);

        $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => $quantity,
            'price' => $product->price,
        ]);

        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/checkout', [
            'shipping_name' => 'Test Customer',
            'shipping_phone' => '0712345678',
            'shipping_address' => '123 Test Street',
            'shipping_city' => 'Nairobi',
            'payment_method' => 'MPESA',
        ]);

        $response->assertCreated();

        return (int) $response->json('data.order.id');
    }

    private function setPaymentStatus(int $orderId, string $status): void
    {
        Order::where('id', $orderId)->update(['payment_status' => $status]);
    }

    public function test_customer_cannot_cancel_paid_or_refunded_order(): void
    {
        $customer = $this->createCustomer();
        $product = $this->createProduct(10);

        foreach (['PAID', 'REFUNDED'] as $paymentStatus) {
            $orderId = $this->placeOrder($customer, $product, 1);
            $this->setPaymentStatus($orderId, $paymentStatus);

            Sanctum::actingAs($customer);

            $this->postJson("/api/orders/{$orderId}/cancel")
                ->assertUnprocessable()
                ->assertJsonPath('success', false);

            $this->assertDatabaseHas('orders', [
                'id' => $orderId,
                'status' => 'PENDING',
            ]);
        }

        $this->assertDatabaseMissing('inventory_adjustments', [
            'type' => 'RETURN',
        ]);
    }

    public function test_admin_cannot_cancel_paid_or_refunded_order(): void
    {
        $customer = $this->createCustomer();
        $admin = $this->createAdmin();
        $product = $this->createProduct(10);

        foreach (['PAID', 'REFUNDED'] as $paymentStatus) {
            $orderId = $this->placeOrder($customer, $product, 1);
            $this->setPaymentStatus($orderId, $paymentStatus);

            Sanctum::actingAs($admin);

            $this->patchJson("/api/admin/orders/{$orderId}/status", [
                'status' => 'CANCELLED',
            ])
                ->assertUnprocessable()
                ->assertJsonPath('success', false);

            $this->assertDatabaseHas('orders', [
                'id' => $orderId,
                'status' => 'PENDING',
            ]);
        }

        $this->assertDatabaseMissing('inventory_adjustments', [
            'type' => 'RETURN',
        ]);
    }

    public function test_customer_can_cancel_order_with_failed_payment(): void
    {
        $customer = $this->createCustomer();
        $product = $this->createProduct(10);

        $orderId = $this->placeOrder($customer, $product, 2);
        $this->setPaymentStatus($orderId, 'FAILED');

        Sanctum::actingAs($customer);

        $this->postJson("/api/orders/{$orderId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.order.status', 'CANCELLED');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 10,
        ]);
    }

    public function test_admin_cancellation_restores_stock_and_records_return(): void
    {
        $customer = $this->createCustomer();
        $admin = $this->createAdmin();
        $product = $this->createProduct(10);

        $orderId = $this->placeOrder($customer, $product, 2);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 8,
        ]);

        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/orders/{$orderId}/status", [
            'status' => 'CANCELLED',
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'status' => 'CANCELLED',
        ]);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 10,
            'status' => 'ACTIVE',
        ]);

        $this->assertDatabaseHas('inventory_adjustments', [
            'product_id' => $product->id,
            'quantity_change' => 2,
            'previous_stock' => 8,
            'new_stock' => 10,
            'type' => 'RETURN',
        ]);
    }

    public function test_customer_cancelling_twice_restores_stock_only_once(): void
    {
        $customer = $this->createCustomer();
        $product = $this->createProduct(10);

        $orderId = $this->placeOrder($customer, $product, 2);

        Sanctum::actingAs($customer);

        $this->postJson("/api/orders/{$orderId}/cancel")->assertOk();

        $this->postJson("/api/orders/{$orderId}/cancel")
            ->assertUnprocessable();

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 10,
        ]);

        $this->assertSame(
            1,
            InventoryAdjustment::where('product_id', $product->id)
                ->where('type', 'RETURN')
                ->count()
        );
    }

    public function test_admin_cancelling_twice_restores_stock_only_once(): void
    {
        $customer = $this->createCustomer();
        $admin = $this->createAdmin();
        $product = $this->createProduct(10);

        $orderId = $this->placeOrder($customer, $product, 2);

        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/orders/{$orderId}/status", [
            'status' => 'CANCELLED',
        ])->assertOk();

        $this->patchJson("/api/admin/orders/{$orderId}/status", [
            'status' => 'CANCELLED',
        ])->assertOk();

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 10,
        ]);

        $this->assertSame(
            1,
            InventoryAdjustment::where('product_id', $product->id)
                ->where('type', 'RETURN')
                ->count()
        );
    }
}