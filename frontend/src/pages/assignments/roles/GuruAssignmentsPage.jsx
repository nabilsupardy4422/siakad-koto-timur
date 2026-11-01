import { useCallback, useEffect, useMemo, useState } from 'react'
import { assignmentApi } from '../../../services/assignmentApi'
import { scheduleApi } from '../../../services/scheduleApi'
import { academicYearApi } from '../../../services/academicYearApi'
import '../styles/GuruAssignmentsPage.css'
import GuruAssignmentSubmissions from './GuruAssignmentSubmissions'

const MAX_FILE_SIZE = 10 * 1024 * 1024
const ACCEPTED_FILE_TYPES =
  '.jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx'

const EMPTY_FORM = {
  jadwal_pelajaran_id: '',
  komponen_nilai_id: '',
  judul: '',
  deskripsi: '',
  deadline: '',
  files: [],
  remove_attachment_ids: [],
  remove_file: false,
}

function collection(response) {
  if (Array.isArray(response?.data?.data)) return response.data.data
  if (Array.isArray(response?.data)) return response.data
  if (Array.isArray(response)) return response
  return []
}

function getMessage(error) {
  const errors = error?.payload?.errors || error?.errors

  if (errors && typeof errors === 'object') {
    const first = Object.values(errors).flat()[0]
    if (first) return first
  }

  return (
    error?.payload?.message ||
    error?.message ||
    'Terjadi kesalahan. Silakan coba kembali.'
  )
}

function scheduleDetails(schedule = {}) {
  const kelas =
    schedule?.kelas?.nama ||
    schedule?.kelas?.nama_kelas ||
    'Kelas'

  const mapel =
    schedule?.mapel?.nama ||
    schedule?.mapel?.nama_mapel ||
    'Mata pelajaran'

  const tingkat = schedule?.kelas?.tingkat

  const time = [
    schedule?.jam_mulai,
    schedule?.jam_selesai,
  ]
    .filter(Boolean)
    .map((value) => String(value).slice(0, 5))
    .join('–')

  return [
    mapel,
    tingkat != null ? `Tingkat ${tingkat}` : '',
    kelas,
    schedule?.hari,
    time,
  ]
    .filter(Boolean)
    .join(' • ')
}

function getSubjectName(item, schedules) {
  const linkedSchedule = item?.jadwal_pelajaran

  if (linkedSchedule?.mapel?.nama) {
    return linkedSchedule.mapel.nama
  }

  if (linkedSchedule?.mapel?.nama_mapel) {
    return linkedSchedule.mapel.nama_mapel
  }

  const schedule = schedules.find(
    (entry) =>
      String(entry.id) ===
      String(
        item?.jadwal_pelajaran_id ??
          linkedSchedule?.id ??
          '',
      ),
  )

  return (
    schedule?.mapel?.nama ||
    schedule?.mapel?.nama_mapel ||
    'Mata Pelajaran Lainnya'
  )
}

function getAssignmentAttachments(item) {
  if (Array.isArray(item?.files) && item.files.length) return item.files
  if (item?.file?.name) {
    return [{
      id: null,
      name: item.file.name,
      mime_type: item.file.mime_type || null,
      size: item.file.size || null,
      legacy: true,
    }]
  }
  return []
}

function formatFileSize(bytes) {
  if (bytes == null || Number.isNaN(Number(bytes))) return ''
  const size = Number(bytes)
  if (size < 1024) return `${size} B`
  if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} KB`
  return `${(size / (1024 * 1024)).toFixed(1)} MB`
}

function localDateTime(value) {
  if (!value) return 'Belum ditentukan'

  const date = new Date(value)

  if (Number.isNaN(date.getTime())) return '—'

  return (
    new Intl.DateTimeFormat('id-ID', {
      dateStyle: 'medium',
      timeStyle: 'short',
      timeZone: 'Asia/Jakarta',
    }).format(date) + ' WIB'
  )
}

function inputDateTime(value) {
  if (!value) return ''

  const date = new Date(value)

  if (Number.isNaN(date.getTime())) return ''

  const local = new Date(
    date.getTime() - date.getTimezoneOffset() * 60000,
  )

  return local.toISOString().slice(0, 16)
}

function assignmentScheduleLabel(item, schedules) {
  const linked = item?.jadwal_pelajaran

  if (linked) return scheduleDetails(linked)

  const schedule = schedules.find(
    (entry) =>
      String(entry.id) ===
      String(item?.jadwal_pelajaran_id),
  )

  return schedule
    ? scheduleDetails(schedule)
    : `Jadwal #${item?.jadwal_pelajaran_id ?? '—'}`
}

function isOverdue(item) {
  if (!item?.deadline) return false

  const deadline = new Date(item.deadline).getTime()

  return !Number.isNaN(deadline) && deadline < Date.now()
}

export default function GuruAssignmentsPage() {
  const [assignments, setAssignments] = useState([])
  const [schedules, setSchedules] = useState([])
  const [activeAcademicYear, setActiveAcademicYear] =
    useState(null)

  const [search, setSearch] = useState('')
  const [scheduleFilter, setScheduleFilter] = useState('')
  const [expandedSubjects, setExpandedSubjects] = useState(
    [],
  )

  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [downloading, setDownloading] = useState(null)
  const [deleting, setDeleting] = useState(false)

  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')

  const [modalOpen, setModalOpen] = useState(false)
  const [editing, setEditing] = useState(null)
  const [deleteTarget, setDeleteTarget] = useState(null)
  const [submissionTarget, setSubmissionTarget] = useState(null)
  const [form, setForm] = useState({ ...EMPTY_FORM })

  const loadData = useCallback(async () => {
    setLoading(true)
    setError('')

    try {
      const academicYearResponse =
        await academicYearApi.getAll({ is_active: 1 })

      const academicYears = collection(academicYearResponse)

      const activeYear = academicYears.find(
        (year) =>
          year.is_active === true ||
          year.is_active === 1 ||
          year.is_active === '1',
      )

      if (!activeYear) {
        setActiveAcademicYear(null)
        setAssignments([])
        setSchedules([])
        setSearch('')
        setScheduleFilter('')
        setExpandedSubjects([])
        return
      }

      setActiveAcademicYear(activeYear)

      const params = {
        academic_year_id: activeYear.id,
        per_page: 100,
      }

      const [assignmentResponse, scheduleResponse] =
        await Promise.all([
          assignmentApi.getAll(params),
          scheduleApi.getAll(params),
        ])

      setAssignments(collection(assignmentResponse))
      setSchedules(collection(scheduleResponse))
    } catch (err) {
      setError(getMessage(err))
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    loadData()
  }, [loadData])

  const filtered = useMemo(() => {
    const query = search
      .trim()
      .toLocaleLowerCase('id-ID')

    return assignments
      .filter((item) => {
        const scheduleId = String(
          item?.jadwal_pelajaran_id ??
            item?.jadwal_pelajaran?.id ??
            '',
        )

        const matchesSchedule =
          !scheduleFilter || scheduleId === scheduleFilter

        const text = [
          item?.judul,
          item?.deskripsi,
          getSubjectName(item, schedules),
          assignmentScheduleLabel(item, schedules),
          ...getAssignmentAttachments(item).map((attachment) => attachment.name),
        ]
          .filter(Boolean)
          .join(' ')
          .toLocaleLowerCase('id-ID')

        return (
          matchesSchedule &&
          (!query || text.includes(query))
        )
      })
      .sort(
        (a, b) =>
          new Date(a?.deadline || 0) -
          new Date(b?.deadline || 0),
      )
  }, [assignments, schedules, search, scheduleFilter])

  const groupedAssignments = useMemo(() => {
    const groups = new Map()

    filtered.forEach((item) => {
      const scheduleId = String(
        item?.jadwal_pelajaran_id ??
          item?.jadwal_pelajaran?.id ??
          'jadwal-tidak-diketahui',
      )
      const schedule = item?.jadwal_pelajaran ||
        schedules.find((entry) => String(entry.id) === scheduleId)
      const subject = getSubjectName(item, schedules)
      const scheduleLabel = schedule
        ? scheduleDetails(schedule)
        : `Jadwal #${scheduleId}`
      const groupKey = `${scheduleId}::${subject}`

      if (!groups.has(groupKey)) {
        groups.set(groupKey, {
          key: groupKey,
          subject,
          scheduleLabel,
          scheduleId,
          assignments: [],
        })
      }

      groups.get(groupKey).assignments.push(item)
    })

    return Array.from(groups.values()).sort((a, b) =>
      `${a.subject} ${a.scheduleLabel}`.localeCompare(
        `${b.subject} ${b.scheduleLabel}`,
        'id-ID',
      ),
    )
  }, [filtered, schedules])

  const summary = useMemo(() => {
    const now = Date.now()

    return {
      total: assignments.length,
      upcoming: assignments.filter(
        (item) =>
          item?.deadline &&
          new Date(item.deadline).getTime() >= now,
      ).length,
      overdue: assignments.filter(isOverdue).length,
      withAttachment: assignments.filter(
        (item) => getAssignmentAttachments(item).length > 0,
      ).length,
    }
  }, [assignments])

  // Ketika ada pencarian atau filter, buka grup yang
  // mengandung tugas yang cocok agar hasilnya langsung terlihat.
  useEffect(() => {
    if (search.trim() || scheduleFilter) {
      setExpandedSubjects(
        groupedAssignments.map((group) => group.key),
      )
    }
  }, [search, scheduleFilter, groupedAssignments])

  function toggleSubject(subject) {
    setExpandedSubjects((current) =>
      current.includes(subject)
        ? current.filter((item) => item !== subject)
        : [...current, subject],
    )
  }

  function resetFilters() {
    setSearch('')
    setScheduleFilter('')
    setExpandedSubjects([])
  }

  function openCreate() {
    setEditing(null)
    setForm({ ...EMPTY_FORM })
    setError('')
    setNotice('')
    setModalOpen(true)
  }

  function openEdit(item) {
    setEditing(item)

    setForm({
      jadwal_pelajaran_id: String(
        item?.jadwal_pelajaran_id ?? '',
      ),
      komponen_nilai_id:
        item?.komponen_nilai_id == null
          ? ''
          : String(item.komponen_nilai_id),
      judul: item?.judul || '',
      deskripsi: item?.deskripsi || '',
      deadline: inputDateTime(item?.deadline),
      files: [],
      remove_attachment_ids: [],
      remove_file: false,
    })

    setError('')
    setNotice('')
    setModalOpen(true)
  }

  function updateField(event) {
    const { name, value, files, checked, type } = event.target

    if (name === 'files') {
      const selectedFiles = Array.from(files || [])
      if (selectedFiles.length > 10) {
        setError('Maksimal 10 file lampiran dalam satu kali pilih.')
        event.target.value = ''
        return
      }
      const oversized = selectedFiles.find((file) => file.size > MAX_FILE_SIZE)
      if (oversized) {
        setError(`Ukuran file "${oversized.name}" melebihi 10 MB.`)
        event.target.value = ''
        return
      }
      setError('')
      setForm((current) => ({
        ...current,
        files: selectedFiles,
        remove_file: false,
      }))
      return
    }

    if (name === 'remove_attachment_ids') {
      const id = Number(value)
      setForm((current) => ({
        ...current,
        remove_attachment_ids: checked
          ? [...new Set([...current.remove_attachment_ids, id])]
          : current.remove_attachment_ids.filter((currentId) => currentId !== id),
      }))
      return
    }

    setForm((current) => ({
      ...current,
      [name]: type === 'checkbox' ? checked : value,
    }))
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setError('')
    setNotice('')

    if (
      !form.jadwal_pelajaran_id ||
      !form.judul.trim() ||
      !form.deadline
    ) {
      setError(
        'Jadwal, judul tugas, dan deadline wajib diisi.',
      )
      return
    }

    if (form.files.length > 10) {
      setError('Maksimal 10 file lampiran dalam satu kali pilih.')
      return
    }

    if (form.files.some((file) => file.size > MAX_FILE_SIZE)) {
      setError('Setiap file lampiran maksimal 10 MB.')
      return
    }

    setSaving(true)

    try {
      const payload = {
        jadwal_pelajaran_id: Number(
          form.jadwal_pelajaran_id,
        ),
        komponen_nilai_id: form.komponen_nilai_id
          ? Number(form.komponen_nilai_id)
          : null,
        judul: form.judul.trim(),
        deskripsi: form.deskripsi.trim() || null,
        deadline:
          form.deadline.replace('T', ' ') +
          (form.deadline.length === 16 ? ':00' : ''),
      }

      if (editing || form.files.length || form.remove_file || form.remove_attachment_ids.length) {
        const data = new FormData()

        Object.entries(payload).forEach(([key, value]) => {
          if (value !== null) {
            data.append(key, String(value))
          }
        })

        form.files.forEach((file) => data.append('files[]', file))

        form.remove_attachment_ids.forEach((id) =>
          data.append('remove_attachment_ids[]', String(id)),
        )

        if (form.remove_file) {
          data.append('remove_file', '1')
        }

        if (editing) {
          await assignmentApi.updateWithFile(
            editing.id,
            data,
          )
        } else {
          await assignmentApi.createWithFile(data)
        }
      } else {
        await assignmentApi.create(payload)
      }

      setModalOpen(false)
      setNotice(
        editing
          ? 'Tugas berhasil diperbarui.'
          : 'Tugas berhasil ditambahkan.',
      )

      await loadData()
    } catch (err) {
      setError(getMessage(err))
    } finally {
      setSaving(false)
    }
  }

  async function handleDelete() {
    if (!deleteTarget) return

    setDeleting(true)
    setError('')

    try {
      await assignmentApi.delete(deleteTarget.id)

      setDeleteTarget(null)
      setNotice('Tugas berhasil dihapus.')

      await loadData()
    } catch (err) {
      setError(getMessage(err))
    } finally {
      setDeleting(false)
    }
  }

  async function handleDownload(item, attachment = null) {
    const downloadKey = attachment?.id ?? `assignment-${item.id}`
    setDownloading(downloadKey)
    setError('')

    try {
      if (attachment?.id && typeof assignmentApi.downloadAssignmentAttachment === 'function') {
        await assignmentApi.downloadAssignmentAttachment(
          attachment.id,
          attachment.name || 'lampiran-tugas',
        )
      } else {
        await assignmentApi.download(
          item.id,
          attachment?.name || item?.file?.name || 'lampiran-tugas',
        )
      }
    } catch (err) {
      setError(getMessage(err))
    } finally {
      setDownloading(null)
    }
  }

  const academicPeriod = activeAcademicYear
    ? `${activeAcademicYear.tahun_mulai}/${activeAcademicYear.tahun_selesai} — ${
        activeAcademicYear.semester === 'GANJIL'
          ? 'Ganjil'
          : activeAcademicYear.semester === 'GENAP'
            ? 'Genap'
            : activeAcademicYear.semester
      }`
    : 'Belum ada tahun akademik aktif'

  return (
    <main className="assignments-page">
      <header className="assignments-heading">
        <div className="assignments-heading-copy">
          <p className="assignments-eyebrow">
            KEGIATAN AKADEMIK
          </p>

          <h1>Tugas</h1>

          <p className="assignments-subtitle">
            Kelola instruksi tugas, lampiran, dan batas waktu
            pengumpulan siswa dalam satu tempat.
          </p>

          <span className="assignments-period">
            <span className="assignments-period-dot" />
            Periode aktif: {academicPeriod}
          </span>
        </div>

        <div className="assignments-heading-actions">
          <button
            type="button"
            className="assignments-secondary-button"
            onClick={loadData}
            disabled={loading}
          >
            {loading ? 'Memuat…' : '↻ Muat ulang'}
          </button>

          <button
            type="button"
            className="assignments-primary-button"
            onClick={openCreate}
            disabled={!activeAcademicYear || loading}
          >
            + Buat Tugas
          </button>
        </div>
      </header>

      {error && !modalOpen && (
        <div className="assignments-alert error" role="alert">
          {error}
        </div>
      )}

      {notice && (
        <div
          className="assignments-alert success"
          role="status"
        >
          <span>{notice}</span>
          <button
            type="button"
            aria-label="Tutup notifikasi"
            onClick={() => setNotice('')}
          >
            ×
          </button>
        </div>
      )}

      {!loading && !error && !activeAcademicYear && (
        <div className="assignments-alert error" role="alert">
          Belum ada tahun akademik aktif. Hubungi Tata Usaha
          untuk mengaktifkan periode akademik sebelum
          mengelola tugas.
        </div>
      )}

      <section className="assignments-summary">
        <article className="assignments-stat-card total">
          <span className="assignments-stat-icon">▤</span>
          <p>Total Tugas</p>
          <strong>{summary.total}</strong>
          <small>Seluruh tugas pada periode aktif</small>
        </article>

        <article className="assignments-stat-card upcoming">
          <span className="assignments-stat-icon">◷</span>
          <p>Tenggat Mendatang</p>
          <strong>{summary.upcoming}</strong>
          <small>Belum melewati batas waktu</small>
        </article>

        <article className="assignments-stat-card overdue">
          <span className="assignments-stat-icon">!</span>
          <p>Tenggat Terlewati</p>
          <strong>{summary.overdue}</strong>
          <small>Tanggal pengumpulan sudah lewat</small>
        </article>

        <article className="assignments-stat-card attachments">
          <span className="assignments-stat-icon">↗</span>
          <p>Dengan Lampiran</p>
          <strong>{summary.withAttachment}</strong>
          <small>Tugas yang memiliki file lampiran</small>
        </article>
      </section>

      <section className="assignments-panel">
        <header className="assignments-list-heading">
          <div>
            <h2>Daftar Tugas</h2>
            <p>
              {filtered.length} tugas ditampilkan dalam{' '}
              {groupedAssignments.length} kelompok mapel dan tingkat
            </p>
          </div>
        </header>

        <div className="assignments-toolbar">
          <label className="assignments-search">
            <span aria-hidden="true">⌕</span>
            <input
              value={search}
              onChange={(event) =>
                setSearch(event.target.value)
              }
              placeholder="Cari judul, instruksi, atau mapel…"
              aria-label="Cari tugas"
            />
          </label>

          <label className="assignments-filter">
            <span>Jadwal mengajar</span>
            <select
              value={scheduleFilter}
              onChange={(event) =>
                setScheduleFilter(event.target.value)
              }
              aria-label="Filter jadwal mengajar"
            >
              <option value="">Semua jadwal</option>
              {schedules.map((schedule) => (
                <option
                  key={schedule.id}
                  value={String(schedule.id)}
                >
                  {scheduleDetails(schedule)}
                </option>
              ))}
            </select>
          </label>

          <button
            type="button"
            className="assignments-secondary-button"
            onClick={resetFilters}
            disabled={!search && !scheduleFilter}
          >
            Reset filter
          </button>
        </div>

        {loading ? (
          <div className="assignments-state">
            <span className="assignments-spinner" />
            Memuat data tugas…
          </div>
        ) : filtered.length === 0 ? (
          <div className="assignments-empty">
            <div className="assignments-empty-icon">▤</div>

            <h2>
              {assignments.length
                ? 'Tugas tidak ditemukan'
                : 'Belum ada tugas'}
            </h2>

            <p>
              {assignments.length
                ? 'Coba ubah kata pencarian atau reset filter yang dipilih.'
                : 'Buat tugas pertama untuk salah satu jadwal mengajarmu.'}
            </p>

            {assignments.length > 0 && (
              <button
                type="button"
                className="assignments-secondary-button"
                onClick={resetFilters}
              >
                Reset filter
              </button>
            )}

            {!assignments.length && activeAcademicYear && (
              <button
                type="button"
                className="assignments-primary-button"
                onClick={openCreate}
              >
                + Buat Tugas
              </button>
            )}
          </div>
        ) : (
          <div className="assignments-subject-grid">
            {groupedAssignments.map((group) => {
              const isExpanded = expandedSubjects.includes(
                group.key,
              )

              return (
                <section
                  className={`assignments-subject-card ${
                    isExpanded ? 'is-expanded' : ''
                  }`}
                  key={group.key}
                >
                  <button
                    type="button"
                    className="assignments-subject-header"
                    onClick={() =>
                      toggleSubject(group.key)
                    }
                    aria-expanded={isExpanded}
                  >
                    <span className="assignments-subject-avatar">
                      {group.subject.charAt(0).toUpperCase()}
                    </span>

                    <span className="assignments-subject-info">
                      <small>MATA PELAJARAN</small>
                      <strong>{group.subject}</strong>
                      <small className="assignments-subject-schedule">{group.scheduleLabel}</small>
                    </span>

                    <span className="assignments-subject-count">
                      {group.assignments.length}{' '}
                      {group.assignments.length === 1
                        ? 'tugas'
                        : 'tugas'}
                    </span>

                    <span
                      className="assignments-subject-toggle"
                      aria-hidden="true"
                    >
                      {isExpanded ? '−' : '+'}
                    </span>
                  </button>

                  {isExpanded && (
                    <div className="assignments-subject-content">
                      {group.assignments.map((item) => {
                        const overdue = isOverdue(item)

                        return (
                          <article
                            className="assignments-card"
                            key={item.id}
                          >
                            <div className="assignments-card-main">
                              <div className="assignments-card-title-row">
                                <h3>{item.judul}</h3>

                                <span
                                  className={`assignments-badge ${
                                    overdue ? 'past' : 'upcoming'
                                  }`}
                                >
                                  {overdue
                                    ? 'Deadline lewat'
                                    : 'Aktif'}
                                </span>
                              </div>

                              <p className="assignments-schedule">
                                {assignmentScheduleLabel(
                                  item,
                                  schedules,
                                )}
                              </p>

                              {item.deskripsi && (
                                <p className="assignments-description">
                                  {item.deskripsi}
                                </p>
                              )}

                              <div className="assignments-meta">
                                <span>
                                  <strong>Batas waktu</strong>
                                  <span>
                                    {localDateTime(item.deadline)}
                                  </span>
                                </span>

                                {getAssignmentAttachments(item).length > 0 && (
                                  <span className="assignments-file-meta">
                                    <strong>Lampiran ({getAssignmentAttachments(item).length})</strong>
                                    <span className="assignments-file-name">
                                      {getAssignmentAttachments(item).map((attachment) => attachment.name).join(', ')}
                                    </span>
                                  </span>
                                )}
                              </div>
                            </div>

                            {getAssignmentAttachments(item).length > 0 && (
                              <div className="assignments-attachment-list" aria-label="Daftar lampiran tugas">
                                {getAssignmentAttachments(item).map((attachment, index) => {
                                  const downloadKey = attachment.id ?? `assignment-${item.id}`
                                  return (
                                    <div
                                      className="assignments-attachment-item"
                                      key={attachment.id ?? `${item.id}-legacy-${index}`}
                                    >
                                      <span
                                        className="assignments-attachment-name"
                                        title={attachment.name}
                                      >
                                        <span className="assignments-attachment-file-icon" aria-hidden="true">
                                          ▤
                                        </span>
                                        {attachment.name}
                                      </span>

                                      <button
                                        type="button"
                                        className="assignments-secondary-button assignments-attachment-download"
                                        disabled={downloading === downloadKey}
                                        onClick={() => handleDownload(item, attachment)}
                                        title={`Unduh ${attachment.name}`}
                                      >
                                        {downloading === downloadKey ? 'Mengunduh…' : '↓ Unduh'}
                                      </button>
                                    </div>
                                  )
                                })}
                              </div>
                            )}

                            <div className="assignments-card-actions">
                              <button
                                type="button"
                                className="assignments-secondary-button"
                                onClick={() => setSubmissionTarget(item)}
                              >
                                Lihat Pengumpulan
                              </button>

                              <button
                                type="button"
                                className="assignments-secondary-button"
                                onClick={() => openEdit(item)}
                              >
                                Edit
                              </button>

                              <button
                                type="button"
                                className="assignments-danger-button"
                                onClick={() => {
                                  setError('')
                                  setDeleteTarget(item)
                                }}
                              >
                                Hapus
                              </button>
                            </div>
                          </article>
                        )
                      })}
                    </div>
                  )}
                </section>
              )
            })}
          </div>
        )}
      </section>

      {modalOpen && (
        <div
          className="assignments-modal-backdrop"
          role="presentation"
          onMouseDown={(event) => {
            if (
              event.target === event.currentTarget &&
              !saving
            ) {
              setModalOpen(false)
            }
          }}
        >
          <section
            className="assignments-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="assignment-form-title"
          >
            <header className="assignments-modal-heading">
              <div>
                <p className="assignments-eyebrow">
                  PENGELOLAAN TUGAS
                </p>

                <h2 id="assignment-form-title">
                  {editing ? 'Edit Tugas' : 'Buat Tugas Baru'}
                </h2>

                <p>
                  Lengkapi informasi tugas dan batas waktu
                  pengumpulan.
                </p>
              </div>

              <button
                type="button"
                className="assignments-close-button"
                onClick={() => setModalOpen(false)}
                disabled={saving}
                aria-label="Tutup"
              >
                ×
              </button>
            </header>

            {error && (
              <div className="assignments-alert error" role="alert">
                {error}
              </div>
            )}

            <form
              onSubmit={handleSubmit}
              className="assignments-form"
            >
              <label className="assignments-form-field">
                <span>
                  Jadwal mengajar <b>*</b>
                </span>

                <select
                  name="jadwal_pelajaran_id"
                  value={form.jadwal_pelajaran_id}
                  onChange={updateField}
                  required
                >
                  <option value="">
                    Pilih jadwal mengajar
                  </option>

                  {schedules.map((schedule) => (
                    <option
                      key={schedule.id}
                      value={schedule.id}
                    >
                      {scheduleDetails(schedule)}
                    </option>
                  ))}
                </select>

                {!schedules.length && (
                  <small>
                    Jadwal belum tersedia. Pastikan jadwal
                    mengajar sudah dibuat.
                  </small>
                )}
              </label>

              <label className="assignments-form-field">
                <span>
                  Komponen nilai <small>(opsional)</small>
                </span>

                <input
                  name="komponen_nilai_id"
                  type="number"
                  min="1"
                  value={form.komponen_nilai_id}
                  onChange={updateField}
                  placeholder="ID komponen nilai jika ada"
                />

                <small>
                  Komponen nilai harus berasal dari jadwal
                  yang sama.
                </small>
              </label>

              <label className="assignments-form-field">
                <span>
                  Judul tugas <b>*</b>
                </span>

                <input
                  name="judul"
                  value={form.judul}
                  onChange={updateField}
                  maxLength={255}
                  required
                  placeholder="Contoh: Latihan Bab 1"
                />
              </label>

              <label className="assignments-form-field">
                <span>Deskripsi / instruksi</span>

                <textarea
                  name="deskripsi"
                  value={form.deskripsi}
                  onChange={updateField}
                  rows={4}
                  placeholder="Tuliskan instruksi pengerjaan tugas…"
                />
              </label>

              <label className="assignments-form-field">
                <span>
                  Batas waktu pengumpulan <b>*</b>
                </span>

                <input
                  name="deadline"
                  type="datetime-local"
                  value={form.deadline}
                  onChange={updateField}
                  required
                />
              </label>

              <label className="assignments-form-field">
                <span>
                  Lampiran tugas <small>(opsional, maks. 10 file; 10 MB per file)</small>
                </span>

                <input
                  name="files"
                  type="file"
                  multiple
                  accept={ACCEPTED_FILE_TYPES}
                  onChange={updateField}
                />

                {form.files.length > 0 && (
                  <small>
                    File baru dipilih ({form.files.length}):{' '}
                    {form.files.map((file) => file.name).join(', ')}
                  </small>
                )}

                {editing && getAssignmentAttachments(editing).length > 0 && (
                  <div className="assignments-existing-attachments">
                    <strong>Lampiran saat ini</strong>
                    {getAssignmentAttachments(editing).map((attachment, index) => {
                      const markedForRemoval = attachment.id
                        ? form.remove_attachment_ids.includes(Number(attachment.id))
                        : form.remove_file
                      return (
                        <label
                          className="assignments-remove-file"
                          key={attachment.id ?? `legacy-${index}`}
                        >
                          <input
                            type="checkbox"
                            name={attachment.id ? 'remove_attachment_ids' : 'remove_file'}
                            value={attachment.id ?? 'legacy'}
                            checked={markedForRemoval}
                            onChange={updateField}
                          />
                          <span>
                            {attachment.name}
                            {attachment.size ? ` (${formatFileSize(attachment.size)})` : ''}
                            {markedForRemoval ? ' — akan dihapus' : ''}
                          </span>
                        </label>
                      )
                    })}
                    <small>Centang hanya lampiran yang ingin dihapus. Lampiran lainnya tetap tersimpan.</small>
                  </div>
                )}
              </label>

              <div className="assignments-modal-footer">
                <button
                  type="button"
                  className="assignments-secondary-button"
                  onClick={() => setModalOpen(false)}
                  disabled={saving}
                >
                  Batal
                </button>

                <button
                  type="submit"
                  className="assignments-primary-button"
                  disabled={saving || !schedules.length}
                >
                  {saving
                    ? 'Menyimpan…'
                    : editing
                      ? 'Simpan Perubahan'
                      : 'Simpan Tugas'}
                </button>
              </div>
            </form>
          </section>
        </div>
      )}

      {deleteTarget && (
        <div className="assignments-modal-backdrop">
          <section
            className="assignments-confirm-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="assignment-delete-title"
          >
            <h2 id="assignment-delete-title">
              Hapus tugas?
            </h2>

            <p>
              Tugas <strong>{deleteTarget.judul}</strong> akan
              dihapus. Jika sudah memiliki submission siswa,
              server mungkin menolak penghapusan sesuai aturan
              backend.
            </p>

            {error && (
              <div className="assignments-alert error" role="alert">
                {error}
              </div>
            )}

            <div className="assignments-modal-footer">
              <button
                type="button"
                className="assignments-secondary-button"
                onClick={() => {
                  setDeleteTarget(null)
                  setError('')
                }}
                disabled={deleting}
              >
                Batal
              </button>

              <button
                type="button"
                className="assignments-danger-button"
                onClick={handleDelete}
                disabled={deleting}
              >
                {deleting ? 'Menghapus…' : 'Ya, hapus tugas'}
              </button>
            </div>
          </section>
        </div>
      )}

      {submissionTarget && (
        <GuruAssignmentSubmissions
          assignment={submissionTarget}
          onClose={() => setSubmissionTarget(null)}
        />
      )}
    </main>
  )
}
