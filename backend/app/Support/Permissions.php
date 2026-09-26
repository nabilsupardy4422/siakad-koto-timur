<?php

namespace App\Support;

class Permissions
{
    public const STUDENTS_VIEW = 'students.view';
    public const STUDENTS_CREATE = 'students.create';
    public const STUDENTS_UPDATE = 'students.update';
    public const STUDENTS_DELETE = 'students.delete';

    public const TEACHERS_VIEW = 'teachers.view';
    public const TEACHERS_CREATE = 'teachers.create';
    public const TEACHERS_UPDATE = 'teachers.update';
    public const TEACHERS_DELETE = 'teachers.delete';

    public const CLASSES_VIEW = 'classes.view';
    public const CLASSES_CREATE = 'classes.create';
    public const CLASSES_UPDATE = 'classes.update';
    public const CLASSES_DELETE = 'classes.delete';

    public const SUBJECTS_VIEW = 'subjects.view';
    public const SUBJECTS_CREATE = 'subjects.create';
    public const SUBJECTS_UPDATE = 'subjects.update';
    public const SUBJECTS_DELETE = 'subjects.delete';

    public const SCHEDULES_VIEW = 'schedules.view';
    public const SCHEDULES_CREATE = 'schedules.create';
    public const SCHEDULES_UPDATE = 'schedules.update';
    public const SCHEDULES_DELETE = 'schedules.delete';

    public const ATTENDANCE_VIEW = 'attendance.view';
    public const ATTENDANCE_CREATE = 'attendance.create';
    public const ATTENDANCE_UPDATE = 'attendance.update';
    public const ATTENDANCE_RECAP = 'attendance.recap';

    public const GRADES_VIEW = 'grades.view';
    public const GRADES_CREATE = 'grades.create';
    public const GRADES_UPDATE = 'grades.update';
    public const GRADES_RECAP = 'grades.recap';

    public const MATERIALS_VIEW = 'materials.view';
    public const MATERIALS_CREATE = 'materials.create';
    public const MATERIALS_UPDATE = 'materials.update';
    public const MATERIALS_DELETE = 'materials.delete';

    public const ASSIGNMENTS_VIEW = 'assignments.view';
    public const ASSIGNMENTS_CREATE = 'assignments.create';
    public const ASSIGNMENTS_UPDATE = 'assignments.update';
    public const ASSIGNMENTS_DELETE = 'assignments.delete';
    public const ASSIGNMENTS_SUBMIT = 'assignments.submit';

    public const REPORTS_VIEW = 'reports.view';
    public const REPORTS_EXPORT = 'reports.export';

    public const REPORT_CARDS_VIEW = 'report_cards.view';
    public const REPORT_CARDS_UPLOAD = 'report_cards.upload';
    public const REPORT_CARDS_DELETE = 'report_cards.delete';

    public const USERS_VIEW = 'users.view';
    public const USERS_CREATE = 'users.create';
    public const USERS_UPDATE = 'users.update';
    public const USERS_ACTIVATE = 'users.activate';
    public const USERS_DEACTIVATE = 'users.deactivate';
    public const USERS_RESET_PASSWORD = 'users.reset_password';

    public static function all(): array
    {
        return [
            self::STUDENTS_VIEW,
            self::STUDENTS_CREATE,
            self::STUDENTS_UPDATE,
            self::STUDENTS_DELETE,

            self::TEACHERS_VIEW,
            self::TEACHERS_CREATE,
            self::TEACHERS_UPDATE,
            self::TEACHERS_DELETE,

            self::CLASSES_VIEW,
            self::CLASSES_CREATE,
            self::CLASSES_UPDATE,
            self::CLASSES_DELETE,

            self::SUBJECTS_VIEW,
            self::SUBJECTS_CREATE,
            self::SUBJECTS_UPDATE,
            self::SUBJECTS_DELETE,

            self::SCHEDULES_VIEW,
            self::SCHEDULES_CREATE,
            self::SCHEDULES_UPDATE,
            self::SCHEDULES_DELETE,

            self::ATTENDANCE_VIEW,
            self::ATTENDANCE_CREATE,
            self::ATTENDANCE_UPDATE,
            self::ATTENDANCE_RECAP,

            self::GRADES_VIEW,
            self::GRADES_CREATE,
            self::GRADES_UPDATE,
            self::GRADES_RECAP,

            self::MATERIALS_VIEW,
            self::MATERIALS_CREATE,
            self::MATERIALS_UPDATE,
            self::MATERIALS_DELETE,

            self::ASSIGNMENTS_VIEW,
            self::ASSIGNMENTS_CREATE,
            self::ASSIGNMENTS_UPDATE,
            self::ASSIGNMENTS_DELETE,
            self::ASSIGNMENTS_SUBMIT,

            self::REPORTS_VIEW,
            self::REPORTS_EXPORT,

            self::REPORT_CARDS_VIEW,
            self::REPORT_CARDS_UPLOAD,
            self::REPORT_CARDS_DELETE,

            self::USERS_VIEW,
            self::USERS_CREATE,
            self::USERS_UPDATE,
            self::USERS_ACTIVATE,
            self::USERS_DEACTIVATE,
            self::USERS_RESET_PASSWORD,
        ];
    }

    public static function forRole(string $role): array
    {
        return match ($role) {
            'TU' => [
                self::STUDENTS_VIEW,
                self::STUDENTS_CREATE,
                self::STUDENTS_UPDATE,
                self::STUDENTS_DELETE,

                self::TEACHERS_VIEW,
                self::TEACHERS_CREATE,
                self::TEACHERS_UPDATE,
                self::TEACHERS_DELETE,

                self::CLASSES_VIEW,
                self::CLASSES_CREATE,
                self::CLASSES_UPDATE,
                self::CLASSES_DELETE,

                self::SUBJECTS_VIEW,
                self::SUBJECTS_CREATE,
                self::SUBJECTS_UPDATE,
                self::SUBJECTS_DELETE,

                self::SCHEDULES_VIEW,
                self::SCHEDULES_CREATE,
                self::SCHEDULES_UPDATE,
                self::SCHEDULES_DELETE,

                self::ATTENDANCE_VIEW,
                self::ATTENDANCE_RECAP,

                self::GRADES_VIEW,
                self::GRADES_RECAP,

                self::MATERIALS_VIEW,

                self::ASSIGNMENTS_VIEW,

                self::REPORTS_VIEW,
                self::REPORTS_EXPORT,

                self::REPORT_CARDS_VIEW,
                self::REPORT_CARDS_UPLOAD,
                self::REPORT_CARDS_DELETE,

                self::USERS_VIEW,
                self::USERS_CREATE,
                self::USERS_UPDATE,
                self::USERS_ACTIVATE,
                self::USERS_DEACTIVATE,
                self::USERS_RESET_PASSWORD,
            ],

            'KEPALA_SEKOLAH' => [
                self::STUDENTS_VIEW,
                self::TEACHERS_VIEW,
                self::CLASSES_VIEW,
                self::SUBJECTS_VIEW,
                self::SCHEDULES_VIEW,

                self::ATTENDANCE_VIEW,
                self::ATTENDANCE_RECAP,

                self::GRADES_VIEW,
                self::GRADES_RECAP,

                self::MATERIALS_VIEW,
                self::ASSIGNMENTS_VIEW,

                self::REPORTS_VIEW,
                self::REPORTS_EXPORT,

                self::REPORT_CARDS_VIEW,
            ],

            'GURU' => [
                self::STUDENTS_VIEW,
                self::SUBJECTS_VIEW,
                self::SCHEDULES_VIEW,

                self::ATTENDANCE_VIEW,
                self::ATTENDANCE_CREATE,
                self::ATTENDANCE_UPDATE,
                self::ATTENDANCE_RECAP,

                self::GRADES_VIEW,
                self::GRADES_CREATE,
                self::GRADES_UPDATE,
                self::GRADES_RECAP,

                self::MATERIALS_VIEW,
                self::MATERIALS_CREATE,
                self::MATERIALS_UPDATE,
                self::MATERIALS_DELETE,

                self::ASSIGNMENTS_VIEW,
                self::ASSIGNMENTS_CREATE,
                self::ASSIGNMENTS_UPDATE,
                self::ASSIGNMENTS_DELETE,

                self::REPORTS_VIEW,

                self::REPORT_CARDS_VIEW,
            ],

            'SISWA' => [
                self::STUDENTS_VIEW,
                self::SUBJECTS_VIEW,
                self::SCHEDULES_VIEW,

                self::ATTENDANCE_VIEW,
                self::GRADES_VIEW,

                self::MATERIALS_VIEW,

                self::ASSIGNMENTS_VIEW,
                self::ASSIGNMENTS_SUBMIT,

                self::REPORT_CARDS_VIEW,
            ],

            default => [],
        };
    }
}