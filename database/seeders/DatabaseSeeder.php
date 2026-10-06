<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'Accessories',
            'Desktops',
            'Keyboards',
            'Laptops',
            'Monitors',
            'Mouse',
            'Networking',
            'Printers',
            'RAM',
            'Storage',
        ];

        foreach ($categories as $name) {
            Category::create([
                'name' => $name,
                'slug' => Str::slug($name),
                'description' => "Sajims TechStore {$name} products.",
                'is_active' => true,
            ]);
        }

        $products = [
            [
                'category' => 'Laptops',
                'name' => 'HP EliteBook 840 G10',
                'brand' => 'HP',
                'sku' => 'HP-840-G10',
                'price' => 65000,
                'stock' => 10,
            ],
            [
                'category' => 'Desktops',
                'name' => 'Dell OptiPlex 7020',
                'brand' => 'Dell',
                'sku' => 'DELL-7020',
                'price' => 45000,
                'stock' => 8,
            ],
            [
                'category' => 'RAM',
                'name' => 'Kingston 8GB DDR4 RAM',
                'brand' => 'Kingston',
                'sku' => 'KING-8GB-DDR4',
                'price' => 3500,
                'stock' => 25,
            ],
            [
                'category' => 'Mouse',
                'name' => 'Logitech M171 Mouse',
                'brand' => 'Logitech',
                'sku' => 'LOG-M171',
                'price' => 1500,
                'stock' => 30,
            ],
        ];

        foreach ($products as $item) {
            $category = Category::where('name', $item['category'])->first();

            Product::create([
                'category_id' => $category->id,
                'name' => $item['name'],
                'slug' => Str::slug($item['name']) . '-' . Str::lower(Str::random(6)),
                'description' => "High-quality {$item['name']} available from Sajims TechStore.",
                'brand' => $item['brand'],
                'sku' => $item['sku'],
                'price' => $item['price'],
                'stock' => $item['stock'],
                'status' => 'ACTIVE',
            ]);
        }
    }
}