// Metadata rekap disediakan backend HANYA setelah otorisasi jadwal Guru.
// Field tidak tersedia pada respons Siswa atau server versi sebelumnya.
// Jangan pernah merekayasa jumlah siswa dari pagination submission.
const isId = value => Number.isSafeInteger(value) && value > 0
const isCount = value => Number.isSafeInteger(value) && value >= 0

export function parseSubmissionClassOverview(overview, assignment) {
  if (overview == null) return null
  if (typeof overview !== 'object' || Array.isArray(overview)) {
    throw new Error('Rekap anggota kelas tidak valid.')
  }
  if (!isId(overview.assignment_id) || !isId(overview.class_id) ||
      !['total_students', 'submitted_students', 'not_submitted_students'].every(k => isCount(overview[k]))) {
    throw new Error('Angka rekap anggota kelas tidak valid.')
  }
  if (String(overview.assignment_id) !== String(assignment?.id)) {
    throw new Error('Rekap anggota bukan untuk tugas yang sedang dilihat.')
  }
  const expectedClass = assignment?.jadwal_pelajaran?.kelas?.id ?? assignment?.jadwal_pelajaran?.kelas_id
  if (expectedClass != null && String(expectedClass) !== String(overview.class_id)) {
    throw new Error('Rekap anggota berasal dari kelas berbeda.')
  }
  if (overview.submitted_students > overview.total_students ||
      overview.total_students !== overview.submitted_students + overview.not_submitted_students) {
    throw new Error('Hitungan anggota kelas tidak konsisten.')
  }
  if (typeof overview.as_of !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(overview.as_of)) {
    throw new Error('Tanggal rekap kelas tidak valid.')
  }
  if (overview.subject_name != null && typeof overview.subject_name !== 'string') {
    throw new Error('Mata pelajaran rekap tidak valid.')
  }
  if (overview.class_name != null && typeof overview.class_name !== 'string') {
    throw new Error('Nama kelas tidak valid.')
  }
  return overview
}
