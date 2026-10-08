<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class AdminController extends Controller
{
    public function dashboard(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Admin dashboard data retrieved successfully.',
            'data' => [
                'statistics' => [
                    'users' => User::count(),
                    'customers' => User::where('role', 'CUSTOMER')->count(),
                    'admins' => User::where('role', 'ADMIN')->count(),
                    'categories' => Category::count(),
                    'products' => Product::count(),
                    'active_products' => Product::where('status', 'ACTIVE')->count(),
                    'out_of_stock_products' => Product::where('status', 'OUT_OF_STOCK')->count(),
                ],
            ],
        ]);
    }
}