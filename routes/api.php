<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

// Publik: nama aplikasi & aturan booking (juga dipakai halaman login).
Route::get('/settings', [SettingController::class, 'index'])->middleware('throttle:60,1');

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    // Akun
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::put('/auth/profile', [AuthController::class, 'updateProfile']);
    Route::put('/auth/password', [AuthController::class, 'changePassword']);

    Route::get('/dashboard', DashboardController::class);
    Route::get('/schedule', ScheduleController::class);

    // Ruangan (baca: semua user)
    Route::get('/rooms/availability', [RoomController::class, 'availability']);
    Route::get('/rooms', [RoomController::class, 'index']);
    Route::get('/rooms/{room}', [RoomController::class, 'show']);

    // Booking
    Route::get('/bookings', [BookingController::class, 'index']);
    Route::get('/bookings/occurrences', [BookingController::class, 'occurrences']);
    Route::post('/bookings', [BookingController::class, 'store']);
    Route::get('/bookings/{booking}', [BookingController::class, 'show']);
    Route::put('/bookings/{booking}', [BookingController::class, 'update']);
    Route::post('/bookings/{booking}/cancel', [BookingController::class, 'cancel']);

    // Khusus admin
    Route::middleware('admin')->group(function () {
        Route::post('/rooms', [RoomController::class, 'store']);
        Route::put('/rooms/{room}', [RoomController::class, 'update']);
        Route::delete('/rooms/{room}', [RoomController::class, 'destroy']);

        Route::delete('/bookings/{booking}', [BookingController::class, 'destroy']);

        Route::apiResource('users', UserController::class);
    });

    // Khusus System Admin
    Route::middleware('system_admin')->group(function () {
        Route::get('/settings/manage', [SettingController::class, 'manage']);
        Route::put('/settings', [SettingController::class, 'update']);
        Route::delete('/settings', [SettingController::class, 'reset']);
    });
});
