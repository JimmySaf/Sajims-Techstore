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

class OrderCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private function createCustomer(): User
    {
        return User::factory()->create([
            'role' => 'CUSTOMER',
        ]);
    }

    private function createProduct(int $stock = 10): Product
    {
        $category = Category::create([
            'name' => 'Checkout Electronics',
            'slug' => 'checkout-electronics',
            'description' => 'Checkout test category',
            'is_active' => true,
        ]);

        return Product::create([
            'category_id' => $category->id,
            'name' => 'Checkout Test Laptop',
            'slug' => 'checkout-test-laptop-' . uniqid(),
            'description' => 'Product for checkout tests',
            'brand' => 'TestBrand',
            'sku' => 'CHK-' . strtoupper(uniqid()),
            'price' => 1500,
            'stock' => $stock,
            'status' => $stock > 0 ? 'ACTIVE' : 'OUT_OF_STOCK',
        ]);
    }

    private function shippingDetails(): array
    {
        return [
            'shipping_name' => 'Test Customer',
            'shipping_phone' => '0712345678',
            'shipping_address' => '123 Test Street',
            'shipping_city' => 'Nairobi',
            'shipping_notes' => 'Feature test order',
            'payment_method' => 'MPESA',
        ];
    }

    private function addProductToCart(
        User $user,
        Product $product,
        int $quantity = 1
    ): Cart {
        $cart = Cart::firstOrCreate([
            'user_id' => $user->id,
        ]);

        $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => $quantity,
            'price' => $product->price,
        ]);

        return $cart;
    }

    public function test_customer_can_checkout_and_stock_is_deducted(): void
    {
        $customer = $this->createCustomer();
        $product = $this->createProduct(10);

        $this->addProductToCart($customer, $product, 2);

        Sanctum::actingAs($customer);

        $response = $this->postJson(
            '/api/checkout',
            $this->shippingDetails()
        );

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.order.status', 'PENDING')
            ->assertJsonPath('data.order.payment_status', 'PENDING')
            ->assertJsonPath('data.order.payment_method', 'MPESA')
            ->assertJsonPath('data.order.subtotal', '3000.00');

        $order = Order::where('user_id', $customer->id)->firstOrFail();

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 8,
            'status' => 'ACTIVE',
        ]);

        $this->assertDatabaseHas('inventory_adjustments', [
            'product_id' => $product->id,
            'user_id' => $customer->id,
            'quantity_change' => -2,
            'previous_stock' => 10,
            'new_stock' => 8,
            'type' => 'SALE',
        ]);

        $this->assertDatabaseMissing('cart_items', [
            'cart_id' => Cart::where('user_id', $customer->id)
                ->value('id'),
        ]);
    }
    public function test_checkout_rejects_an_empty_cart(): void
    {
        $customer = $this->createCustomer();

        Sanctum::actingAs($customer);

        $response = $this->postJson(
            '/api/checkout',
            $this->shippingDetails()
        );

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Your cart is empty.');

        $this->assertDatabaseCount('orders', 0);
    }
    public function test_checkout_rejects_an_empty_cart(): void
    {
        $customer = $this->createCustomer();

        Sanctum::actingAs($customer);

        $response = $this->postJson(
            '/api/checkout',
            $this->shippingDetails()
        );

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Your cart is empty.');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_checkout_rejects_quantity_exceeding_stock(): void
    {
        $customer = $this->createCustomer();
        $product = $this->createProduct(2);

        $this->addProductToCart($customer, $product, 3);

        Sanctum::actingAs($customer);

        $response = $this->postJson(
            '/api/checkout',
            $this->shippingDetails()
        );

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('available_stock', 2)
            ->assertJsonPath('requested_quantity', 3);

        $this->assertDatabaseCount('orders', 0);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 2,
        ]);

        $this->assertDatabaseCount('inventory_adjustments', 0);
    }

    public function test_checkout_requires_shipping_details(): void
    {
        $customer = $this->createCustomer();

        Sanctum::actingAs($customer);

        $this->postJson('/api/checkout', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'shipping_name',
                'shipping_phone',
                'shipping_address',
                'shipping_city',
            ]);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_customer_cannot_view_another_customers_order(): void
    {
        $owner = $this->createCustomer();
        $otherCustomer = $this->createCustomer();

        $order = Order::create([
            'user_id' => $owner->id,
            'order_number' => 'SJ-TEST-' . strtoupper(uniqid()),
            'subtotal' => 1500,
            'shipping_fee' => 0,
            'total' => 1500,
            'status' => 'PENDING',
            'payment_status' => 'PENDING',
            'payment_method' => 'MPESA',
            'shipping_name' => 'Order Owner',
            'shipping_phone' => '0712345678',
            'shipping_address' => 'Test Street',
            'shipping_city' => 'Nairobi',
        ]);

        Sanctum::actingAs($otherCustomer);

        $this->getJson('/api/orders/' . $order->id)
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_cancelling_order_restores_stock_and_records_return(): void
    {
        $customer = $this->createCustomer();
        $product = $this->createProduct(10);

        $this->addProductToCart($customer, $product, 2);

        Sanctum::actingAs($customer);

        $checkout = $this->postJson(
            '/api/checkout',
            $this->shippingDetails()
        );

        $checkout->assertCreated();

        $orderId = $checkout->json('data.order.id');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 8,
        ]);

        $this->postJson("/api/orders/{$orderId}/cancel")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.order.status', 'CANCELLED');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 10,
            'status' => 'ACTIVE',
        ]);

        $this->assertDatabaseHas('inventory_adjustments', [
            'product_id' => $product->id,
            'user_id' => $customer->id,
            'quantity_change' => 2,
            'previous_stock' => 8,
            'new_stock' => 10,
            'type' => 'RETURN',
        ]);

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'status' => 'CANCELLED',
        ]);
    }
}