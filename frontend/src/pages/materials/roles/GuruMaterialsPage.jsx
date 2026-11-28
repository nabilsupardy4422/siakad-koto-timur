import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useAuth } from '../../../context/useAuth'
import { collectPages, teacherId } from '../../../utils/teachingScope'
import { loadMaterialWorkspace, canSelectMaterialSchedule } from './materialScope'
import ConfirmDialog from '../../../components/ui/ConfirmDialog'
import ToastNotification from '../../../components/ui/ToastNotification'
import { materialApi } from '../../../services/materialApi'
import { scheduleApi } from '../../../services/scheduleApi'
import { academicYearApi } from '../../../services/academicYearApi'
import TeacherListPager,{useTeacherPage} from '../../../components/ui/TeacherListPager'
import SearchableSelect from '../../../components/ui/TeacherNativeSelect'
import FileDropzone from '../../../components/ui/FileDropzone'
import FileTypeIcon from '../../../components/ui/FileTypeIcon'
import WorkspaceModal from '../../../components/ui/WorkspaceModal'
import './GuruMaterialsPage.css'

const MAX_FILE_SIZE = 10 * 1024 * 1024
const MAX_FILE_LABEL = '10 MB'
const EMPTY_LIST = []
const SUPPORTED_EXTENSIONS = new Set('pdf doc docx xls xlsx ppt pptx odt ods odp jpg jpeg png gif webp txt csv zip mp3 mp4 wav'.split(' '))
const supportedFile = file => file.name.includes('.') && SUPPORTED_EXTENSIONS.has(file.name.split('.').pop().toLowerCase())

const EMPTY_FORM = {
  jadwal_pelajaran_id: '',
  judul: '',
  deskripsi: '',
  files: [],
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
  const { user } = useAuth()
  const guruId = teacherId(user)
  const requestVersion = useRef(0)
  const [expanded, setExpanded] = useState({})
  const [loadedMaterials, setMaterials] = useState([])
  const [loadedSchedules, setSchedules] = useState([])
  const [academicYears, setAcademicYears] = useState([])
  const [selectedYearId, setSelectedYearId] = useState('')
  const contextKey = `${guruId ?? ''}:${selectedYearId}`
  const [loadedContext, setLoadedContext] = useState(null)
  const materials = loadedContext === contextKey ? loadedMaterials : EMPTY_LIST
  const schedules = loadedContext === contextKey ? loadedSchedules : EMPTY_LIST
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
  const [attachmentTarget, setAttachmentTarget] = useState(null)
  const [toast, setToast] = useState(null)
  const closeToast = useCallback(() => setToast(null), [])
  const notify = useCallback((type, title, message) => setToast({type, title, message}), [])
  const hasOwnMaterial = item => materials.some(m => String(m.id) === String(item?.id))
  const canSelectSchedule = id => canSelectMaterialSchedule(schedules, id, guruId, selectedYearId)


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
          return String(schedule.mapel_id ?? schedule.mapel?.id ?? '') === subjectFilter
        })
      : teacherSchedules

    const levelSchedules = levelFilter
      ? subjectSchedules.filter((schedule) => {
          const details = scheduleDetails(schedule)
          return details.tingkat === levelFilter
        })
      : subjectSchedules

    const classes = new Map()
    levelSchedules.forEach(schedule => {
      const d = scheduleDetails(schedule)
      if (schedule?.kelas?.nama || schedule?.kelas?.nama_kelas) {
        const value = String(schedule.kelas_id ?? schedule.kelas?.id ?? '')
        if (!value) return
        classes.set(value, { value, label: `${d.kelas}${d.tingkat ? ` · Tingkat ${d.tingkat}` : " · Tingkat belum tersedia"}` })
      }
    })
    return {
      subjects: [...new Map(teacherSchedules.filter(schedule => (schedule.mapel_id ?? schedule.mapel?.id) != null).map(schedule => {
        const value = String(schedule.mapel_id ?? schedule.mapel?.id)
        return [value, {value, label: scheduleDetails(schedule).mapel}]
      })).values()].sort((a,b) => a.label.localeCompare(b.label, 'id')),
      levels: unique(
        subjectSchedules
          .map((schedule) => schedule?.kelas?.tingkat)
          .map((value) => value == null ? '' : String(value)),
      ).sort((a, b) => Number(a) - Number(b)),
      classes: [...classes.values()].sort((a,b) => a.label.localeCompare(b.label, "id")),
    }
  }, [teacherSchedules, subjectFilter, levelFilter])

  // Bersihkan filter turunan jika pilihan induk membuatnya tidak valid.
  useEffect(() => {
    if (subjectFilter && !filterOptions.subjects.some(option => option.value === subjectFilter)) {
      setSubjectFilter(''); setLevelFilter(''); setClassFilter(''); return
    }
    if (levelFilter && !filterOptions.levels.includes(levelFilter)) {
      setLevelFilter('')
      setClassFilter('')
      return
    }

    if (classFilter && !filterOptions.classes.some(option => option.value === classFilter)) {
      setClassFilter('')
    }
  }, [filterOptions.subjects, filterOptions.levels, filterOptions.classes, subjectFilter, levelFilter, classFilter])


  const filteredMaterials = useMemo(() => {
    const query = searchTerm.trim().toLocaleLowerCase('id-ID')
    return materials.filter((material) => {
      const details = scheduleDetails(material?.jadwal_pelajaran || {})
      if (query && !materialSearchText(material).includes(query)) return false
      if (subjectFilter && String(material.jadwal_pelajaran?.mapel_id ?? material.jadwal_pelajaran?.mapel?.id ?? '') !== subjectFilter) return false
      if (levelFilter && details.tingkat !== levelFilter) return false
      if (classFilter && String(material.jadwal_pelajaran?.kelas_id ?? material.jadwal_pelajaran?.kelas?.id ?? '') !== classFilter) return false
      return true
    }).sort((a, b) => {
      const aTime = new Date(a?.created_at || 0).getTime()
      const bTime = new Date(b?.created_at || 0).getTime()
      return dateSort === 'oldest' ? aTime - bTime : bTime - aTime
    })
  }, [materials, searchTerm, subjectFilter, levelFilter, classFilter, dateSort])

  const materialPage=useTeacherPage(filteredMaterials,JSON.stringify([selectedYearId,searchTerm,subjectFilter,levelFilter,classFilter,dateSort]))
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
        const years = await collectPages(page => academicYearApi.getAll({ per_page: 100, page }))
        const activeYears = years.filter(
          (year) =>
            year.is_active === true ||
            year.is_active === 1 ||
            year.is_active === '1',
        )

        if (cancelled) return
        const activeYear=activeYears.length===1?activeYears[0]:null
        setAcademicYears(years)
        if(activeYears.length>1)setError('Lebih dari satu periode aktif. Pilih periode eksplisit; hubungi Tata Usaha.')
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
    const version = ++requestVersion.current
    if (!selectedYearId || guruId == null) {
      setMaterials([])
      setSchedules([])
      setLoadedContext(null)
      setLoading(false)
      return
    }

    setLoading(true)
    setError('')

    try {
      const data = await loadMaterialWorkspace(scheduleApi, materialApi, guruId, selectedYearId)
      if (version !== requestVersion.current) return
      setMaterials(data.materials)
      setSchedules(data.schedules)
      setLoadedContext(contextKey)
    } catch (err) {
      if (version !== requestVersion.current) return
      setError(message(err))
      setMaterials([])
      setSchedules([])
    } finally {
      if (version === requestVersion.current) setLoading(false)
    }
  }, [selectedYearId, guruId, contextKey])

  useEffect(() => {
    load()
    return () => { requestVersion.current++ }
  }, [load])

  const closeModal = useCallback(() => {
    if (saving || attachmentDeleting !== null) return

    setModalOpen(false)
    setEditing(null)
    setForm({ ...EMPTY_FORM, files: [] })
  }, [saving, attachmentDeleting])

  function openCreate() {
    if (!schedules.length || loading) return
    setEditing(null)
    setForm({ ...EMPTY_FORM, files: [] })
    setError('')
    setNotice('')
    setModalOpen(true)
  }

  function openEdit(material) {
    if (loading || !hasOwnMaterial(material)) return
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

  function handleFilesChange(selectedFiles) {
    if (saving || attachmentDeleting !== null || !selectedFiles.length) return
    const unsupported = selectedFiles.filter(file => !supportedFile(file))
    if (unsupported.length) {
      setError(`Format file tidak didukung: ${unsupported.map(file => file.name).join(', ')}`)
      return
    }
    const oversizedFiles = selectedFiles.filter(file => file.size > MAX_FILE_SIZE)
    if (oversizedFiles.length) {
      setError(`Setiap file maksimal ${MAX_FILE_LABEL}. File terlalu besar: ${oversizedFiles.map(file => file.name).join(', ')}`)
      return
    }
    setError('')
    setForm(previous => ({ ...previous, files: [...previous.files, ...selectedFiles] }))
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

    if (loading || !canSelectSchedule(form.jadwal_pelajaran_id) || (editing !== null && !selectedMaterial)) {
      setError('Pilih jadwal mengajar milik Anda pada periode terpilih.')
      return
    }

    if (!form.judul.trim() || form.judul.trim().length > 255) {
      setError('Judul bahan ajar wajib diisi, maksimal 255 karakter.')
      return
    }

    if (form.files.some(file => !supportedFile(file))) {
      setError('Terdapat file dengan format yang tidak didukung.')
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

      notify('success', editing !== null ? 'Bahan ajar diperbarui' : 'Bahan ajar ditambahkan')
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
    if (!hasOwnMaterial(material) || loading) return
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
          { scope: 'teaching' },
        )
      } else {
        await materialApi.download(
          material.id,
          attachment.file_name || material.judul,
          { scope: 'teaching' },
        )
      }

      notify('success', 'Unduhan diproses', attachment.file_name || material.judul)
      setNotice(
        `Permintaan unduhan "${attachment.file_name || material.judul}" berhasil diproses.`,
      )
    } catch (err) {
      setError(message(err))
      notify('error', 'Unduhan gagal', message(err))
    } finally {
      setDownloading(null)
    }
  }

  function removeOneAttachment(material, attachment) {
    if (!hasOwnMaterial(material) || loading || attachment.id == null) return
    setError('')
    setNotice('')
    setAttachmentTarget({material, attachment})
  }

  async function confirmRemoveAttachment() {
    if (!attachmentTarget || loading || !hasOwnMaterial(attachmentTarget.material)) return
    const { material, attachment } = attachmentTarget
    setError('')
    setAttachmentDeleting(attachment.id)
    try {
      await materialApi.deleteAttachment(material.id, attachment.id)
      setAttachmentTarget(null)
      await load()
      setNotice(`Lampiran "${attachment.file_name}" berhasil dihapus.`)
      notify('success', 'Lampiran dihapus', attachment.file_name)
    } catch (err) {
      setError(message(err))
    } finally {
      setAttachmentDeleting(null)
    }
  }

  function confirmRemove(material) {
    if (!hasOwnMaterial(material) || loading) return
    setError('')
    setNotice('')
    setDeleteTarget(material)
  }

  async function handleDeleteMaterial() {
    if (!deleteTarget || !hasOwnMaterial(deleteTarget) || loading) return

    setDeleting(true)
    setError('')
    setNotice('')

    try {
      const deletedTitle = deleteTarget.judul

      await materialApi.delete(deleteTarget.id)
      setDeleteTarget(null)

      await load()

      notify('success', 'Bahan ajar dihapus', deletedTitle)
      setNotice(
        `Bahan ajar "${deletedTitle}" beserta lampirannya berhasil dihapus.`,
      )
    } catch (err) {
      setError(message(err))
    } finally {
      setDeleting(false)
    }
  }

  const busy = saving || attachmentDeleting !== null
  const options = (items, all, label = value => value) => [{ value: '', label: all }, ...items.map(value => ({ value, label: label(value) }))]
  const scheduleOptions = schedules.map(schedule => {
    const d = scheduleDetails(schedule)
    return { value: String(schedule.id), label: `${d.kelas} · ${d.tingkat ? `Tingkat ${d.tingkat}` : 'Tingkat belum tersedia'}`, description: [d.mapel, d.hari, d.waktu].filter(Boolean).join(' · ') }
  })
  const yearOptions = academicYears.map(year => ({ value: String(year.id), label: `${year.tahun_mulai}/${year.tahun_selesai} · ${year.semester === 'GANJIL' ? 'Ganjil' : year.semester === 'GENAP' ? 'Genap' : year.semester}${[true, 1, '1'].includes(year.is_active) ? ' (Aktif)' : ''}` }))
  const fileCount = materials.reduce((sum, material) => sum + materialAttachments(material).length, 0)
  const classLabel = d => `${d.kelas} · ${d.tingkat ? `Tingkat ${d.tingkat}` : 'Tingkat belum tersedia'}`

  function fileRows(material, allowRemove = false) {
    const files = materialAttachments(material)
    if (!files.length) return <p className="gm-empty-files">Belum ada lampiran.</p>
    return <ul className="gm-files">{files.map((file, index) => <li className="gm-file-row" key={file.id ?? `${file.file_name}-${index}`}>
      <FileTypeIcon name={file.file_name}/>
      <div className="gm-file-info"><strong>{file.file_name || 'Lampiran tanpa nama'}</strong><small>{[formatFileSize(file.file_size), formatUploadDate(file.created_at)].filter(Boolean).join(' · ')}</small></div>
      <div className="gm-row-actions"><button type="button" className="gm-btn gm-secondary" onClick={() => downloadOneAttachment(material, file, index)} disabled={downloading !== null || busy}>{downloading === `${material.id}-${file.id ?? index}` ? 'Mengunduh…' : '↓ Unduh'}</button>
      {allowRemove && file.id != null && <button type="button" className="gm-btn gm-danger" onClick={() => removeOneAttachment(material, file)} disabled={busy}>{attachmentDeleting === file.id ? 'Menghapus…' : 'Hapus lampiran'}</button>}</div>
    </li>)}</ul>
  }

  return <main className="gm-v2">
    <header className="gm-heading"><div><p className="gm-eyebrow">KEGIATAN MENGAJAR</p><h1>Bahan Ajar<span>.</span></h1><p>Kelola dan bagikan materi pembelajaran sesuai jadwal mengajar Anda.</p></div><div className="gm-actions"><button className="gm-btn gm-secondary" type="button" onClick={load} disabled={loading}>↻ {loading ? 'Memuat…' : 'Muat ulang'}</button><button className="gm-btn gm-primary" type="button" onClick={openCreate} disabled={loading || !schedules.length || busy || deleting}>＋ Tambah bahan ajar</button></div></header>
    {!modalOpen && !deleteTarget && error && <div className="gm-alert gm-error" role="alert">{error}</div>}

    <section className="gm-band" aria-label="Konteks perpustakaan"><div className="gm-band-title"><span className="gm-band-icon"><BookIcon/></span><div><small>RUANG MATERI GURU</small><h2>Perpustakaan pembelajaran</h2><p>Dokumen terorganisasi berdasarkan periode akademik.</p></div></div><div className="gm-metric"><span>Materi</span><strong>{loading ? '—' : materials.length}</strong></div><div className="gm-metric"><span>Lampiran</span><strong>{loading ? '—' : fileCount}</strong></div><div className="gm-year"><SearchableSelect label="Tahun akademik" value={selectedYearId} options={yearOptions} placeholder="Pilih tahun akademik" disabled={loading || busy || deleting || modalOpen || Boolean(deleteTarget)} onChange={value => { if (value === selectedYearId) return; setLoading(true); setMaterials([]); setSchedules([]); setExpanded({}); resetFilters(); setSelectedYearId(value) }}/></div></section>
    <section className="gm-filter"><div className="gm-section-head"><div><h2>Temukan materi</h2><p>Telusuri judul atau lampiran, lalu persempit hasil pencarian.</p></div><button className="gm-text-btn" type="button" onClick={resetFilters} disabled={!hasActiveFilters}>↻ Reset filter</button></div><div className="gm-filter-grid"><label className="gm-field gm-search"><span>Cari bahan ajar</span><input type="search" value={searchTerm} onChange={event => setSearchTerm(event.target.value)} placeholder="Cari judul atau nama file…"/></label>
      <SearchableSelect label="Mata pelajaran" value={subjectFilter} options={[{value:'',label:'Semua mapel'},...filterOptions.subjects]} onChange={value => {setSubjectFilter(value);setLevelFilter('');setClassFilter('')}} disabled={loading}/>
      <SearchableSelect label="Tingkat" value={levelFilter} options={options(filterOptions.levels,'Semua tingkat', value => `Tingkat ${value}`)} onChange={value => {setLevelFilter(value);setClassFilter('')}} disabled={loading}/>
      <SearchableSelect label="Kelas" value={classFilter} options={[{value:'',label:'Semua kelas'},...filterOptions.classes]} onChange={setClassFilter} disabled={loading}/>
      <SearchableSelect label="Urutkan tanggal" value={dateSort} options={[{value:'newest',label:'Terbaru'},{value:'oldest',label:'Terlama'}]} onChange={setDateSort}/>
    </div></section>
    <div className="gm-section-head gm-results"><div><h2>Daftar bahan ajar</h2><p aria-live="polite">{loading ? 'Memuat materi…' : `${filteredMaterials.length} dari ${materials.length} materi cocok`}</p></div></div>
    {loading ? <div className="gm-state" role="status">Memuat bahan ajar…</div> : error && !materials.length ? <div className="gm-state"><h3>Data belum dapat dimuat</h3><button className="gm-btn gm-secondary" type="button" onClick={load}>Coba lagi</button></div> : !materials.length ? <div className="gm-state"><BookIcon/><h3>Belum ada bahan ajar</h3><p>Tambahkan materi dan lampiran untuk kelas yang Anda ajar.</p><button className="gm-btn gm-primary" type="button" onClick={openCreate} disabled={!schedules.length || busy || deleting}>＋ Tambah bahan ajar</button></div> : !filteredMaterials.length ? <div className="gm-state"><h3>Materi tidak ditemukan</h3><p>Coba kata kunci atau filter lainnya.</p><button className="gm-btn gm-secondary" type="button" onClick={resetFilters}>Reset filter</button></div> : <div className="gm-list">{materialPage.rows.map((material,index) => {
      const d = scheduleDetails(material.jadwal_pelajaran || {})
      const isOpen = expanded[material.id] ?? (index === 0)
      const attachments = materialAttachments(material)
      return <article className="gm-card" key={material.id}><div className="gm-card-top"><span className="gm-book"><BookIcon/></span><div className="gm-card-content"><h3>{material.judul || 'Bahan ajar tanpa judul'}</h3><p className="gm-meta">{d.mapel} · Diunggah {formatUploadDate(material.created_at)}</p><div className="gm-tags"><span>{classLabel(d)}</span>{(d.hari || d.waktu) && <span>{[d.hari,d.waktu].filter(Boolean).join(' · ')}</span>}</div>{material.deskripsi && <p className="gm-description">{material.deskripsi}</p>}</div><div className="gm-row-actions gm-card-actions"><button className="gm-btn gm-secondary" type="button" onClick={() => openEdit(material)}>Edit</button><button className="gm-btn gm-danger" type="button" onClick={() => confirmRemove(material)}>Hapus</button></div></div>
      <div className="gm-attachment-box"><button type="button" className="gm-attachment-toggle" aria-expanded={isOpen} aria-controls={`gm-files-${material.id}`} onClick={() => setExpanded(old => ({...old,[material.id]:!isOpen}))}><span>▤ &nbsp; Lampiran ({attachments.length})</span><span>{isOpen ? 'Sembunyikan ↑' : 'Tampilkan ↓'}</span></button><div id={`gm-files-${material.id}`} hidden={!isOpen}>{fileRows(material)}</div></div></article>
    })}</div>}
    {!loading&&<TeacherListPager data={materialPage} label="materi"/>}
    {modalOpen && <WorkspaceModal className="gm-v2" eyebrow="PERPUSTAKAAN PEMBELAJARAN" title={editing !== null ? 'Edit bahan ajar' : 'Tambah bahan ajar'} description="Lengkapi informasi materi dan kelola lampiran pembelajaran." onClose={closeModal} busy={busy} footer={<><button type="button" className="gm-btn gm-secondary" onClick={closeModal} disabled={busy}>Batal</button><button type="submit" form="gm-material-form" className="gm-btn gm-primary" disabled={busy}>{saving ? 'Menyimpan…' : editing !== null ? 'Simpan perubahan' : 'Tambah bahan ajar'}</button></>}>
      <form id="gm-material-form" noValidate onSubmit={handleSubmit} className="gm-editor">
        {error && <div className="gm-alert gm-error" role="alert">{error}</div>}{notice && <div className="gm-alert gm-success" role="status">{notice}</div>}
        <section className="gm-form-section"><p className="gm-eyebrow">01 / INFORMASI MATERI</p><div className="gm-form-grid"><SearchableSelect label="Jadwal mengajar" value={form.jadwal_pelajaran_id} options={scheduleOptions} placeholder="Pilih jadwal mengajar" required disabled={busy} onChange={value => setForm(prev => ({...prev,jadwal_pelajaran_id:value}))}/><label className="gm-field"><span>Judul bahan ajar *</span><input name="judul" value={form.judul} onChange={handleFieldChange} placeholder="Contoh: Modul dasar" maxLength={255} required disabled={busy}/></label></div><label className="gm-field"><span>Deskripsi (opsional)</span><textarea name="deskripsi" value={form.deskripsi} onChange={handleFieldChange} rows={3} placeholder="Ringkasan isi materi" disabled={busy}/></label></section>
        {editing !== null && <section className="gm-form-section"><p className="gm-eyebrow">02 / LAMPIRAN TERSIMPAN</p><div className="gm-attachment-box">{selectedMaterial ? fileRows(selectedMaterial,true) : <p className="gm-empty-files">Memuat lampiran…</p>}</div><p className="gm-help">Lampiran tersimpan tetap ada saat menambah file baru. Hapus lampiran langsung berlaku setelah dikonfirmasi.</p></section>}
        <section className="gm-form-section"><p className="gm-eyebrow">{editing !== null ? '03' : '02'} / LAMPIRAN {editing !== null ? 'BARU' : 'MATERI'}</p><p className="gm-help">Format: dokumen Office/PDF, gambar, teks, ZIP, audio MP3/WAV, atau MP4. Maksimal 10 MB per file.</p><FileDropzone onFiles={handleFilesChange} disabled={busy} maxLabel={MAX_FILE_LABEL}/>{form.files.length > 0 && <div className="gm-attachment-box"><h3 className="gm-pending-title">File baru ({form.files.length})</h3><ul className="gm-files">{form.files.map((file,index) => <li className="gm-file-row" key={`${file.name}-${file.lastModified}-${index}`}><FileTypeIcon name={file.name}/><div className="gm-file-info"><strong>{file.name}</strong><small>{formatFileSize(file.size)} · Belum diunggah</small></div><button type="button" className="gm-btn gm-danger" disabled={busy} onClick={() => removeSelectedFile(index)}>Hapus dari daftar</button></li>)}</ul></div>}</section>
      </form>
    </WorkspaceModal>}
    {attachmentTarget && <ConfirmDialog title="Hapus lampiran?" description="Lampiran lainnya tidak akan dihapus." danger confirmLabel="Ya, hapus" busy={attachmentDeleting !== null} error={error} onClose={() => {if (attachmentDeleting === null) {setAttachmentTarget(null);setError('')}}} onConfirm={confirmRemoveAttachment}><p>Hapus lampiran “{attachmentTarget.attachment.file_name}”?</p></ConfirmDialog>}
    {deleteTarget && <WorkspaceModal className="gm-v2" eyebrow="PERPUSTAKAAN PEMBELAJARAN" title="Hapus bahan ajar?" description="Tindakan ini tidak dapat dibatalkan." onClose={() => {if (!deleting) setDeleteTarget(null)}} busy={deleting} compact footer={<><button className="gm-btn gm-secondary" type="button" disabled={deleting} onClick={() => setDeleteTarget(null)}>Batal</button><button className="gm-btn gm-danger-solid" type="button" disabled={deleting} onClick={handleDeleteMaterial}>{deleting ? 'Menghapus…' : 'Ya, hapus'}</button></>}><p>Bahan ajar “{deleteTarget.judul}” beserta seluruh lampirannya akan dihapus.</p>{error && <div role="alert" className="gm-alert gm-error">{error}</div>}</WorkspaceModal>}
    <ToastNotification notice={toast} onClose={closeToast}/>
  </main>
}

function BookIcon() {return <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" aria-hidden="true"><path d="M12 6c-3-2-6-2-9-1v15c3-1 6-1 9 1 3-2 6-2 9-1V5c-3-1-6-1-9 1Zm0 0v15" strokeLinejoin="round"/></svg>}
