<?php

use App\Http\Controllers\Auth\AuthenticatedUserController;
use App\Http\Controllers\OrganizationController;
use Illuminate\Support\Facades\Route;

Route::get('/user', AuthenticatedUserController::class)->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/organization', [OrganizationController::class, 'show']);
    Route::post('/organization', [OrganizationController::class, 'store']);
    Route::delete('/organization', [OrganizationController::class, 'destroy']);
    Route::get('/organizations/{organization}/reviews', [OrganizationController::class, 'reviews']);
});
