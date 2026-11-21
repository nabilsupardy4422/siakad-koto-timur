<?php

use App\Http\Controllers\Api\AcademicYearController;
use App\Http\Controllers\Api\AcademicYearExcelController;
use App\Http\Controllers\Api\AssignmentController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\Auth\ActivateAccountController;
use App\Http\Controllers\Api\Auth\GoogleLoginController;
use App\Http\Controllers\Api\Auth\GoogleUsernameRecoveryController;
use App\Http\Controllers\Api\Auth\GooglePasswordRecoveryController;
use App\Http\Controllers\Api\Auth\GooglePasswordRecoveryChallengeController;
use App\Http\Controllers\Api\Auth\GooglePasswordRecoveryVerifyController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Http\Controllers\Api\Auth\MeController;
use App\Http\Controllers\Api\ClassController;
use App\Http\Controllers\Api\ClassMemberController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\GradeComponentController;
use App\Http\Controllers\Api\GradeController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\IssueUserActivationCodeController;
use App\Http\Controllers\Api\MaterialController;
use App\Http\Controllers\Api\ReportCardController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\ScheduleExcelController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\StudentExcelController;
use App\Http\Controllers\Api\SubmissionController;
use App\Http\Controllers\Api\SubjectController;
use App\Http\Controllers\Api\TeacherController;
use App\Http\Controllers\Api\TeacherExcelController;
use App\Http\Controllers\Api\WaliKelasController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\UserExcelController;
use App\Http\Controllers\Api\UserImportPreviewController;
use App\Http\Controllers\Api\UserImportController;
use App\Http\Controllers\Api\Profile\GoogleAccountController;
use App\Http\Controllers\Api\Profile\ProfilePhotoController;
use App\Http\Controllers\Api\UserActivationCodeStatusController;
use App\Http\Controllers\Api\CancelUserActivationCodeController;
use App\Http\Controllers\Api\SubmissionAttachmentController;
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\EnsureAccountIsUsable;

Route::post('/login', LoginController::class);

// Google Login hanya untuk akun SIAKAD yang sudah ditautkan.
// Batasi percobaan sebelum memverifikasi ID token Google.
Route::post('/login/google', GoogleLoginController::class)
    ->middleware('throttle:5,1');

// Pemulihan username hanya melalui Google yang sudah tertaut.
// Route publik agar dapat digunakan sebelum login.
Route::post('/recovery/google/username', GoogleUsernameRecoveryController::class)
    ->middleware('throttle:5,1');

// Challenge sementara sebelum reset password menggunakan akun Google tertaut.
// Endpoint publik, tetapi dibatasi untuk mengurangi penyalahgunaan.
Route::post(
    '/recovery/google/password/challenge',
    GooglePasswordRecoveryChallengeController::class
)->middleware('throttle:5,1');

// Verifikasi kepemilikan akun Google sebelum menerbitkan izin reset sementara.
// Harus menggunakan challenge dan Google ID token dengan nonce valid.
Route::post(
    '/recovery/google/password/verify',
    GooglePasswordRecoveryVerifyController::class
)->middleware('throttle:5,1');

// Reset password melalui izin sementara sekali pakai.
// Izin diterbitkan setelah verifikasi Google dan nonce berhasil.
Route::post('/recovery/google/password', GooglePasswordRecoveryController::class)
    ->middleware('throttle:5,1');

// Aktivasi akun Guru/Siswa menggunakan kode sekali pakai.
Route::post('/account/activate', ActivateAccountController::class)
    ->middleware('throttle:5,1');

Route::middleware([
    'auth:sanctum',
    EnsureAccountIsUsable::class,
])->group(function (): void {
    Route::get('/me', MeController::class);
    Route::post('/logout', LogoutController::class);

    /*
    |--------------------------------------------------------------------------
    | Google Account Linking ΓÇö Semua Role SIAKAD
    |--------------------------------------------------------------------------
    | Akun TU, Kepala Sekolah, Guru, dan Siswa dapat menautkan Google
    | setelah login. Middleware EnsureAccountIsUsable tetap berlaku.
    */
    Route::middleware('role:TU,KEPALA_SEKOLAH,GURU,SISWA')->group(function (): void {
        Route::get(
            '/profile/google/status',
            [GoogleAccountController::class, 'status']
        );

        Route::post(
            '/profile/google/link',
            [GoogleAccountController::class, 'link']
        )->middleware('throttle:5,1');

        Route::post(
            '/profile/google/unlink',
            [GoogleAccountController::class, 'unlink']
        )->middleware('throttle:5,1');
    });


    /*
    |--------------------------------------------------------------------------
    | Foto Profil ΓÇö Semua Role SIAKAD
    |--------------------------------------------------------------------------
    | Hanya pengguna yang sedang login dan memiliki akun aktif
    | yang dapat melihat, mengunggah, dan menghapus foto sendiri.
    */
    Route::middleware('role:TU,KEPALA_SEKOLAH,GURU,SISWA')->group(function (): void {
        Route::get(
            '/profile/photo',
            [ProfilePhotoController::class, 'show']
        );

        Route::post(
            '/profile/photo',
            [ProfilePhotoController::class, 'store']
        )->middleware('throttle:10,1');

        Route::delete(
            '/profile/photo',
            [ProfilePhotoController::class, 'destroy']
        )->middleware('throttle:10,1');
    });

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
    Route::middleware('role:TU')->group(function (): void {
    Route::get(
        '/academic-years/template',
        [AcademicYearExcelController::class, 'template']
    );

    Route::post(
        '/academic-years/import/preview',
        [AcademicYearExcelController::class, 'preview']
    );

    Route::post(
        '/academic-years/import',
        [AcademicYearExcelController::class, 'import']
    );

    // Academic Year CRUD - TU Only
    Route::post(
        '/academic-years',
        [AcademicYearController::class, 'store']
    );

    Route::put(
        '/academic-years/{academicYear}',
        [AcademicYearController::class, 'update']
    );

    Route::delete(
        '/academic-years/{academicYear}',
        [AcademicYearController::class, 'destroy']
    );
});

// Academic Year Read - Semua Role
Route::middleware('role:TU,KEPALA_SEKOLAH,GURU,SISWA')->group(function (): void {
    Route::get(
        '/academic-years',
        [AcademicYearController::class, 'index']
    );

    Route::get(
        '/academic-years/{academicYear}',
        [AcademicYearController::class, 'show']
    );
});

    /*
    |--------------------------------------------------------------------------
    | Teachers
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:TU')->group(function (): void {
    Route::get(
        '/teachers/template',
        [TeacherExcelController::class, 'template']
    );

    Route::post(
        '/teachers/import/preview',
        [TeacherExcelController::class, 'preview']
    );

    Route::post(
        '/teachers/import',
        [TeacherExcelController::class, 'import']
    );
});
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

    Route::middleware('role:TU')->group(function (): void {
    Route::get(
        '/students/template',
        [StudentExcelController::class, 'template']
    );

    Route::post(
        '/students/import/preview',
        [StudentExcelController::class, 'preview']
    );

    Route::post(
        '/students/import',
        [StudentExcelController::class, 'import']
    );
});

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
        Route::get('/grades/recap', [GradeController::class, 'recap']);
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


    /*
    |--------------------------------------------------------------------------
    | User Management
    |--------------------------------------------------------------------------
    */

    Route::middleware('role:TU')->group(function (): void {

        /*
        |--------------------------------------------------------------------------
        | Import Pengguna dari Excel
        |--------------------------------------------------------------------------
        */

        // Download template resmi import pengguna.
        Route::get(
            '/users/template',
            [UserExcelController::class, 'template']
        )->middleware('permission:users.create');

        // Preview dan validasi file Excel.
        // Tidak menyimpan data ke database.
        Route::post(
            '/users/import/preview',
            UserImportPreviewController::class
        )->middleware('permission:users.create');

        // Konfirmasi import massal.
        // Memvalidasi ulang dan menyimpan akun.
        Route::post(
            '/users/import',
            UserImportController::class
        )->middleware('permission:users.create');

        /*
        |--------------------------------------------------------------------------
        | Manajemen Data Pengguna
        |--------------------------------------------------------------------------
        */

        // Daftar pengguna.
        Route::get(
            '/users',
            [UserController::class, 'index']
        )->middleware('permission:users.view');

        // Detail pengguna.
        Route::get(
            '/users/{user}',
            [UserController::class, 'show']
        )->middleware('permission:users.view');

        // Tambah pengguna manual.
        Route::post(
            '/users',
            [UserController::class, 'store']
        )->middleware('permission:users.create');

        // Edit pengguna.
        Route::put(
            '/users/{user}',
            [UserController::class, 'update']
        )->middleware('permission:users.update');

        /*
        |--------------------------------------------------------------------------
        | Pengelolaan Status Akun
        |--------------------------------------------------------------------------
        */

        // Aktifkan kembali akun nonaktif.
        Route::post(
            '/users/{user}/activate',
            [UserController::class, 'activate']
        )->middleware('permission:users.activate');

        // Nonaktifkan akun pengguna.
        Route::post(
            '/users/{user}/deactivate',
            [UserController::class, 'deactivate']
        )->middleware('permission:users.deactivate');

        /*
        |--------------------------------------------------------------------------
        | Pengelolaan Aktivasi Akun Guru dan Siswa
        |--------------------------------------------------------------------------
        */

        // TU menerbitkan atau menerbitkan ulang
        // kode aktivasi individual.
        Route::post(
            '/users/{user}/activation-code',
            IssueUserActivationCodeController::class
        )->middleware('permission:users.reset_password');

        // TU memeriksa apakah akun memiliki
        // kode aktivasi yang masih berlaku.
        //
        // Tidak mengembalikan kode asli atau hash.
        //
        // Digunakan frontend untuk menentukan
        // apakah tombol "Batalkan Kode Aktivasi"
        // perlu ditampilkan.
        Route::get(
            '/users/{user}/activation-code/status',
            UserActivationCodeStatusController::class
        )->middleware('permission:users.reset_password');

        // TU membatalkan kode aktivasi.
        //
        // Akun tidak dihapus dan status akun
        // tidak berubah.
        Route::delete(
            '/users/{user}/activation-code',
            CancelUserActivationCodeController::class
        )->middleware('permission:users.reset_password');

        /*
        |--------------------------------------------------------------------------
        | Reset Password oleh TU
        |--------------------------------------------------------------------------
        */

        // Reset password pengguna.
        Route::post(
            '/users/{user}/reset-password',
            [UserController::class, 'resetPassword']
        )->middleware('permission:users.reset_password');

    });

});

Route::get('/health', HealthController::class);
