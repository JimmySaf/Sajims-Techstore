<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function createOrder(User $user): Order
    {
        return Order::create([
            'user_id' => $user->id,
            'order_number' => 'SJ-TEST-' . uniqid(),
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
        ]);
    }

    private function createPayment(User $user, Order $order): Payment
    {
        return Payment::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'payment_reference' => 'PAY-' . strtoupper(bin2hex(random_bytes(5))),
            'amount' => $order->total,
            'method' => 'MPESA',
            'status' => 'PENDING',
            'phone_number' => '0712345678',
        ]);
    }

    public function test_guest_cannot_access_admin_payments(): void
    {
        $this->getJson('/api/admin/payments')
            ->assertUnauthorized();
    }

    public function test_customer_cannot_access_admin_payments(): void
    {
        $customer = User::factory()->create([
            'role' => 'CUSTOMER',
        ]);

        Sanctum::actingAs($customer);

        $this->getJson('/api/admin/payments')
            ->assertForbidden();
    }

    public function test_admin_can_list_payments(): void
    {
        $admin = User::factory()->create([
            'role' => 'ADMIN',
        ]);

        $customer = User::factory()->create([
            'role' => 'CUSTOMER',
        ]);

        $order = $this->createOrder($customer);
        $this->createPayment($customer, $order);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/payments')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_completing_payment_updates_order_payment_status(): void
    {
        $admin = User::factory()->create([
            'role' => 'ADMIN',
        ]);

        $customer = User::factory()->create([
            'role' => 'CUSTOMER',
        ]);

        $order = $this->createOrder($customer);
        $payment = $this->createPayment($customer, $order);

        Sanctum::actingAs($admin);

        $this->patchJson(
            "/api/admin/payments/{$payment->id}/status",
            [
                'status' => 'COMPLETED',
                'transaction_reference' => 'TEST-TRANSACTION-001',
            ]
        )
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.payment.status', 'COMPLETED');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'COMPLETED',
            'transaction_reference' => 'TEST-TRANSACTION-001',
        ]);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'payment_status' => 'PAID',
        ]);
    }

    public function test_refund_is_rejected_for_pending_payment(): void
    {
        $admin = User::factory()->create([
            'role' => 'ADMIN',
        ]);

        $customer = User::factory()->create([
            'role' => 'CUSTOMER',
        ]);

        $order = $this->createOrder($customer);
        $payment = $this->createPayment($customer, $order);

        Sanctum::actingAs($admin);

        $this->patchJson(
            "/api/admin/payments/{$payment->id}/status",
            ['status' => 'REFUNDED']
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'PENDING',
        ]);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'payment_status' => 'PENDING',
        ]);
    }
}