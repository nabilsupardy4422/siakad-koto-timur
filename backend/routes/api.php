<?php

use App\Http\Controllers\Api\AcademicYearController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Http\Controllers\Api\Auth\MeController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\TeacherController;
use Illuminate\Support\Facades\Route;

Route::post('/login', LoginController::class);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/me', MeController::class);
    Route::post('/logout', LogoutController::class);

    /*
    |--------------------------------------------------------------------------
    | Academic Years
    |--------------------------------------------------------------------------
    */

    Route::middleware('role:TU,KEPALA_SEKOLAH,GURU,SISWA')->group(function (): void {
        Route::get('/academic-years', [AcademicYearController::class, 'index']);
        Route::get('/academic-years/{academicYear}', [AcademicYearController::class, 'show']);
    });

    Route::middleware('role:TU')->group(function (): void {
        Route::post('/academic-years', [AcademicYearController::class, 'store']);
        Route::put('/academic-years/{academicYear}', [AcademicYearController::class, 'update']);
        Route::delete('/academic-years/{academicYear}', [AcademicYearController::class, 'destroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | Teachers
    |--------------------------------------------------------------------------
    */

    Route::middleware('role:TU,KEPALA_SEKOLAH,GURU,SISWA')->group(function (): void {
        Route::get('/teachers', [TeacherController::class, 'index']);
        Route::get('/teachers/{teacher}', [TeacherController::class, 'show']);
    });

    Route::middleware('role:TU')->group(function (): void {
        Route::post('/teachers', [TeacherController::class, 'store']);
        Route::put('/teachers/{teacher}', [TeacherController::class, 'update']);
        Route::delete('/teachers/{teacher}', [TeacherController::class, 'destroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | Students
    |--------------------------------------------------------------------------
    */

    Route::middleware('role:TU,KEPALA_SEKOLAH,GURU,SISWA')->group(function (): void {
        Route::get('/students', [StudentController::class, 'index']);
        Route::get('/students/{student}', [StudentController::class, 'show']);
    });

    Route::middleware('role:TU')->group(function (): void {
        Route::post('/students', [StudentController::class, 'store']);
        Route::put('/students/{student}', [StudentController::class, 'update']);
        Route::delete('/students/{student}', [StudentController::class, 'destroy']);
    });
});

Route::get('/health', HealthController::class);