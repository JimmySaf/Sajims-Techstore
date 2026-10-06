<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            $table->foreignId('category_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('brand')->nullable();
            $table->string('sku')->unique();
            $table->decimal('price', 12, 2);
            $table->unsignedInteger('stock')->default(0);

            $table->enum('status', [
                'ACTIVE',
                'INACTIVE',
                'OUT_OF_STOCK',
            ])->default('ACTIVE');

            $table->string('image')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};