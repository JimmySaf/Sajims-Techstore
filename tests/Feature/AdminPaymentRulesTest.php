<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminPaymentRulesTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): User
    {
        return User::factory()->create(['role' => 'ADMIN']);
    }

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

    private function updatePaymentStatus(Payment $payment, string $status)
    {
        return $this->patchJson(
            "/api/admin/payments/{$payment->id}/status",
            ['status' => $status]
        );
    }

    public function test_order_cannot_have_two_completed_payments(): void
    {
        $admin = $this->createAdmin();
        $customer = $this->createCustomer();
        $order = $this->createOrder($customer);

        $first = $this->createPayment($customer, $order);
        $second = $this->createPayment($customer, $order);

        Sanctum::actingAs($admin);

        $this->updatePaymentStatus($first, 'COMPLETED')->assertOk();

        $this->updatePaymentStatus($second, 'COMPLETED')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseHas('payments', [
            'id' => $second->id,
            'status' => 'PENDING',
        ]);
    }

    public function test_failed_payment_cannot_be_reopened(): void
    {
        $admin = $this->createAdmin();
        $customer = $this->createCustomer();
        $order = $this->createOrder($customer);
        $payment = $this->createPayment($customer, $order);

        Sanctum::actingAs($admin);

        $this->updatePaymentStatus($payment, 'FAILED')->assertOk();

        $this->updatePaymentStatus($payment, 'PENDING')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'FAILED',
        ]);
    }

    public function test_failing_another_payment_does_not_unpay_a_paid_order(): void
    {
        $admin = $this->createAdmin();
        $customer = $this->createCustomer();
        $order = $this->createOrder($customer);

        $paid = $this->createPayment($customer, $order);
        $stray = $this->createPayment($customer, $order);

        Sanctum::actingAs($admin);

        $this->updatePaymentStatus($paid, 'COMPLETED')->assertOk();
        $this->updatePaymentStatus($stray, 'FAILED')->assertOk();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'payment_status' => 'PAID',
        ]);
    }

    public function test_refunding_completed_payment_marks_order_refunded(): void
    {
        $admin = $this->createAdmin();
        $customer = $this->createCustomer();
        $order = $this->createOrder($customer);
        $payment = $this->createPayment($customer, $order);

        Sanctum::actingAs($admin);

        $this->updatePaymentStatus($payment, 'COMPLETED')->assertOk();
        $this->updatePaymentStatus($payment, 'REFUNDED')->assertOk();

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'REFUNDED',
        ]);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'payment_status' => 'REFUNDED',
        ]);
    }

    public function test_payment_for_cancelled_order_cannot_be_completed(): void
    {
        $admin = $this->createAdmin();
        $customer = $this->createCustomer();
        $order = $this->createOrder($customer, ['status' => 'CANCELLED']);
        $payment = $this->createPayment($customer, $order);

        Sanctum::actingAs($admin);

        $this->updatePaymentStatus($payment, 'COMPLETED')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'payment_status' => 'PENDING',
        ]);
    }
}