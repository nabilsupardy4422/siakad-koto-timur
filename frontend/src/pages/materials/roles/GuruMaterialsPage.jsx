import { useCallback, useEffect, useMemo, useState } from 'react'
import { materialApi } from '../../../services/materialApi'
import { scheduleApi } from '../../../services/scheduleApi'
import { academicYearApi } from '../../../services/academicYearApi'
import './GuruMaterialsPage.css'

const MAX_FILE_SIZE = 10 * 1024 * 1024
const MAX_FILE_LABEL = '10 MB'

const EMPTY_FORM = {
  jadwal_pelajaran_id: '',
  judul: '',
  deskripsi: '',
  files: [],
}

function collection(response) {
  if (Array.isArray(response?.data?.data)) return response.data.data
  if (Array.isArray(response?.data)) return response.data
  if (Array.isArray(response)) return response
  return []
}

function message(error) {
  const errors = error?.payload?.errors || error?.response?.data?.errors

  if (errors && typeof errors === 'object') {
    const firstError = Object.values(errors).flat()[0]
    if (firstError) return firstError
  }

  return (
    error?.payload?.message ||
    error?.response?.data?.message ||
    error?.message ||
    'Terjadi kesalahan. Silakan coba kembali.'
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

function scheduleDetails(schedule) {
  const kelas = schedule?.kelas?.nama || schedule?.kelas?.nama_kelas || 'Kelas'
  const tingkat = schedule?.kelas?.tingkat
  const mapel = schedule?.mapel?.nama || schedule?.mapel?.nama_mapel || 'Mata pelajaran'
  const waktu = [schedule?.jam_mulai, schedule?.jam_selesai]
    .filter(Boolean)
    .map((value) => String(value).slice(0, 5))
    .join('–')
  const tingkatText = tingkat !== null && tingkat !== undefined ? String(tingkat) : ''
  return {
    kelas,
    tingkat: tingkatText,
    mapel,
    hari: schedule?.hari || '',
    waktu,
    label: [mapel, tingkatText ? `Tingkat ${tingkatText}` : '', kelas, schedule?.hari, waktu]
      .filter(Boolean)
      .join(' • '),
  }
}

function scheduleLabel(schedule) {
  return scheduleDetails(schedule).label
}

function materialSearchText(material) {
  const schedule = scheduleDetails(material?.jadwal_pelajaran || {})
  const filenames = materialAttachments(material).map((file) => file?.file_name || '').join(' ')
  return [material?.judul, material?.deskripsi, filenames, schedule.mapel, schedule.kelas, schedule.tingkat, schedule.hari]
    .filter(Boolean)
    .join(' ')
    .toLocaleLowerCase('id-ID')
}

function materialScheduleId(material) {
  return String(
    material?.jadwal_pelajaran_id ??
      material?.jadwal_pelajaran?.id ??
      '',
  )
}

function materialAttachments(material) {
  if (Array.isArray(material?.lampiran)) {
    return material.lampiran
  }

  if (material?.has_file || material?.file_path || material?.file_name) {
    return [
      {
        id: null,
        file_name: material.file_name || 'Lampiran materi',
        file_size: material.file_size ?? null,
        created_at: material.created_at ?? null,
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

export default function GuruMaterialsPage() {
  const [materials, setMaterials] = useState([])
  const [schedules, setSchedules] = useState([])
  const [academicYears, setAcademicYears] = useState([])
  const [selectedYearId, setSelectedYearId] = useState('')
  const [form, setForm] = useState(EMPTY_FORM)
  const [searchTerm, setSearchTerm] = useState('')
  const [subjectFilter, setSubjectFilter] = useState('')
  const [levelFilter, setLevelFilter] = useState('')
  const [classFilter, setClassFilter] = useState('')
  const [dateSort, setDateSort] = useState('newest')

  const [editing, setEditing] = useState(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [deleting, setDeleting] = useState(false)
  const [attachmentDeleting, setAttachmentDeleting] = useState(null)
  const [downloading, setDownloading] = useState(null)

  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [modalOpen, setModalOpen] = useState(false)
  const [deleteTarget, setDeleteTarget] = useState(null)

  const selectedMaterial = useMemo(
    () => materials.find((item) => String(item.id) === String(editing)),
    [materials, editing],
  )

  // Opsi filter dibangun dari jadwal yang dikembalikan API untuk pengguna login.
  // Jadwal bahan ajar lama tidak dipakai untuk menambah opsi filter di luar jadwal tersebut.
  const teacherSchedules = schedules

  const filterOptions = useMemo(() => {
    const unique = (values) =>
      [...new Set(values.filter((value) => value !== '' && value !== null && value !== undefined))]

    const subjectSchedules = subjectFilter
      ? teacherSchedules.filter((schedule) => {
          const details = scheduleDetails(schedule)
          return details.mapel === subjectFilter
        })
      : teacherSchedules

    const levelSchedules = levelFilter
      ? subjectSchedules.filter((schedule) => {
          const details = scheduleDetails(schedule)
          return details.tingkat === levelFilter
        })
      : subjectSchedules

    return {
      subjects: unique(
        teacherSchedules.map((schedule) => schedule?.mapel?.nama || schedule?.mapel?.nama_mapel),
      ).sort((a, b) => a.localeCompare(b, 'id')),
      levels: unique(
        subjectSchedules
          .map((schedule) => schedule?.kelas?.tingkat)
          .map((value) => value == null ? '' : String(value)),
      ).sort((a, b) => Number(a) - Number(b)),
      classes: unique(
        levelSchedules.map((schedule) => schedule?.kelas?.nama || schedule?.kelas?.nama_kelas),
      ).sort((a, b) => a.localeCompare(b, 'id')),
    }
  }, [teacherSchedules, subjectFilter, levelFilter])

  // Bersihkan filter turunan jika pilihan induk membuatnya tidak valid.
  useEffect(() => {
    if (levelFilter && !filterOptions.levels.includes(levelFilter)) {
      setLevelFilter('')
      setClassFilter('')
      return
    }

    if (classFilter && !filterOptions.classes.includes(classFilter)) {
      setClassFilter('')
    }
  }, [filterOptions.levels, filterOptions.classes, levelFilter, classFilter])


  const filteredMaterials = useMemo(() => {
    const query = searchTerm.trim().toLocaleLowerCase('id-ID')
    return materials.filter((material) => {
      const details = scheduleDetails(material?.jadwal_pelajaran || {})
      if (query && !materialSearchText(material).includes(query)) return false
      if (subjectFilter && details.mapel !== subjectFilter) return false
      if (levelFilter && details.tingkat !== levelFilter) return false
      if (classFilter && details.kelas !== classFilter) return false
      return true
    }).sort((a, b) => {
      const aTime = new Date(a?.created_at || 0).getTime()
      const bTime = new Date(b?.created_at || 0).getTime()
      return dateSort === 'oldest' ? aTime - bTime : bTime - aTime
    })
  }, [materials, searchTerm, subjectFilter, levelFilter, classFilter, dateSort])

  const hasActiveFilters = Boolean(searchTerm || subjectFilter || levelFilter || classFilter || dateSort !== 'newest')

  function resetFilters() {
    setSearchTerm('')
    setSubjectFilter('')
    setLevelFilter('')
    setClassFilter('')
    setDateSort('newest')
  }

  // Muat tahun akademik dan pilih tahun aktif sebagai pilihan awal.
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
      setSchedules([])
      setLoading(false)
      return
    }

    setLoading(true)
    setError('')

    try {
      const params = {
        per_page: 100,
        academic_year_id: selectedYearId,
      }

      const [materialResponse, scheduleResponse] = await Promise.all([
        materialApi.getAll(params),
        scheduleApi.getAll(params),
      ])

      setMaterials(collection(materialResponse))
      setSchedules(collection(scheduleResponse))
    } catch (err) {
      setError(message(err))
      setMaterials([])
      setSchedules([])
    } finally {
      setLoading(false)
    }
  }, [selectedYearId])

  useEffect(() => {
    load()
  }, [load])

  const closeModal = useCallback(() => {
    if (saving || attachmentDeleting !== null) return

    setModalOpen(false)
    setEditing(null)
    setForm({ ...EMPTY_FORM, files: [] })
  }, [saving, attachmentDeleting])

  useEffect(() => {
    if (!modalOpen) return undefined

    function handleKeyDown(event) {
      if (event.key === 'Escape') {
        closeModal()
      }
    }

    window.addEventListener('keydown', handleKeyDown)

    return () => window.removeEventListener('keydown', handleKeyDown)
  }, [modalOpen, closeModal])

  function openCreate() {
    setEditing(null)
    setForm({ ...EMPTY_FORM, files: [] })
    setError('')
    setNotice('')
    setModalOpen(true)
  }

  function openEdit(material) {
    setEditing(material.id)

    setForm({
      jadwal_pelajaran_id: materialScheduleId(material),
      judul: material?.judul || '',
      deskripsi: material?.deskripsi || '',
      files: [],
    })

    setError('')
    setNotice('')
    setModalOpen(true)
  }

  function handleFieldChange(event) {
    const { name, value } = event.target

    setForm((previous) => ({
      ...previous,
      [name]: value,
    }))
  }

  function handleFilesChange(event) {
    const input = event.currentTarget
    const selectedFiles = Array.from(input.files || [])

    if (selectedFiles.length === 0) return

    const oversizedFiles = selectedFiles.filter(
      (file) => file.size > MAX_FILE_SIZE,
    )

    if (oversizedFiles.length > 0) {
      setError(
        `Setiap file maksimal ${MAX_FILE_LABEL}. File terlalu besar: ${oversizedFiles
          .map((file) => file.name)
          .join(', ')}`,
      )

      input.value = ''
      return
    }

    setError('')

    setForm((previous) => ({
      ...previous,
      files: [...previous.files, ...selectedFiles],
    }))

    // File yang dipilih berikutnya akan ditambahkan ke daftar.
    input.value = ''
  }

  function removeSelectedFile(indexToRemove) {
    setForm((previous) => ({
      ...previous,
      files: previous.files.filter(
        (_, index) => index !== indexToRemove,
      ),
    }))
  }

  async function handleSubmit(event) {
    event.preventDefault()

    setError('')
    setNotice('')

    if (!form.jadwal_pelajaran_id) {
      setError('Pilih jadwal mengajar terlebih dahulu.')
      return
    }

    if (!form.judul.trim()) {
      setError('Judul bahan ajar wajib diisi.')
      return
    }

    const oversizedFile = form.files.find(
      (file) => file.size > MAX_FILE_SIZE,
    )

    if (oversizedFile) {
      setError(
        `Ukuran file "${oversizedFile.name}" melebihi ${MAX_FILE_LABEL}.`,
      )
      return
    }

    const formData = new FormData()
    formData.append('jadwal_pelajaran_id', form.jadwal_pelajaran_id)
    formData.append('judul', form.judul.trim())
    formData.append('deskripsi', form.deskripsi.trim())

    // Kirim semua file baru sekaligus menggunakan files[].
    form.files.forEach((file) => {
      formData.append('files[]', file)
    })

    setSaving(true)

    try {
      if (editing !== null) {
        await materialApi.update(editing, formData)
      } else {
        await materialApi.create(formData)
      }

      setModalOpen(false)
      setEditing(null)
      setForm({ ...EMPTY_FORM, files: [] })

      await load()

      setNotice(
        editing !== null
          ? 'Bahan ajar berhasil diperbarui dan lampiran baru ditambahkan.'
          : 'Bahan ajar berhasil ditambahkan.',
      )
    } catch (err) {
      setError(message(err))
    } finally {
      setSaving(false)
    }
  }

  async function downloadOneAttachment(material, attachment, index) {
    const key = `${material.id}-${attachment.id ?? index}`

    setError('')
    setNotice('')
    setDownloading(key)

    try {
      if (attachment.id !== null && attachment.id !== undefined) {
        await materialApi.downloadAttachment(
          material.id,
          attachment.id,
          attachment.file_name,
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

  async function removeOneAttachment(material, attachment) {
    if (attachment.id === null || attachment.id === undefined) {
      setError(
        'Lampiran lama ini belum memiliki ID lampiran sehingga tidak dapat dihapus satu per satu dari halaman ini.',
      )
      return
    }

    const confirmed = window.confirm(
      `Hapus lampiran "${attachment.file_name}"? Lampiran lainnya tidak akan dihapus.`,
    )

    if (!confirmed) return

    setError('')
    setNotice('')
    setAttachmentDeleting(attachment.id)

    try {
      await materialApi.deleteAttachment(material.id, attachment.id)
      await load()

      setNotice(`Lampiran "${attachment.file_name}" berhasil dihapus.`)
    } catch (err) {
      setError(message(err))
    } finally {
      setAttachmentDeleting(null)
    }
  }

  function confirmRemove(material) {
    setError('')
    setNotice('')
    setDeleteTarget(material)
  }

  async function handleDeleteMaterial() {
    if (!deleteTarget) return

    setDeleting(true)
    setError('')
    setNotice('')

    try {
      const deletedTitle = deleteTarget.judul

      await materialApi.delete(deleteTarget.id)
      setDeleteTarget(null)

      await load()

      setNotice(
        `Bahan ajar "${deletedTitle}" beserta lampirannya berhasil dihapus.`,
      )
    } catch (err) {
      setError(message(err))
    } finally {
      setDeleting(false)
    }
  }

  return (
    <main className="materials-page">
      <header className="materials-heading">
        <div>
          <p className="materials-eyebrow">PEMBELAJARAN</p>
          <h1>Bahan Ajar</h1>
          <p>
            Kelola materi, tanggal unggah, dan lampiran berdasarkan jadwal mengajar.
          </p>
        </div>

        <div className="materials-heading-actions">
          <button
            type="button"
            className="materials-primary-button"
            onClick={openCreate}
          >
            + Tambah bahan ajar
          </button>

          <button
            type="button"
            className="materials-secondary-button"
            onClick={load}
            disabled={loading}
          >
            {loading ? 'Memuat…' : 'Muat ulang'}
          </button>
        </div>
      </header>

      {error && (
        <div className="materials-alert error" role="alert">
          {error}
        </div>
      )}

      {notice && (
        <div className="materials-alert success" role="status">
          {notice}
        </div>
      )}

      <section className="materials-panel">
        <div className="materials-panel-heading">
          <div>
            <h2>Daftar bahan ajar</h2>
            <p>Menampilkan {filteredMaterials.length} dari {materials.length} materi</p>
          </div>

          <label className="materials-filter-field">
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

        <div className="materials-filters" aria-label="Pencarian dan filter bahan ajar">
          <label className="materials-search-field">
            <span>Cari judul atau nama file</span>
            <input type="search" value={searchTerm} onChange={(event) => setSearchTerm(event.target.value)} placeholder="Ketik judul atau nama lampiran…" />
          </label>
          <label className="materials-filter-field">
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
              {filterOptions.subjects.map((item) => <option key={item} value={item}>{item}</option>)}
            </select>
          </label>
          <label className="materials-filter-field">
            <span>Tingkat</span>
            <select
              value={levelFilter}
              onChange={(event) => {
                setLevelFilter(event.target.value)
                setClassFilter('')
              }}
            >
              <option value="">Semua tingkat</option>
              {filterOptions.levels.map((item) => <option key={item} value={item}>Tingkat {item}</option>)}
            </select>
          </label>
          <label className="materials-filter-field">
            <span>Kelas</span>
            <select value={classFilter} onChange={(event) => setClassFilter(event.target.value)}>
              <option value="">Semua kelas</option>
              {filterOptions.classes.map((item) => <option key={item} value={item}>{item}</option>)}
            </select>
          </label>
          <label className="materials-filter-field">
            <span>Urutkan tanggal</span>
            <select value={dateSort} onChange={(event) => setDateSort(event.target.value)}>
              <option value="newest">Terbaru</option><option value="oldest">Terlama</option>
            </select>
          </label>
          <button type="button" className="materials-secondary-button materials-reset-filters" onClick={resetFilters} disabled={!hasActiveFilters}>Reset filter</button>
        </div>

        {loading ? (
          <p className="materials-state">Memuat bahan ajar…</p>
        ) : materials.length === 0 ? (
          <p className="materials-state">
            Belum ada bahan ajar. Klik “Tambah bahan ajar” untuk membuat materi baru.
          </p>
        ) : filteredMaterials.length === 0 ? (
          <div className="materials-empty"><p>Tidak ada bahan ajar yang cocok dengan pencarian atau filter.</p><button type="button" className="materials-secondary-button" onClick={resetFilters}>Hapus pencarian dan filter</button></div>
        ) : (
          <div className="materials-list">
            {filteredMaterials.map((material) => {
              const attachments = materialAttachments(material)

              return (
                <article className="materials-card" key={material.id}>
                  <div className="materials-card-icon">
                    <span>DOC</span>
                  </div>

                  <div className="materials-card-content">
                    <h3>{material.judul || 'Bahan ajar tanpa judul'}</h3>

                    <p className="materials-upload-date">
                      <span>Diunggah:</span>{' '}
                      {formatUploadDate(material.created_at)}
                    </p>

                    <div className="materials-card-meta" aria-label="Informasi jadwal">
                      <span className="materials-meta-subject">{scheduleDetails(material.jadwal_pelajaran || {}).mapel}</span>
                      <span className="materials-meta-context">
                        {scheduleDetails(material.jadwal_pelajaran || {}).tingkat ? `Tingkat ${scheduleDetails(material.jadwal_pelajaran || {}).tingkat} · ` : ''}{scheduleDetails(material.jadwal_pelajaran || {}).kelas}
                        {scheduleDetails(material.jadwal_pelajaran || {}).hari ? ` · ${scheduleDetails(material.jadwal_pelajaran || {}).hari}` : ''}
                        {scheduleDetails(material.jadwal_pelajaran || {}).waktu ? ` · ${scheduleDetails(material.jadwal_pelajaran || {}).waktu}` : ''}
                      </span>
                    </div>

                    {material.deskripsi && (
                      <p className="materials-card-description">
                        {material.deskripsi}
                      </p>
                    )}

                    <div className="materials-attachments">
                      <strong>
                        Lampiran ({attachments.length})
                      </strong>

                      {attachments.length === 0 ? (
                        <p className="materials-attachments-empty">
                          Belum ada lampiran.
                        </p>
                      ) : (
                        <ul className="materials-attachment-list">
                          {attachments.map((attachment, index) => (
                            <li
                              className="materials-attachment-item"
                              key={
                                attachment.id ??
                                `${attachment.file_name}-${index}`
                              }
                            >
                              <span className="materials-attachment-name">
                                <span className="materials-attachment-filename">
                                  {attachment.file_name || 'Lampiran tanpa nama'}
                                </span>

                                <small className="materials-attachment-date">
                                  Diunggah:{' '}
                                  {formatUploadDate(attachment.created_at)}
                                </small>

                                {attachment.file_size !== null &&
                                  attachment.file_size !== undefined && (
                                    <small>
                                      {formatFileSize(attachment.file_size)}
                                    </small>
                                  )}
                              </span>

                              <div className="materials-attachment-actions">
                                <button
                                  type="button"
                                  className="materials-secondary-button"
                                  onClick={() =>
                                    downloadOneAttachment(
                                      material,
                                      attachment,
                                      index,
                                    )
                                  }
                                  disabled={downloading !== null}
                                >
                                  {downloading ===
                                  `${material.id}-${attachment.id ?? index}`
                                    ? 'Mengunduh…'
                                    : 'Unduh'}
                                </button>
                              </div>
                            </li>
                          ))}
                        </ul>
                      )}
                    </div>
                  </div>

                  <div className="materials-card-actions">
                    <button
                      type="button"
                      className="materials-secondary-button"
                      onClick={() => openEdit(material)}
                    >
                      Edit
                    </button>

                    <button
                      type="button"
                      className="materials-danger-button"
                      onClick={() => confirmRemove(material)}
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

      {modalOpen && (
        <div
          className="materials-modal-backdrop"
          onMouseDown={(event) => {
            if (event.target === event.currentTarget) closeModal()
          }}
        >
          <section
            className="materials-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="materials-modal-title"
          >
            <header className="materials-modal-header">
              <div>
                <p className="materials-eyebrow">PEMBELAJARAN</p>
                <h2 id="materials-modal-title">
                  {editing !== null ? 'Edit bahan ajar' : 'Tambah bahan ajar'}
                </h2>
                <p>
                  Isi informasi materi dan pilih satu atau beberapa lampiran.
                </p>
              </div>

              <button
                type="button"
                className="materials-modal-close"
                onClick={closeModal}
                disabled={saving || attachmentDeleting !== null}
                aria-label="Tutup"
              >
                ×
              </button>
            </header>

            <form onSubmit={handleSubmit}>
              {error && (
                <div className="materials-alert error" role="alert">
                  {error}
                </div>
              )}

              <div className="materials-form-grid">
                <label className="materials-form-field">
                  <span>Jadwal mengajar</span>
                  <select
                    name="jadwal_pelajaran_id"
                    value={form.jadwal_pelajaran_id}
                    onChange={handleFieldChange}
                    required
                    disabled={saving}
                  >
                    <option value="">Pilih mata pelajaran, tingkat, kelas, dan jadwal</option>

                    {schedules.map((schedule) => (
                      <option key={schedule.id} value={schedule.id}>
                        {scheduleLabel(schedule)}
                      </option>
                    ))}
                  </select>
                </label>

                <label className="materials-form-field">
                  <span>Judul bahan ajar</span>
                  <input
                    type="text"
                    name="judul"
                    value={form.judul}
                    onChange={handleFieldChange}
                    placeholder="Contoh: Modul Dasar Elektronika"
                    maxLength={255}
                    required
                    disabled={saving}
                  />
                </label>
              </div>

              <label className="materials-form-field">
                <span>Deskripsi</span>
                <textarea
                  name="deskripsi"
                  value={form.deskripsi}
                  onChange={handleFieldChange}
                  placeholder="Ringkasan isi materi (opsional)"
                  rows={3}
                  disabled={saving}
                />
              </label>

              {editing !== null && (
                <div className="materials-attachments">
                  <strong>Lampiran tersimpan</strong>

                  {materialAttachments(selectedMaterial).length === 0 ? (
                    <p className="materials-attachments-empty">
                      Belum ada lampiran tersimpan.
                    </p>
                  ) : (
                    <ul className="materials-attachment-list">
                      {materialAttachments(selectedMaterial).map(
                        (attachment, index) => (
                          <li
                            className="materials-attachment-item"
                            key={
                              attachment.id ??
                              `${attachment.file_name}-${index}`
                            }
                          >
                            <span className="materials-attachment-name">
                              <span className="materials-attachment-filename">
                                {attachment.file_name || 'Lampiran tanpa nama'}
                              </span>

                              <small className="materials-attachment-date">
                                Diunggah:{' '}
                                {formatUploadDate(attachment.created_at)}
                              </small>

                              {attachment.file_size !== null &&
                                attachment.file_size !== undefined && (
                                  <small>
                                    {formatFileSize(attachment.file_size)}
                                  </small>
                                )}
                            </span>

                            <div className="materials-attachment-actions">
                              <button
                                type="button"
                                className="materials-secondary-button"
                                onClick={() =>
                                  downloadOneAttachment(
                                    selectedMaterial,
                                    attachment,
                                    index,
                                  )
                                }
                                disabled={
                                  saving || downloading !== null
                                }
                              >
                                Unduh
                              </button>

                              {attachment.id !== null &&
                                attachment.id !== undefined && (
                                  <button
                                    type="button"
                                    className="materials-danger-button"
                                    onClick={() =>
                                      removeOneAttachment(
                                        selectedMaterial,
                                        attachment,
                                      )
                                    }
                                    disabled={
                                      saving ||
                                      attachmentDeleting !== null
                                    }
                                  >
                                    {attachmentDeleting === attachment.id
                                      ? 'Menghapus…'
                                      : 'Hapus lampiran'}
                                  </button>
                                )}
                            </div>
                          </li>
                        ),
                      )}
                    </ul>
                  )}

                  <p className="materials-form-help">
                    Lampiran yang sudah tersimpan tetap ada ketika kamu
                    menambahkan file baru. Tanggal unggah setiap lampiran
                    mengikuti waktu file tersebut dibuat.
                  </p>
                </div>
              )}

              <div className="materials-form-field">
                <label htmlFor="materials-files">
                  <span>Lampiran materi</span>
                </label>

                <input
                  id="materials-files"
                  type="file"
                  multiple
                  onChange={handleFilesChange}
                  disabled={saving}
                  aria-describedby="materials-files-help"
                />

                <p
                  className="materials-form-help"
                  id="materials-files-help"
                >
                  Pilih beberapa file sekaligus atau pilih file lagi untuk
                  menambahkannya ke daftar. Maksimal {MAX_FILE_LABEL} per file.
                </p>
              </div>

              {form.files.length > 0 && (
                <div className="materials-attachments">
                  <strong>
                    File baru yang akan diunggah ({form.files.length})
                  </strong>

                  <ul className="materials-selected-files">
                    {form.files.map((file, index) => (
                      <li
                        key={`${file.name}-${file.size}-${file.lastModified}-${index}`}
                        className="materials-attachment-item"
                      >
                        <span className="materials-attachment-name">
                          <span className="materials-attachment-filename">
                            {file.name}
                          </span>
                          <small>{formatFileSize(file.size)}</small>
                          <small className="materials-attachment-date">
                            Tanggal unggah akan ditetapkan saat file disimpan.
                          </small>
                        </span>

                        <button
                          type="button"
                          className="materials-danger-button"
                          onClick={() => removeSelectedFile(index)}
                          disabled={saving}
                        >
                          Hapus dari daftar
                        </button>
                      </li>
                    ))}
                  </ul>
                </div>
              )}

              <footer className="materials-modal-footer">
                <button
                  type="button"
                  className="materials-secondary-button"
                  onClick={closeModal}
                  disabled={saving || attachmentDeleting !== null}
                >
                  Batal
                </button>

                <button
                  type="submit"
                  className="materials-primary-button"
                  disabled={saving || attachmentDeleting !== null}
                >
                  {saving
                    ? 'Menyimpan…'
                    : editing !== null
                      ? 'Simpan perubahan'
                      : 'Tambah bahan ajar'}
                </button>
              </footer>
            </form>
          </section>
        </div>
      )}

      {deleteTarget && (
        <div className="materials-modal-backdrop">
          <section
            className="materials-modal materials-confirm-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="materials-delete-title"
          >
            <header className="materials-modal-header">
              <div>
                <h2 id="materials-delete-title">Hapus bahan ajar?</h2>
                <p>
                  Bahan ajar “{deleteTarget.judul}” beserta seluruh lampirannya
                  akan dihapus. Tindakan ini tidak dapat dibatalkan.
                </p>
              </div>
            </header>

            {error && (
              <div className="materials-alert error" role="alert">
                {error}
              </div>
            )}

            <footer className="materials-modal-footer">
              <button
                type="button"
                className="materials-secondary-button"
                onClick={() => setDeleteTarget(null)}
                disabled={deleting}
              >
                Batal
              </button>

              <button
                type="button"
                className="materials-danger-button"
                onClick={handleDeleteMaterial}
                disabled={deleting}
              >
                {deleting ? 'Menghapus…' : 'Ya, hapus'}
              </button>
            </footer>
          </section>
        </div>
      )}
    </main>
  )
}
