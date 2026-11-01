import { useCallback, useEffect, useMemo, useState } from 'react'
import PageHeader from '../../../components/ui/PageHeader'
import Card from '../../../components/ui/Card'
import Select from '../../../components/ui/Select'
import Input from '../../../components/ui/Input'
import Button from '../../../components/ui/Button'
import LoadingState from '../../../components/ui/LoadingState'
import ErrorState from '../../../components/ui/ErrorState'
import EmptyState from '../../../components/ui/EmptyState'
import { attendanceApi } from '../../../services/attendanceApi'
import { scheduleApi } from '../../../services/scheduleApi'
import { studentApi } from '../../../services/studentApi'
import './GuruAttendancePage.css'

const STATUS_OPTIONS = [
  { value: 'HADIR', label: 'Hadir' },
  { value: 'IZIN', label: 'Izin' },
  { value: 'SAKIT', label: 'Sakit' },
  { value: 'ALPA', label: 'Alpa' },
]

function today() {
  const date = new Date()
  return new Date(date.getTime() - date.getTimezoneOffset() * 60000)
    .toISOString()
    .slice(0, 10)
}

function getCollection(response) {
  if (Array.isArray(response?.data)) return response.data
  if (Array.isArray(response?.data?.data)) return response.data.data
  return []
}

function getErrorMessage(error) {
  return error?.payload?.message || error?.message || 'Terjadi kesalahan saat memuat presensi.'
}

function getValidationMessage(error) {
  const errors = error?.errors || error?.payload?.errors
  if (errors && typeof errors === 'object') {
    const first = Object.values(errors).flat()[0]
    if (first) return first
  }
  return getErrorMessage(error)
}

function scheduleLabel(schedule) {
  const time = [schedule?.jam_mulai, schedule?.jam_selesai].filter(Boolean).join('–')
  return [
    schedule?.kelas?.nama || 'Kelas',
    schedule?.mapel?.nama || 'Mata pelajaran',
    schedule?.hari,
    time,
  ].filter(Boolean).join(' • ')
}

export default function GuruAttendancePage() {
  const [schedules, setSchedules] = useState([])
  const [scheduleId, setScheduleId] = useState('')
  const [date, setDate] = useState(today())
  const [students, setStudents] = useState([])
  const [attendanceRows, setAttendanceRows] = useState([])
  const [loadingSchedules, setLoadingSchedules] = useState(true)
  const [loadingStudents, setLoadingStudents] = useState(false)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')

  const selectedSchedule = useMemo(
    () => schedules.find((item) => String(item.id) === String(scheduleId)) || null,
    [schedules, scheduleId],
  )

  const loadSchedules = useCallback(async () => {
    setLoadingSchedules(true)
    setError('')
    try {
      const response = await scheduleApi.getAll({ per_page: 100 })
      const data = getCollection(response)
      setSchedules(data)
      setScheduleId((current) =>
        current && data.some((item) => String(item.id) === String(current))
          ? current
          : '',
      )
    } catch (err) {
      setSchedules([])
      setScheduleId('')
      setError(getErrorMessage(err))
    } finally {
      setLoadingSchedules(false)
    }
  }, [])

  useEffect(() => {
    loadSchedules()
  }, [loadSchedules])

  const loadStudentsAndAttendance = useCallback(async () => {
    if (!selectedSchedule || !date) {
      setStudents([])
      setAttendanceRows([])
      return
    }

    setLoadingStudents(true)
    setError('')
    try {
      const [studentResponse, attendanceResponse] = await Promise.all([
        studentApi.getAll({
          class_id: selectedSchedule.kelas?.id,
          academic_year_id: selectedSchedule.tahun_akademik_id || selectedSchedule.tahunAkademik?.id,
          active_on: date,
          page_size: 100,
        }),
        attendanceApi.getAll({
          schedule_id: selectedSchedule.id,
          date,
          per_page: 100,
        }),
      ])

      const studentList = getCollection(studentResponse)
      const attendanceList = getCollection(attendanceResponse)
      const byStudent = new Map()
      attendanceList.forEach((item) => {
        const id = item.siswa_id ?? item.siswa?.id
        if (id != null) byStudent.set(String(id), item)
      })

      setStudents(studentList)
      setAttendanceRows(studentList.map((student) => {
        const existing = byStudent.get(String(student.id))
        return {
          siswa_id: student.id,
          nama_lengkap: student.nama_lengkap || student.nama || '-',
          nis: student.nis || student.nisn || '-',
          status: existing?.status || 'HADIR',
          catatan: existing?.catatan || '',
        }
      }))
    } catch (err) {
      setStudents([])
      setAttendanceRows([])
      setError(getErrorMessage(err))
    } finally {
      setLoadingStudents(false)
    }
  }, [selectedSchedule, date])

  useEffect(() => {
    loadStudentsAndAttendance()
  }, [loadStudentsAndAttendance])

  function updateRow(studentId, field, value) {
    setAttendanceRows((current) => current.map((row) =>
      String(row.siswa_id) === String(studentId) ? { ...row, [field]: value } : row,
    ))
  }

 
async function saveAttendance() {
    if (!selectedSchedule || !date || attendanceRows.length === 0 || saving) {
      return
    }
  
    setSaving(true)
    setError('')
    setNotice('')
  
    try {
      await attendanceApi.bulkSave({
        jadwal_pelajaran_id: Number(selectedSchedule.id),
        tanggal: date,
        presensi: attendanceRows.map((row) => ({
          siswa_id: Number(row.siswa_id),
          status: row.status,
          catatan: row.catatan.trim() || null,
        })),
      })
  
      // Tampilkan konfirmasi setelah API menyatakan penyimpanan berhasil.
      // Loader tidak menghapus pesan ini saat memuat ulang data.
      setNotice('Presensi kelas berhasil disimpan.')

      // Segarkan daftar agar status terbaru tampil di layar.
      await loadStudentsAndAttendance()
    } catch (err) {
      setError(getValidationMessage(err))
    } finally {
      setSaving(false)
    }
  }
  

  return (
    <div className="attendance-page">
      <PageHeader
        title="Presensi Kelas"
        description="Pilih jadwal mengajar untuk mengisi kehadiran seluruh siswa dalam satu kelas."
      />

      {error && <ErrorState message={error} />}
      {notice && <div className="attendance-notice" role="status">{notice}</div>}

      <Card>
        <div className="attendance-toolbar">
          <div>
            <h2>Pilih Jadwal dan Tanggal</h2>
            <p>Daftar siswa akan ditampilkan berdasarkan kelas pada jadwal yang dipilih.</p>
          </div>
        </div>
        <div className="attendance-filters attendance-guru-filters">
          <Select
            id="guru-attendance-schedule"
            label="Jadwal Mengajar / Kelas"
            value={scheduleId}
            options={schedules.map((item) => ({ value: String(item.id), label: scheduleLabel(item) }))}
            placeholder={loadingSchedules ? 'Memuat jadwal...' : 'Pilih jadwal mengajar'}
            disabled={loadingSchedules || schedules.length === 0}
            required
            onChange={(event) => {
              setNotice('')
              setScheduleId(event.target.value)
            }}
          />
          <Input
            id="guru-attendance-date"
            label="Tanggal Presensi"
            type="date"
            value={date}
            required
            onChange={(event) => {
              setNotice('')
              setDate(event.target.value)
            }}
          />
        </div>
        {selectedSchedule && (
          <p className="attendance-class-context">
            Kelas: <strong>{selectedSchedule.kelas?.nama || '-'}</strong>
            {' • '}Mata pelajaran: <strong>{selectedSchedule.mapel?.nama || '-'}</strong>
          </p>
        )}
      </Card>

      <Card>
        <div className="attendance-table-heading">
          <div>
            <h2>Daftar Siswa</h2>
            <p>{selectedSchedule ? `${attendanceRows.length} siswa dalam daftar presensi` : 'Pilih jadwal mengajar untuk menampilkan nama siswa.'}</p>
          </div>
          <Button
            onClick={saveAttendance}
            loading={saving}
            disabled={!selectedSchedule || loadingStudents || attendanceRows.length === 0 || saving}
          >
            Simpan Presensi Kelas
          </Button>
        </div>

        {!selectedSchedule ? (
          <EmptyState title="Jadwal belum dipilih" description="Pilih salah satu jadwal mengajar untuk melihat seluruh siswa di kelas tersebut." />
        ) : loadingStudents ? (
          <LoadingState />
        ) : attendanceRows.length === 0 ? (
          <EmptyState title="Siswa tidak ditemukan" description="Tidak ada siswa yang dikembalikan untuk kelas dan tanggal ini. Pastikan anggota kelas sudah terdaftar." />
        ) : (
          <div className="attendance-bulk-table-wrap">
            <table className="attendance-bulk-table">
              <thead>
                <tr>
                  <th>No.</th>
                  <th>Nama Siswa</th>
                  <th>NIS/NISN</th>
                  <th>Status Kehadiran</th>
                  <th>Catatan</th>
                </tr>
              </thead>
              <tbody>
                {attendanceRows.map((row, index) => (
                  <tr key={row.siswa_id}>
                    <td>{index + 1}</td>
                    <td><strong>{row.nama_lengkap}</strong></td>
                    <td>{row.nis}</td>
                    <td>
                      <select
                        className="attendance-bulk-status"
                        value={row.status}
                        aria-label={`Status kehadiran ${row.nama_lengkap}`}
                        onChange={(event) => updateRow(row.siswa_id, 'status', event.target.value)}
                      >
                        {STATUS_OPTIONS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                      </select>
                    </td>
                    <td>
                      <input
                        className="attendance-bulk-note"
                        value={row.catatan}
                        maxLength={1000}
                        aria-label={`Catatan ${row.nama_lengkap}`}
                        placeholder="Opsional"
                        onChange={(event) => updateRow(row.siswa_id, 'catatan', event.target.value)}
                      />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  )
}
