<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminCustomerDeactivationTest extends TestCase
{
    use RefreshDatabase;

    private function createCustomer(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'CUSTOMER',
            'is_active' => true,
            'password' => 'password',
        ], $attributes));
    }

    private function createAdmin(): User
    {
        return User::factory()->create([
            'role' => 'ADMIN',
            'is_active' => true,
        ]);
    }

    public function test_deactivated_customer_cannot_log_in(): void
    {
        $customer = $this->createCustomer(['is_active' => false]);

        $this->postJson('/api/auth/login', [
            'email' => $customer->email,
            'password' => 'password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_active_customer_can_log_in(): void
    {
        $customer = $this->createCustomer();

        $this->postJson('/api/auth/login', [
            'email' => $customer->email,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_admin_can_deactivate_customer_and_tokens_are_revoked(): void
    {
        $admin = $this->createAdmin();
        $customer = $this->createCustomer();

        $customer->createToken('test-token');
        $this->assertSame(1, $customer->tokens()->count());

        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/customers/{$customer->id}", [
            'is_active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('users', [
            'id' => $customer->id,
            'is_active' => false,
        ]);

        $this->assertSame(0, $customer->tokens()->count());
    }

    public function test_admin_can_reactivate_customer_and_login_works_again(): void
    {
        $admin = $this->createAdmin();
        $customer = $this->createCustomer(['is_active' => false]);

        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/customers/{$customer->id}", [
            'is_active' => true,
        ])->assertOk();

        $this->postJson('/api/auth/login', [
            'email' => $customer->email,
            'password' => 'password',
        ])->assertOk();
    }

    public function test_inactive_customer_with_valid_token_is_rejected_and_token_removed(): void
    {
        $customer = $this->createCustomer();
        $plainToken = $customer->createToken('test-token')->plainTextToken;

        // Simulate a change made outside the admin endpoint.
        User::where('id', $customer->id)->update(['is_active' => false]);

        $this->withToken($plainToken)
            ->getJson('/api/auth/me')
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->assertSame(0, $customer->tokens()->count());
    }

    public function test_customer_cannot_use_admin_customer_endpoint(): void
    {
        $customer = $this->createCustomer();
        $other = $this->createCustomer();

        Sanctum::actingAs($customer);

        $this->patchJson("/api/admin/customers/{$other->id}", [
            'is_active' => false,
        ])->assertForbidden();

        $this->assertDatabaseHas('users', [
            'id' => $other->id,
            'is_active' => true,
        ]);
    }

    public function test_customer_with_order_history_cannot_be_deleted(): void
    {
        $admin = $this->createAdmin();
        $customer = $this->createCustomer();

        Order::create([
            'user_id' => $customer->id,
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

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/admin/customers/{$customer->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('users', ['id' => $customer->id]);
    }
}