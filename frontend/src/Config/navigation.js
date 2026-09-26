const navigation = {
    TU: [
      {
        label: 'Dashboard',
        path: '/app/dashboard',
      },

      {
        label: 'Data Akademik',
        section: true,
      },
      {
        label: 'Tahun Akademik',
        path: '/app/academic-years',
        role: 'TU',
      },
      {
        label: 'Guru',
        path: '/app/teachers',
        permission: 'teachers.view',
      },
      {
        label: 'Siswa',
        path: '/app/students',
        permission: 'students.view',
      },
      {
        label: 'Mata Pelajaran',
        path: '/app/subjects',
        permission: 'subjects.view',
      },
      {
        label: 'Kelas',
        path: '/app/classes',
        permission: 'classes.view',
      },

      {
        label: 'Kegiatan Akademik',
        section: true,
      },
      {
        label: 'Jadwal',
        path: '/app/schedules',
        permission: 'schedules.view',
      },
      {
        label: 'Presensi',
        path: '/app/attendance',
        permission: 'attendance.recap',
      },
      {
        label: 'Penilaian',
        path: '/app/grades',
        permission: 'grades.recap',
      },
      {
        label: 'Bahan Ajar',
        path: '/app/materials',
        permission: 'materials.view',
      },
      {
        label: 'Tugas',
        path: '/app/assignments',
        permission: 'assignments.view',
      },
      {
        label: 'Rapor',
        path: '/app/report-cards',
        permission: 'report_cards.view',
      },
      {
        label: 'Laporan',
        path: '/app/reports',
        permission: 'reports.view',
      },

      {
        label: 'Manajemen Akun',
        section: true,
      },
      {
        label: 'Pengguna',
        path: '/app/users',
        permission: 'users.view',
      },
    ],

    KEPALA_SEKOLAH: [
      {
        label: 'Dashboard',
        path: '/app/dashboard',
      },

      {
        label: 'Monitoring',
        section: true,
      },
      {
        label: 'Presensi',
        path: '/app/attendance',
        permission: 'attendance.recap',
      },
      {
        label: 'Penilaian',
        path: '/app/grades',
        permission: 'grades.recap',
      },
      {
        label: 'Jadwal',
        path: '/app/schedules',
        permission: 'schedules.view',
      },
      {
        label: 'Rapor',
        path: '/app/report-cards',
        permission: 'report_cards.view',
      },
      {
        label: 'Laporan',
        path: '/app/reports',
        permission: 'reports.view',
      },
    ],

    GURU: [
      {
        label: 'Dashboard',
        path: '/app/dashboard',
      },

      {
        label: 'Kegiatan Mengajar',
        section: true,
      },
      {
        label: 'Jadwal Mengajar',
        path: '/app/schedules',
        permission: 'schedules.view',
      },
      {
        label: 'Presensi',
        path: '/app/attendance',
        permission: 'attendance.view',
      },
      {
        label: 'Bahan Ajar',
        path: '/app/materials',
        permission: 'materials.view',
      },
      {
        label: 'Tugas',
        path: '/app/assignments',
        permission: 'assignments.view',
      },
      {
        label: 'Penilaian',
        path: '/app/grades',
        permission: 'grades.view',
      },
      {
        label: 'Rapor',
        path: '/app/report-cards',
        permission: 'report_cards.view',
      },

      {
        label: 'Wali Kelas',
        section: true,
        assignment: 'WALI_KELAS',
      },
      {
        label: 'Kelas Saya',
        path: '/app/homeroom',
        assignment: 'WALI_KELAS',
      },
    ],

    SISWA: [
      {
        label: 'Dashboard',
        path: '/app/dashboard',
      },

      {
        label: 'Akademik',
        section: true,
      },
      {
        label: 'Jadwal',
        path: '/app/schedules',
        permission: 'schedules.view',
      },
      {
        label: 'Presensi',
        path: '/app/attendance',
        permission: 'attendance.view',
      },
      {
        label: 'Nilai',
        path: '/app/grades',
        permission: 'grades.view',
      },
      {
        label: 'Bahan Ajar',
        path: '/app/materials',
        permission: 'materials.view',
      },
      {
        label: 'Tugas',
        path: '/app/assignments',
        permission: 'assignments.view',
      },
      {
        label: 'Rapor',
        path: '/app/report-cards',
        permission: 'report_cards.view',
      },
    ],
  }

  export default navigation