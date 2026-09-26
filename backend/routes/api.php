<?php

use App\Http\Controllers\Api\AcademicYearController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Http\Controllers\Api\Auth\MeController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\TeacherController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\SubjectController;
use App\Http\Controllers\Api\ClassController;
use App\Http\Controllers\Api\ClassMemberController;
use App\Http\Controllers\Api\WaliKelasController;

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

    Route::middleware('role:TU,KEPALA_SEKOLAH,GURU,SISWA')->group(function (): void {
        Route::get('/subjects', [SubjectController::class, 'index']);
        Route::get('/subjects/{subject}', [SubjectController::class, 'show']);
    });

    Route::middleware('role:TU')->group(function (): void {
        Route::post('/subjects', [SubjectController::class, 'store']);
        Route::put('/subjects/{subject}', [SubjectController::class, 'update']);
        Route::delete('/subjects/{subject}', [SubjectController::class, 'destroy']);
    });

      /*
    |--------------------------------------------------------------------------
    | Classes
    |--------------------------------------------------------------------------
    */

    Route::middleware('role:TU,KEPALA_SEKOLAH,GURU,SISWA')->group(function (): void {
        Route::get('/classes', [ClassController::class, 'index']);
        Route::get('/classes/{class}', [ClassController::class, 'show']);
    });

    Route::middleware('role:TU')->group(function (): void {
        Route::post('/classes', [ClassController::class, 'store']);
        Route::put('/classes/{class}', [ClassController::class, 'update']);
        Route::delete('/classes/{class}', [ClassController::class, 'destroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | Class Members
    |--------------------------------------------------------------------------
    */

    Route::middleware('role:TU,KEPALA_SEKOLAH,GURU,SISWA')->group(function (): void {
        Route::get('/classes/{class}/students', [ClassMemberController::class, 'index']);
    });

    Route::middleware('role:TU,GURU')->group(function (): void {
        Route::post('/classes/{class}/students', [ClassMemberController::class, 'store']);
        Route::delete('/classes/{class}/students/{student}', [ClassMemberController::class, 'destroy']);
    });

    Route::middleware('role:TU,KEPALA_SEKOLAH,GURU')->group(function (): void {
        Route::get(
            '/homeroom-assignments',
            [WaliKelasController::class, 'index']
        );

        Route::get(
            '/homeroom-assignments/{assignment}',
            [WaliKelasController::class, 'show']
        );
    });

    Route::middleware('role:TU')->group(function (): void {
        Route::post(
            '/homeroom-assignments',
            [WaliKelasController::class, 'store']
        );

        Route::put(
            '/homeroom-assignments/{assignment}',
            [WaliKelasController::class, 'update']
        );

        Route::delete(
            '/homeroom-assignments/{assignment}',
            [WaliKelasController::class, 'destroy']
        );
    });
});

Route::get('/health', HealthController::class);