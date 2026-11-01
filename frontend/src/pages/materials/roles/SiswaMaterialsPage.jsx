import { useCallback, useEffect, useMemo, useState } from 'react'
import { materialApi } from '../../../services/materialApi'
import { academicYearApi } from '../../../services/academicYearApi'
import './SiswaMaterialsPage.css'

function collection(response) {
  if (Array.isArray(response?.data?.data)) return response.data.data
  if (Array.isArray(response?.data)) return response.data
  if (Array.isArray(response)) return response
  return []
}

function message(error) {
  return (
    error?.payload?.message ||
    error?.response?.data?.message ||
    error?.message ||
    'Terjadi kesalahan saat memuat bahan ajar.'
  )
}

function formatUploadDate(value) {
  if (!value) return 'Tanggal tidak tersedia'

  const date = new Date(value)

  if (Number.isNaN(date.getTime())) {
    return 'Tanggal tidak tersedia'
  }

  const formatted = new Intl.DateTimeFormat('id-ID', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    timeZone: 'Asia/Jakarta',
  }).format(date)

  return `${formatted} WIB`
}

function scheduleDetails(item) {
  const schedule = item?.jadwal_pelajaran || {}
  const className =
    schedule?.kelas?.nama_kelas ||
    schedule?.kelas?.nama ||
    'Kelas tidak tersedia'
  const level = schedule?.kelas?.tingkat
  const subject =
    schedule?.mapel?.nama_mapel ||
    schedule?.mapel?.nama ||
    'Mata pelajaran tidak tersedia'
  const time = [schedule?.jam_mulai, schedule?.jam_selesai]
    .filter(Boolean)
    .join('–')

  return {
    className,
    level: level === null || level === undefined || level === '' ? '' : String(level),
    subject,
    day: schedule?.hari || '',
    time,
  }
}

function scheduleLabel(item) {
  const details = scheduleDetails(item)

  // Pada halaman siswa, tampilkan konteks pembelajaran tanpa tingkat/kelas.
  return [details.subject, details.day, details.time]
    .filter(Boolean)
    .join(' • ')
}

function materialAttachments(item) {
  if (Array.isArray(item?.lampiran)) {
    return item.lampiran
  }

  if (item?.has_file || item?.file_name) {
    return [
      {
        id: null,
        file_name: item.file_name || 'Unduh materi',
        file_size: item.file_size ?? null,
        created_at: item.created_at ?? null,
      },
    ]
  }

  return []
}

function formatFileSize(bytes) {
  if (bytes === null || bytes === undefined || bytes === '') {
    return ''
  }

  const size = Number(bytes)

  if (!Number.isFinite(size) || size < 0) {
    return ''
  }

  if (size < 1024 * 1024) {
    return `${Math.max(1, Math.round(size / 1024))} KB`
  }

  return `${(size / (1024 * 1024)).toFixed(2)} MB`
}

function normalized(value) {
  return String(value ?? '').trim().toLocaleLowerCase('id-ID')
}

function compareText(a, b) {
  return String(a ?? '').localeCompare(String(b ?? ''), 'id-ID', {
    numeric: true,
    sensitivity: 'base',
  })
}

export default function SiswaMaterialsPage() {
  const [materials, setMaterials] = useState([])
  const [academicYears, setAcademicYears] = useState([])
  const [selectedYearId, setSelectedYearId] = useState('')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [downloading, setDownloading] = useState(null)
  const [searchTerm, setSearchTerm] = useState('')
  const [subjectFilter, setSubjectFilter] = useState('')
  const [levelFilter, setLevelFilter] = useState('')
  const [classFilter, setClassFilter] = useState('')
  const [dateSort, setDateSort] = useState('newest')

  // Muat daftar tahun akademik dan pilih tahun aktif sebagai pilihan awal.
  useEffect(() => {
    let cancelled = false

    async function loadAcademicYears() {
      try {
        const response = await academicYearApi.getAll()
        const years = collection(response)
        const activeYear = years.find(
          (year) =>
            year.is_active === true ||
            year.is_active === 1 ||
            year.is_active === '1',
        )

        if (cancelled) return

        setAcademicYears(years)
        setSelectedYearId((current) => {
          if (current && years.some(
            (year) => String(year.id) === String(current),
          )) {
            return current
          }

          return activeYear ? String(activeYear.id) : ''
        })
      } catch (err) {
        if (!cancelled) {
          setError(message(err))
        }
      }
    }

    loadAcademicYears()

    return () => {
      cancelled = true
    }
  }, [])

  const load = useCallback(async () => {
    if (!selectedYearId) {
      setMaterials([])
      setLoading(false)
      return
    }

    setLoading(true)
    setError('')

    try {
      const response = await materialApi.getAll({
        per_page: 100,
        academic_year_id: selectedYearId,
      })
      setMaterials(collection(response))
    } catch (err) {
      setError(message(err))
      setMaterials([])
    } finally {
      setLoading(false)
    }
  }, [selectedYearId])

  useEffect(() => {
    load()
  }, [load])

  const filterOptions = useMemo(() => {
    const details = materials.map((item) => ({
      item,
      schedule: scheduleDetails(item),
    }))

    const subjects = [...new Set(details.map(({ schedule }) => schedule.subject)
      .filter((value) => value && value !== 'Mata pelajaran tidak tersedia'))]
      .sort(compareText)

    const subjectItems = details.filter(({ schedule }) =>
      !subjectFilter || schedule.subject === subjectFilter,
    )

    const levels = [...new Set(subjectItems.map(({ schedule }) => schedule.level)
      .filter(Boolean))]
      .sort(compareText)

    const levelItems = subjectItems.filter(({ schedule }) =>
      !levelFilter || schedule.level === levelFilter,
    )

    const classes = [...new Set(levelItems.map(({ schedule }) => schedule.className)
      .filter((value) => value && value !== 'Kelas tidak tersedia'))]
      .sort(compareText)

    return { subjects, levels, classes }
  }, [materials, subjectFilter, levelFilter])

  useEffect(() => {
    if (levelFilter && !filterOptions.levels.includes(levelFilter)) {
      setLevelFilter('')
      setClassFilter('')
      return
    }

    if (classFilter && !filterOptions.classes.includes(classFilter)) {
      setClassFilter('')
    }
  }, [filterOptions, levelFilter, classFilter])

  const visibleMaterials = useMemo(() => {
    const query = normalized(searchTerm)

    return materials
      .filter((item) => {
        const details = scheduleDetails(item)
        const attachmentNames = materialAttachments(item)
          .map((attachment) => attachment.file_name)
          .join(' ')

        const searchable = normalized([
          item.judul,
          item.deskripsi,
          attachmentNames,
          details.subject,
          details.className,
          details.level,
        ].join(' '))

        if (query && !searchable.includes(query)) return false
        if (subjectFilter && details.subject !== subjectFilter) return false
        if (levelFilter && details.level !== levelFilter) return false
        if (classFilter && details.className !== classFilter) return false

        return true
      })
      .sort((a, b) => {
        const dateA = new Date(a.created_at || 0).getTime()
        const dateB = new Date(b.created_at || 0).getTime()
        const safeA = Number.isFinite(dateA) ? dateA : 0
        const safeB = Number.isFinite(dateB) ? dateB : 0

        return dateSort === 'oldest' ? safeA - safeB : safeB - safeA
      })
  }, [materials, searchTerm, subjectFilter, levelFilter, classFilter, dateSort])

  function resetFilters() {
    setSearchTerm('')
    setSubjectFilter('')
    setLevelFilter('')
    setClassFilter('')
    setDateSort('newest')
  }

  async function downloadAttachment(material, attachment, index) {
    const key = `${material.id}-${attachment.id ?? index}`

    setError('')
    setNotice('')
    setDownloading(key)

    try {
      if (attachment.id !== null && attachment.id !== undefined) {
        await materialApi.downloadAttachment(
          material.id,
          attachment.id,
          attachment.file_name || material.judul,
        )
      } else {
        await materialApi.download(
          material.id,
          attachment.file_name || material.judul,
        )
      }

      setNotice(
        `Permintaan unduhan "${attachment.file_name || material.judul}" berhasil diproses.`,
      )
    } catch (err) {
      setError(message(err))
    } finally {
      setDownloading(null)
    }
  }

  return (
    <main className="student-materials-page">
      <header className="student-materials-heading">
        <div>
          <p className="student-materials-eyebrow">PEMBELAJARAN</p>
          <h1>Bahan Ajar</h1>
          <p>
            Materi, tanggal unggah, dan lampiran yang tersedia untuk kelas Anda.
          </p>
        </div>

        <button
          type="button"
          className="student-materials-refresh"
          onClick={load}
          disabled={loading}
        >
          {loading ? 'Memuat…' : 'Muat ulang'}
        </button>
      </header>

      {error && (
        <div className="student-materials-alert error" role="alert">
          {error}
        </div>
      )}

      {notice && (
        <div className="student-materials-alert success" role="status">
          {notice}
        </div>
      )}

      <section className="student-materials-panel">
        <div className="student-materials-panel-title">
          <div>
            <h2>Materi pembelajaran</h2>
            <span>
              Menampilkan {visibleMaterials.length} dari {materials.length} materi
            </span>
          </div>

          <label className="student-materials-filter-field">
            <span>Tahun akademik</span>
            <select
              value={selectedYearId}
              onChange={(event) => setSelectedYearId(event.target.value)}
              disabled={loading}
            >
              {!selectedYearId && (
                <option value="">Tidak ada tahun akademik aktif</option>
              )}

              {academicYears.map((year) => (
                <option key={year.id} value={String(year.id)}>
                  {year.tahun_mulai}/{year.tahun_selesai}
                  {' — '}
                  {year.semester === 'GANJIL'
                    ? 'Ganjil'
                    : year.semester === 'GENAP'
                      ? 'Genap'
                      : year.semester}
                  {year.is_active === true ||
                  year.is_active === 1 ||
                  year.is_active === '1'
                    ? ' (Aktif)'
                    : ''}
                </option>
              ))}
            </select>
          </label>
        </div>

        <div className="student-materials-filters">
          <label className="student-materials-filter-field student-materials-search">
            <span>Cari judul atau nama file</span>
            <input
              type="search"
              value={searchTerm}
              onChange={(event) => setSearchTerm(event.target.value)}
              placeholder="Ketik judul atau nama lampiran…"
              aria-label="Cari judul atau nama file"
            />
          </label>

          <label className="student-materials-filter-field">
            <span>Mata pelajaran</span>
            <select
              value={subjectFilter}
              onChange={(event) => {
                setSubjectFilter(event.target.value)
                setLevelFilter('')
                setClassFilter('')
              }}
            >
              <option value="">Semua mata pelajaran</option>
              {filterOptions.subjects.map((subject) => (
                <option key={subject} value={subject}>{subject}</option>
              ))}
            </select>
          </label>

          <label className="student-materials-filter-field">
            <span>Tingkat</span>
            <select
              value={levelFilter}
              onChange={(event) => {
                setLevelFilter(event.target.value)
                setClassFilter('')
              }}
              disabled={!subjectFilter || filterOptions.levels.length === 0}
            >
              <option value="">Semua tingkat</option>
              {filterOptions.levels.map((level) => (
                <option key={level} value={level}>Tingkat {level}</option>
              ))}
            </select>
          </label>

          <label className="student-materials-filter-field">
            <span>Kelas</span>
            <select
              value={classFilter}
              onChange={(event) => setClassFilter(event.target.value)}
              disabled={!subjectFilter || !levelFilter || filterOptions.classes.length === 0}
            >
              <option value="">Semua kelas</option>
              {filterOptions.classes.map((className) => (
                <option key={className} value={className}>{className}</option>
              ))}
            </select>
          </label>

          <label className="student-materials-filter-field">
            <span>Urutkan tanggal</span>
            <select value={dateSort} onChange={(event) => setDateSort(event.target.value)}>
              <option value="newest">Terbaru</option>
              <option value="oldest">Terlama</option>
            </select>
          </label>

          <button
            type="button"
            className="student-materials-reset"
            onClick={resetFilters}
          >
            Reset filter
          </button>
        </div>

        {loading ? (
          <p className="student-materials-state">Memuat bahan ajar…</p>
        ) : visibleMaterials.length === 0 ? (
          <p className="student-materials-state">
            {materials.length === 0
              ? 'Belum ada bahan ajar yang tersedia.'
              : 'Tidak ada bahan ajar yang cocok dengan filter pencarian.'}
          </p>
        ) : (
          <div className="student-materials-list">
            {visibleMaterials.map((item) => {
              const attachments = materialAttachments(item)

              return (
                <article className="student-material-card" key={item.id}>
                  <div className="student-material-symbol">
                    <span>DOC</span>
                  </div>

                  <div className="student-material-info">
                    <h3>{item.judul || 'Bahan ajar tanpa judul'}</h3>

                    <p className="student-material-upload-date">
                      <span>Diunggah:</span>{' '}
                      {formatUploadDate(item.created_at)}
                    </p>

                    <p className="student-material-meta">
                      {scheduleLabel(item)}
                    </p>

                    {item.deskripsi && (
                      <p className="student-material-description">
                        {item.deskripsi}
                      </p>
                    )}

                    {attachments.length > 0 ? (
                      <div className="student-material-attachments">
                        <strong>Lampiran ({attachments.length})</strong>

                        <ul>
                          {attachments.map((attachment, index) => {
                            const key = `${item.id}-${attachment.id ?? index}`

                            return (
                              <li
                                key={attachment.id ?? `${attachment.file_name}-${index}`}
                              >
                                <div className="student-material-file-info">
                                  <span className="student-material-filename">
                                    {attachment.file_name || 'Lampiran tanpa nama'}
                                  </span>

                                  <small className="student-material-attachment-date">
                                    Diunggah: {formatUploadDate(attachment.created_at)}
                                  </small>

                                  {attachment.file_size !== null &&
                                    attachment.file_size !== undefined && (
                                      <small className="student-material-file-size">
                                        {formatFileSize(attachment.file_size)}
                                      </small>
                                    )}
                                </div>

                                <button
                                  type="button"
                                  onClick={() => downloadAttachment(item, attachment, index)}
                                  disabled={downloading !== null}
                                >
                                  {downloading === key ? 'Mengunduh…' : 'Unduh'}
                                </button>
                              </li>
                            )
                          })}
                        </ul>
                      </div>
                    ) : (
                      <small>Tidak ada file lampiran.</small>
                    )}
                  </div>
                </article>
              )
            })}
          </div>
        )}
      </section>
    </main>
  )
}
