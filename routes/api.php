<?php

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

Route::get('/health', function (): JsonResponse {
    return response()->json([
        'success' => true,
        'message' => 'Sajims TechStore API is running.',
        'application' => 'Sajims TechStore',
        'version' => '1.0.0',
    ]);
});