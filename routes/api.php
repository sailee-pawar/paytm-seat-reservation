<?php

use App\Http\Controllers\ReservationController;
use App\Http\Controllers\ShowController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\MetricsController;
use Illuminate\Support\Facades\Route;

Route::post('/shows', [ShowController::class, 'store'])
    ->middleware('auth.admin');
Route::get('/shows/{show}', [ShowController::class, 'show']);

Route::post(
    '/shows/{show}/reserve',
    [ReservationController::class, 'store']
)->middleware('auth.user');

Route::post('/reservations/{reservation}/cancel', [ReservationController::class, 'cancel'])
    ->middleware('auth.user');

Route::get('/health/live', [HealthController::class, 'live']);

Route::get('/health/ready', [HealthController::class, 'ready']);

Route::get('/metrics', [MetricsController::class, 'index']);

