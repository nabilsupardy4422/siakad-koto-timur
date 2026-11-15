<?php

use App\Http\Controllers\Api\AcademicYearController;
use App\Http\Controllers\Api\AssignmentController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Http\Controllers\Api\Auth\MeController;
use App\Http\Controllers\Api\ClassController;
use App\Http\Controllers\Api\ClassMemberController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\GradeComponentController;
use App\Http\Controllers\Api\GradeController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\MaterialController;
use App\Http\Controllers\Api\ReportCardController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\ScheduleExcelController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\SubmissionController;
use App\Http\Controllers\Api\SubjectController;
use App\Http\Controllers\Api\TeacherController;
use App\Http\Controllers\Api\WaliKelasController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\SubmissionAttachmentController;
use Illuminate\Support\Facades\Route;

Route::post('/login', LoginController::class);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/me', MeController::class);
    Route::post('/logout', LogoutController::class);

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    */
    Route::get(
        '/dashboard',
        DashboardController::class
    )->middleware('role:TU,KEPALA_SEKOLAH,GURU,SISWA');

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
        Route::get(
            '/teacher-accounts/available',
            [TeacherController::class, 'availableAccounts']
        );

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
        Route::get(
            '/student-accounts/available',
            [StudentController::class, 'availableAccounts']
        );

        Route::post('/students', [StudentController::class, 'store']);
        Route::put('/students/{student}', [StudentController::class, 'update']);
        Route::delete('/students/{student}', [StudentController::class, 'destroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | Subjects
    |--------------------------------------------------------------------------
    */
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

    /*
    |--------------------------------------------------------------------------
    | Homeroom Assignments
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:TU,KEPALA_SEKOLAH,GURU')->group(function (): void {
        Route::get('/homeroom-assignments', [WaliKelasController::class, 'index']);
        Route::get('/homeroom-assignments/{assignment}', [WaliKelasController::class, 'show']);
    });

    Route::middleware('role:TU')->group(function (): void {
        Route::post('/homeroom-assignments', [WaliKelasController::class, 'store']);
        Route::put('/homeroom-assignments/{assignment}', [WaliKelasController::class, 'update']);
        Route::delete('/homeroom-assignments/{assignment}', [WaliKelasController::class, 'destroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | Schedules
    |--------------------------------------------------------------------------
    */

    // Schedule Excel - TU Only
    // Route Excel harus berada sebelum /schedules/{schedule}.
    Route::middleware('role:TU')->group(function (): void {
        Route::get(
            '/schedules/template',
            [ScheduleExcelController::class, 'template']
        );

        Route::post(
            '/schedules/import',
            [ScheduleExcelController::class, 'import']
        );

        Route::get(
            '/schedules/export',
            [ScheduleExcelController::class, 'export']
        );
    });

    // Schedule Read
    Route::middleware('role:TU,KEPALA_SEKOLAH,GURU,SISWA')->group(function (): void {
        Route::get('/schedules', [ScheduleController::class, 'index']);
        Route::get('/schedules/{schedule}', [ScheduleController::class, 'show']);
    });

    // Schedule CRUD - TU Only
    Route::middleware('role:TU')->group(function (): void {
        Route::post('/schedules', [ScheduleController::class, 'store']);
        Route::put('/schedules/{schedule}', [ScheduleController::class, 'update']);
        Route::delete('/schedules/{schedule}', [ScheduleController::class, 'destroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | Attendance
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:TU,KEPALA_SEKOLAH,GURU,SISWA')->group(function (): void {
        Route::get('/attendance', [AttendanceController::class, 'index']);
        Route::get('/attendance/recap', [AttendanceController::class, 'recap']);
    });

    Route::middleware('role:GURU')->group(function (): void {
        Route::post('/attendance', [AttendanceController::class, 'store']);
        Route::post('/attendance/bulk', [AttendanceController::class, 'bulkStore']);
        Route::put('/attendance/{attendance}', [AttendanceController::class, 'update']);
    });

    Route::get(
        '/attendance/class-recap',
        [AttendanceController::class, 'classRecap']
    )->middleware('role:TU,KEPALA_SEKOLAH');

    /*
    |--------------------------------------------------------------------------
    | Teaching Materials
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:GURU,SISWA')->group(function (): void {
        Route::get('/materials', [MaterialController::class, 'index']);
        Route::get('/materials/{material}', [MaterialController::class, 'show']);

        Route::get(
            '/materials/{material}/download',
            [MaterialController::class, 'download']
        );

        Route::get(
            '/materials/{material}/attachments/{attachment}/download',
            [MaterialController::class, 'downloadAttachment']
        );
    });

    Route::middleware('role:GURU')->group(function (): void {
        Route::post('/materials', [MaterialController::class, 'store']);
        Route::put('/materials/{material}', [MaterialController::class, 'update']);
        Route::delete('/materials/{material}', [MaterialController::class, 'destroy']);

        Route::delete(
            '/materials/{material}/attachments/{attachment}',
            [MaterialController::class, 'destroyAttachment']
        );
    });

    /*
    |--------------------------------------------------------------------------
    | Grade Components
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:TU,KEPALA_SEKOLAH,GURU,SISWA')->group(function (): void {
        Route::get('/grade-components', [GradeComponentController::class, 'index']);
        Route::get('/grade-components/{component}', [GradeComponentController::class, 'show']);
    });

    Route::middleware('role:GURU')->group(function (): void {
        Route::post('/grade-components', [GradeComponentController::class, 'store']);
        Route::put('/grade-components/{component}', [GradeComponentController::class, 'update']);
        Route::delete('/grade-components/{component}', [GradeComponentController::class, 'destroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | Grades
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:TU,KEPALA_SEKOLAH,GURU,SISWA')->group(function (): void {
        Route::get('/grades', [GradeController::class, 'index']);
    });

    Route::middleware('role:GURU')->group(function (): void {
        Route::post('/grades', [GradeController::class, 'store']);
        Route::put('/grades/{grade}', [GradeController::class, 'update']);
    });

    /*
    |--------------------------------------------------------------------------
    | Assignments and Assignment Attachments
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:TU,KEPALA_SEKOLAH,GURU,SISWA')->group(function (): void {
        Route::get('/assignments', [AssignmentController::class, 'index']);
        Route::get('/assignments/{assignment}', [AssignmentController::class, 'show']);

        // Kompatibilitas endpoint unduhan tugas lama.
        Route::get(
            '/assignments/{assignment}/download',
            [AssignmentController::class, 'download']
        )->name('assignments.download');

        // Unduh satu lampiran berdasarkan ID.
        Route::get(
            '/assignment-attachments/{attachment}/download',
            [AssignmentController::class, 'downloadAttachment']
        )->name('assignment-attachments.download');
    });

    Route::middleware('role:GURU')->group(function (): void {
        Route::post('/assignments', [AssignmentController::class, 'store']);
        Route::put('/assignments/{assignment}', [AssignmentController::class, 'update']);
        Route::delete('/assignments/{assignment}', [AssignmentController::class, 'destroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | Submissions
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:GURU,SISWA')->group(function (): void {
        Route::get('/assignments/{assignment}/submissions', [SubmissionController::class, 'index']);
        Route::get('/submissions/{submission}', [SubmissionController::class, 'show']);
    });

    Route::middleware('role:SISWA')->group(function (): void {
        Route::post('/assignments/{assignment}/submissions', [SubmissionController::class, 'store']);
        Route::put('/submissions/{submission}', [SubmissionController::class, 'update']);
    });

    Route::middleware('role:GURU,SISWA')->group(function (): void {
        Route::get(
            '/submission-attachments/{attachment}/view',
            [SubmissionAttachmentController::class, 'view']
        )->name('submission-attachments.view');

        Route::get(
            '/submission-attachments/{attachment}/download',
            [SubmissionAttachmentController::class, 'download']
        )->name('submission-attachments.download');
    });

    /*
    |--------------------------------------------------------------------------
    | Report Cards
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:TU,SISWA')->group(function (): void {
        Route::get('/report-cards', [ReportCardController::class, 'index']);
        Route::get('/report-cards/{reportCard}', [ReportCardController::class, 'show']);
        Route::get('/report-cards/{reportCard}/download', [ReportCardController::class, 'download']);
    });

    Route::middleware('role:TU')->group(function (): void {
        Route::post('/report-cards', [ReportCardController::class, 'store']);
        Route::delete('/report-cards/{reportCard}', [ReportCardController::class, 'destroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | Reporting / Recap
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:TU,KEPALA_SEKOLAH,GURU,SISWA')->group(function (): void {
        Route::get('/reports/{reportType}', [ReportController::class, 'index']);
    });

    /*
    |--------------------------------------------------------------------------
    | User Management
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:TU')->group(function (): void {
        Route::get('/users', [UserController::class, 'index'])
            ->middleware('permission:users.view');

        Route::get('/users/{user}', [UserController::class, 'show'])
            ->middleware('permission:users.view');

        Route::post('/users', [UserController::class, 'store'])
            ->middleware('permission:users.create');

        Route::put('/users/{user}', [UserController::class, 'update'])
            ->middleware('permission:users.update');

        Route::post('/users/{user}/activate', [UserController::class, 'activate'])
            ->middleware('permission:users.activate');

        Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate'])
            ->middleware('permission:users.deactivate');
    });
});

Route::get('/health', HealthController::class);
