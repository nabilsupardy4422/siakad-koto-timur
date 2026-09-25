<?php

use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\HealthController;
use Illuminate\Support\Facades\Route;

Route::post('/login', LoginController::class);

Route::get('/health', HealthController::class);