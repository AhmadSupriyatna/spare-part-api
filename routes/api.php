<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\PartController;
use App\Http\Controllers\Api\SupplierController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'me']);

    // Master data: any authenticated role can read.
    Route::apiResource('branches', BranchController::class)->only(['index', 'show']);
    Route::apiResource('parts', PartController::class)->only(['index', 'show']);
    Route::get('/branches/{branch}/suppliers', [SupplierController::class, 'index']);
    Route::get('/suppliers/{supplier}', [SupplierController::class, 'show']);
    Route::get('/branches/{branch}/locations', [LocationController::class, 'index']);
    Route::get('/locations/{location}', [LocationController::class, 'show']);

    // Master data: only warehouse admin, supervisor, and superadmin can write.
    Route::middleware('role:admin_gudang|supervisor|superadmin')->group(function () {
        Route::apiResource('branches', BranchController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('parts', PartController::class)->only(['store', 'update', 'destroy']);
        Route::post('/branches/{branch}/suppliers', [SupplierController::class, 'store']);
        Route::put('/suppliers/{supplier}', [SupplierController::class, 'update']);
        Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy']);
        Route::post('/branches/{branch}/locations', [LocationController::class, 'store']);
        Route::put('/locations/{location}', [LocationController::class, 'update']);
        Route::delete('/locations/{location}', [LocationController::class, 'destroy']);
    });
});
