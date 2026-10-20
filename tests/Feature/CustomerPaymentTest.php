<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function createCustomer(): User
    {
        return User::factory()->create(['role' => 'CUSTOMER']);
    }

    private function createOrder(User $user, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'user_id' => $user->id,
            'order_number' => 'SJ-TEST-' . strtoupper(uniqid()),
            'subtotal' => 1000,
            'shipping_fee' => 0,
            'total' => 1000,
            'status' => 'PENDING',
            'payment_status' => 'PENDING',
            'payment_method' => 'MPESA',
            'shipping_name' => 'Test Customer',
            'shipping_phone' => '0712345678',
            'shipping_address' => 'Test Street',
            'shipping_city' => 'Nairobi',
        ], $overrides));
    }

    private function createPayment(
        User $user,
        Order $order,
        string $status = 'PENDING'
    ): Payment {
        return Payment::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'payment_reference' => 'PAY-' . strtoupper(bin2hex(random_bytes(5))),
            'amount' => $order->total,
            'method' => 'MPESA',
            'status' => $status,
            'phone_number' => '0712345678',
        ]);
    }

    private function mpesaPayload(): array
    {
        return [
            'method' => 'MPESA',
            'phone_number' => '0712345678',
        ];
    }

    public function test_guest_cannot_create_payment(): void
    {
        $order = $this->createOrder($this->createCustomer());

        $this->postJson(
            "/api/orders/{$order->id}/payment",
            $this->mpesaPayload()
        )->assertUnauthorized();
    }

    public function test_customer_payment_is_pending_and_amount_comes_from_order(): void
    {
        $customer = $this->createCustomer();
        $order = $this->createOrder($customer);

        Sanctum::actingAs($customer);

        $this->postJson(
            "/api/orders/{$order->id}/payment",
            array_merge(
                $this->mpesaPayload(),
                ['amount' => 1, 'status' => 'COMPLETED']
            )
        )
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.payment.status', 'PENDING')
            ->assertJsonPath('data.payment.amount', '1000.00');

        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'user_id' => $customer->id,
            'amount' => 1000,
            'status' => 'PENDING',
            'paid_at' => null,
        ]);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'payment_status' => 'PENDING',
        ]);
    }

    public function test_customer_cannot_pay_for_another_customers_order(): void
    {
        $owner = $this->createCustomer();
        $other = $this->createCustomer();
        $order = $this->createOrder($owner);

        Sanctum::actingAs($other);

        $this->postJson(
            "/api/orders/{$order->id}/payment",
            $this->mpesaPayload()
        )->assertNotFound();

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_mpesa_payment_requires_phone_number(): void
    {
        $customer = $this->createCustomer();
        $order = $this->createOrder($customer);

        Sanctum::actingAs($customer);

        $this->postJson("/api/orders/{$order->id}/payment", [
            'method' => 'MPESA',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone_number');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_payment_cannot_be_created_for_cancelled_order(): void
    {
        $customer = $this->createCustomer();
        $order = $this->createOrder($customer, [
            'status' => 'CANCELLED',
        ]);

        Sanctum::actingAs($customer);

        $this->postJson(
            "/api/orders/{$order->id}/payment",
            $this->mpesaPayload()
        )->assertUnprocessable();

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_payment_cannot_be_created_for_paid_order(): void
    {
        $customer = $this->createCustomer();
        $order = $this->createOrder($customer, [
            'payment_status' => 'PAID',
        ]);

        Sanctum::actingAs($customer);

        $this->postJson(
            "/api/orders/{$order->id}/payment",
            $this->mpesaPayload()
        )->assertUnprocessable();

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_duplicate_pending_payment_is_rejected(): void
    {
        $customer = $this->createCustomer();
        $order = $this->createOrder($customer);

        $this->createPayment($customer, $order, 'PENDING');

        Sanctum::actingAs($customer);

        $this->postJson(
            "/api/orders/{$order->id}/payment",
            $this->mpesaPayload()
        )->assertStatus(409);

        $this->assertSame(
            1,
            Payment::where('order_id', $order->id)->count()
        );
    }

    public function test_processing_payment_blocks_duplicate_payment(): void
    {
        $customer = $this->createCustomer();
        $order = $this->createOrder($customer);

        $this->createPayment($customer, $order, 'PROCESSING');

        Sanctum::actingAs($customer);

        $this->postJson(
            "/api/orders/{$order->id}/payment",
            $this->mpesaPayload()
        )->assertStatus(409);

        $this->assertSame(
            1,
            Payment::where('order_id', $order->id)->count()
        );
    }

    public function test_existing_completed_payment_blocks_new_payment(): void
    {
        $customer = $this->createCustomer();
        $order = $this->createOrder($customer);

        $this->createPayment($customer, $order, 'COMPLETED');

        Sanctum::actingAs($customer);

        $this->postJson(
            "/api/orders/{$order->id}/payment",
            $this->mpesaPayload()
        )->assertStatus(409);

        $this->assertSame(
            1,
            Payment::where('order_id', $order->id)->count()
        );
    }

    public function test_invalid_payment_method_is_rejected(): void
    {
        $customer = $this->createCustomer();
        $order = $this->createOrder($customer);

        Sanctum::actingAs($customer);

        $this->postJson("/api/orders/{$order->id}/payment", [
            'method' => 'CRYPTO',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('method');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_payment_cannot_be_created_for_delivered_order(): void
    {
        $customer = $this->createCustomer();
        $order = $this->createOrder($customer, [
            'status' => 'DELIVERED',
        ]);

        Sanctum::actingAs($customer);

        $this->postJson(
            "/api/orders/{$order->id}/payment",
            $this->mpesaPayload()
        )->assertUnprocessable();

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_customer_can_retry_after_failed_payment(): void
    {
        $customer = $this->createCustomer();
        $order = $this->createOrder($customer, [
            'payment_status' => 'FAILED',
        ]);

        $failed = $this->createPayment($customer, $order, 'FAILED');

        Sanctum::actingAs($customer);

        $response = $this->postJson(
            "/api/orders/{$order->id}/payment",
            $this->mpesaPayload()
        )
            ->assertCreated()
            ->assertJsonPath('data.payment.status', 'PENDING');

        $newPaymentId = $response->json('data.payment.id');

        $this->assertNotEquals($failed->id, $newPaymentId);

        $this->assertSame(
            2,
            Payment::where('order_id', $order->id)->count()
        );

        $this->assertDatabaseHas('payments', [
            'id' => $failed->id,
            'status' => 'FAILED',
        ]);

        $this->assertDatabaseHas('payments', [
            'id' => $newPaymentId,
            'order_id' => $order->id,
            'user_id' => $customer->id,
            'status' => 'PENDING',
        ]);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'payment_status' => 'PENDING',
        ]);
    }

    public function test_retry_after_failure_preserves_previous_payment_details(): void
    {
        $customer = $this->createCustomer();
        $order = $this->createOrder($customer, [
            'payment_status' => 'FAILED',
        ]);

        $failed = $this->createPayment($customer, $order, 'FAILED');

        $failed->update([
            'payment_reference' => 'PAY-OLD-REFERENCE',
            'transaction_reference' => 'OLD-TRANSACTION',
            'failure_reason' => 'Previous attempt failed',
        ]);

        Sanctum::actingAs($customer);

        $response = $this->postJson(
            "/api/orders/{$order->id}/payment",
            $this->mpesaPayload()
        )
            ->assertCreated()
            ->assertJsonPath('data.payment.status', 'PENDING');

        $newPaymentId = $response->json('data.payment.id');

        $this->assertNotEquals($failed->id, $newPaymentId);

        $this->assertDatabaseHas('payments', [
            'id' => $failed->id,
            'payment_reference' => 'PAY-OLD-REFERENCE',
            'transaction_reference' => 'OLD-TRANSACTION',
            'failure_reason' => 'Previous attempt failed',
            'status' => 'FAILED',
        ]);

        $this->assertDatabaseHas('payments', [
            'id' => $newPaymentId,
            'status' => 'PENDING',
            'transaction_reference' => null,
            'failure_reason' => null,
            'paid_at' => null,
        ]);

        $this->assertNotEquals(
            'PAY-OLD-REFERENCE',
            $response->json('data.payment.payment_reference')
        );

        $this->assertSame(
            2,
            Payment::where('order_id', $order->id)->count()
        );
    }

    public function test_customer_can_view_own_payment(): void
    {
        $customer = $this->createCustomer();
        $order = $this->createOrder($customer);
        $payment = $this->createPayment($customer, $order);

        Sanctum::actingAs($customer);

        $this->getJson("/api/payments/{$payment->id}")
            ->assertOk()
            ->assertJsonPath('data.payment.id', $payment->id);
    }

    public function test_customer_cannot_view_another_customers_payment(): void
    {
        $owner = $this->createCustomer();
        $other = $this->createCustomer();
        $order = $this->createOrder($owner);
        $payment = $this->createPayment($owner, $order);

        Sanctum::actingAs($other);

        $this->getJson("/api/payments/{$payment->id}")
            ->assertNotFound();
    }
}
