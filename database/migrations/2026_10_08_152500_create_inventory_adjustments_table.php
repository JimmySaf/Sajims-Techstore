<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_adjustments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->integer('quantity_change');

            $table->unsignedInteger('previous_stock');

            $table->unsignedInteger('new_stock');

            $table->enum('type', [
                'RESTOCK',
                'SALE',
                'RETURN',
                'ADJUSTMENT',
                'DAMAGE',
            ]);

            $table->string('reason')->nullable();

            $table->timestamps();

            $table->index(['product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_adjustments');
    }
};