<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class AdminController extends Controller
{
    public function dashboard(): JsonResponse
    {
        $revenue = Order::where('payment_status', 'PAID')
            ->sum('total');

        $pendingOrders = Order::whereIn('status', [
            'PENDING',
            'CONFIRMED',
            'PROCESSING',
        ])->count();

        $lowStockProducts = Product::where('stock', '>', 0)
            ->where('stock', '<=', 5)
            ->count();

        $outOfStockProducts = Product::where('stock', '<=', 0)
            ->count();

        $pendingPayments = Payment::whereIn('status', [
            'PENDING',
            'PROCESSING',
        ])->count();

        return response()->json([
            'success' => true,
            'message' => 'Admin dashboard data retrieved successfully.',
            'data' => [
                'statistics' => [
                    'users' => User::count(),

                    'customers' => User::where(
                        'role',
                        'CUSTOMER'
                    )->count(),

                    'admins' => User::where(
                        'role',
                        'ADMIN'
                    )->count(),

                    'categories' => Category::count(),

                    'products' => Product::count(),

                    'active_products' => Product::where(
                        'status',
                        'ACTIVE'
                    )->count(),

                    'low_stock_products' => $lowStockProducts,

                    'out_of_stock_products' => $outOfStockProducts,

                    'orders' => Order::count(),

                    'pending_orders' => $pendingOrders,

                    'completed_orders' => Order::where(
                        'status',
                        'DELIVERED'
                    )->count(),

                    'cancelled_orders' => Order::where(
                        'status',
                        'CANCELLED'
                    )->count(),

                    'payments' => Payment::count(),

                    'pending_payments' => $pendingPayments,

                    'paid_orders' => Order::where(
                        'payment_status',
                        'PAID'
                    )->count(),

                    'revenue' => (float) $revenue,
                ],
            ],
        ]);
    }
}