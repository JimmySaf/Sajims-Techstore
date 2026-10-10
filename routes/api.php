<?php
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ProductController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\AdminOrderController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\AdminCustomerController;

Route::get('/health', function (): JsonResponse {
    return response()->json([
        'success' => true,
        'message' => 'Sajims TechStore API is running.',
        'application' => 'Sajims TechStore',
        'version' => '1.0.0',
    ]);
});

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

Route::middleware('throttle:10,1')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
});

/*
|--------------------------------------------------------------------------
| Public Catalog
|--------------------------------------------------------------------------
*/

Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/categories/{category}', [CategoryController::class, 'show']);

Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{product}', [ProductController::class, 'show']);

/*
|--------------------------------------------------------------------------
| Authenticated User Routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/cart', [CartController::class, 'index']);
Route::post('/cart/items', [CartController::class, 'store']);
Route::patch('/cart/items/{cartItem}', [CartController::class, 'update']);
Route::delete('/cart/items/{cartItem}', [CartController::class, 'destroy']);
Route::delete('/cart', [CartController::class, 'clear']);
});

/*
|--------------------------------------------------------------------------
| Administrator Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'admin'])
    ->prefix('admin')
    ->group(function () {

        /*
        | Admin Dashboard
        */
        Route::get('/dashboard', [AdminController::class, 'dashboard']);

        /*
        | Category Management
        */
        Route::post('/categories', [CategoryController::class, 'store']);
        Route::put('/categories/{category}', [CategoryController::class, 'update']);
        Route::patch('/categories/{category}', [CategoryController::class, 'update']);
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);

        /*
        | Product Management
        */
        Route::post('/products', [ProductController::class, 'store']);
        Route::put('/products/{product}', [ProductController::class, 'update']);
        Route::patch('/products/{product}', [ProductController::class, 'update']);
        Route::delete('/products/{product}', [ProductController::class, 'destroy']);
          
        /*
|--------------------------------------------------------------------------
| Order Management
|--------------------------------------------------------------------------
*/

Route::get('/orders', [AdminOrderController::class, 'index']);
Route::get('/orders/{order}', [AdminOrderController::class, 'show']);

Route::patch(
    '/orders/{order}/status',
    [AdminOrderController::class, 'updateStatus']
);

Route::patch(
    '/orders/{order}/payment-status',
    [AdminOrderController::class, 'updatePaymentStatus']
);
/*
|--------------------------------------------------------------------------
| Customer Management
|--------------------------------------------------------------------------
*/

Route::get(
    '/customers',
    [AdminCustomerController::class, 'index']
);

Route::get(
    '/customers/{user}',
    [AdminCustomerController::class, 'show']
);

Route::put(
    '/customers/{user}',
    [AdminCustomerController::class, 'update']
);

Route::patch(
    '/customers/{user}',
    [AdminCustomerController::class, 'update']
);

Route::delete(
    '/customers/{user}',
    [AdminCustomerController::class, 'destroy']
);
Route::get(
    '/inventory/low-stock',
    [InventoryController::class, 'lowStock']
);
    });
    
