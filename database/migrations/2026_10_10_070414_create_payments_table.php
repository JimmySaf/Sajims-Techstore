<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('payment_reference')->unique();

            $table->decimal('amount', 12, 2);

            $table->enum('method', [
                'MPESA',
                'CARD',
                'CASH',
                'BANK_TRANSFER',
            ]);

            $table->enum('status', [
                'PENDING',
                'PROCESSING',
                'COMPLETED',
                'FAILED',
                'CANCELLED',
                'REFUNDED',
            ])->default('PENDING');

            $table->string('transaction_reference')->nullable();
            $table->string('phone_number')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};