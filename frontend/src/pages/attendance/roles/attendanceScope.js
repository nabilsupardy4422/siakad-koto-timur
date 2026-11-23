import { collectPages, sameId } from '../../../utils/teachingScope.js'

const statuses = ['HADIR', 'IZIN', 'SAKIT', 'ALPA']

export function isAttendanceDate(date) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) return false
  const parsed = new Date(`${date}T00:00:00Z`)
  return !Number.isNaN(parsed.getTime()) && parsed.toISOString().slice(0, 10) === date
}

export function weekdayMismatch(schedule, date) {
  if (!schedule?.hari || !isAttendanceDate(date)) return false
  const days = ['minggu', 'senin', 'selasa', 'rabu', 'kamis', 'jumat', 'sabtu']
  const day = String(schedule.hari).toLowerCase().replace(/['’]/g, '').trim()
  return day !== days[new Date(`${date}T00:00:00Z`).getUTCDay()]
}

export async function loadAttendanceRoster(api, schedule, date, yearId) {
  const classId = schedule?.kelas_id ?? schedule?.kelas?.id
  if (!schedule?.id || !classId || !yearId || !isAttendanceDate(date)) {
    throw new Error('Jadwal, kelas, periode, atau tanggal presensi belum valid.')
  }
  const [students, records] = await Promise.all([
    collectPages(async page => {
      const response = await api.getRoster({ schedule_id: schedule.id, date, per_page: 100, page })
      const meta = response?.meta
      if (!sameId(meta?.schedule_id, schedule.id) || !sameId(meta?.class_id, classId) ||
          !sameId(meta?.academic_year_id, yearId) || meta?.date !== date) {
        throw new Error('Konteks daftar siswa tidak sesuai dengan jadwal yang dipilih.')
      }
      return response
    }),
    collectPages(page => api.getAll({ scope: 'teaching', academic_year_id: yearId, schedule_id: schedule.id, date, per_page: 100, page })),
  ])
  const byStudent = new Map()
  for (const item of records) {
    if (!sameId(item.jadwal_pelajaran_id, schedule.id) || item.tanggal !== date) {
      throw new Error('Data presensi dari server tidak sesuai dengan konteks pertemuan.')
    }
    if (!statuses.includes(item.status) || item.siswa_id == null || byStudent.has(String(item.siswa_id))) {
      throw new Error('Data presensi tidak valid atau duplikat. Hubungi Tata Usaha.')
    }
    byStudent.set(String(item.siswa_id), item)
  }
  return students.map(student => {
    const existing = byStudent.get(String(student.id))
    return { siswa_id: student.id, nama_lengkap: student.nama_lengkap || '-', nis: student.nis || student.nisn || '-', status: existing?.status || '', catatan: existing?.catatan || '', recorded: Boolean(existing) }
  })
}
