import { useEffect, useState } from 'react'

import { reportCardApi } from '../../../services/reportCardApi'

import LoadingState from '../../../components/ui/LoadingState'
import ErrorState from '../../../components/ui/ErrorState'
import { apiClient } from '../../../services/apiClient'
const ITEMS_PER_PAGE = 15

export default function TuReportCards() {
  const [reportCards, setReportCards] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const [search, setSearch] = useState('')
  const [type, setType] = useState('')
  const [semester, setSemester] = useState('')

  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)

  const [uploading, setUploading] = useState(false)
  const [selectedFile, setSelectedFile] = useState(null)
  const [studentId, setStudentId] = useState('')
  const [academicYearId, setAcademicYearId] = useState('')
  const [academicYears, setAcademicYears] = useState([])
const [loadingAcademicYears, setLoadingAcademicYears] = useState(true)
const [academicYearError, setAcademicYearError] = useState('')
  const [reportType, setReportType] = useState('MID_SEMESTER')

  const [actionError, setActionError] = useState('')
  const [actionSuccess, setActionSuccess] = useState('')

  const [searchSubmitted, setSearchSubmitted] = useState('')

  async function loadReportCards({
    currentPage = page,
    searchValue = searchSubmitted,
    typeValue = type,
    semesterValue = semester,
  } = {}) {
    try {
      setLoading(true)
      setError('')

      const response = await reportCardApi.getReportCards({
        page: currentPage,
        per_page: ITEMS_PER_PAGE,
        student_id: searchValue,
        type: typeValue,
        semester: semesterValue,
      })

      // Mendukung response pagination Laravel dan response API sederhana.
      const payload = response?.data

      const records = Array.isArray(payload)
        ? payload
        : Array.isArray(payload?.data)
          ? payload.data
          : []

      const pagination = payload?.meta || response?.meta || {}

      setReportCards(records)

      setLastPage(
        Number(
          pagination.last_page ||
          response?.last_page ||
          1
        )
      )
    } catch (err) {
      setError(
        err.message || 'Gagal mengambil data rapor.'
      )
    } finally {
      setLoading(false)
    }
  }
useEffect(() => {
  let isMounted = true

  async function loadAcademicYears() {
    try {
      setLoadingAcademicYears(true)
      setAcademicYearError('')

      const response = await apiClient.get('/academic-years')

      const payload = response?.data

      const records = Array.isArray(payload)
        ? payload
        : Array.isArray(payload?.data)
          ? payload.data
          : []

      if (isMounted) {
        setAcademicYears(records)
      }
    } catch (err) {
      if (isMounted) {
        setAcademicYearError(
          err.message || 'Gagal memuat tahun akademik.'
        )
      }
    } finally {
      if (isMounted) {
        setLoadingAcademicYears(false)
      }
    }
  }

  loadAcademicYears()

  return () => {
    isMounted = false
  }
}, [])
  useEffect(() => {
    loadReportCards({
      currentPage: page,
      searchValue: searchSubmitted,
      typeValue: type,
      semesterValue: semester,
    })
  }, [page, type, semester, searchSubmitted])

  function handleSearch(event) {
    event.preventDefault()

    setPage(1)
    setSearchSubmitted(search.trim())
  }

  function handleResetFilter() {
    setSearch('')
    setSearchSubmitted('')
    setType('')
    setSemester('')
    setPage(1)
  }

  async function handleUpload(event) {
    event.preventDefault()

    setActionError('')
    setActionSuccess('')

    if (!selectedFile) {
      setActionError('Silakan pilih file rapor.')
      return
    }

    if (!studentId.trim() || !academicYearId.trim()) {
      setActionError(
        'ID siswa dan tahun akademik wajib diisi.'
      )
      return
    }

    if (selectedFile.type !== 'application/pdf') {
      setActionError('File rapor harus berformat PDF.')
      return
    }

    const formData = new FormData()

    formData.append('file', selectedFile)
    formData.append('siswa_id', studentId.trim())
    formData.append(
      'tahun_akademik_id',
      academicYearId.trim()
    )
    formData.append('jenis_rapor', reportType)

    try {
      setUploading(true)

      await reportCardApi.uploadReportCard(formData)

      setActionSuccess('Rapor berhasil diunggah.')

      setSelectedFile(null)
      setStudentId('')
      setAcademicYearId('')

      event.target.reset()

      await loadReportCards({
        currentPage: page,
        searchValue: searchSubmitted,
        typeValue: type,
        semesterValue: semester,
      })
    } catch (err) {
      setActionError(
        err.message || 'Gagal mengunggah rapor.'
      )
    } finally {
      setUploading(false)
    }
  }

  async function handleDownload(reportCard) {
    setActionError('')
    setActionSuccess('')

    try {
      const blob = await reportCardApi.downloadReportCard(
        reportCard.id
      )

      if (!(blob instanceof Blob)) {
        throw new Error(
          'Response download tidak valid.'
        )
      }

      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')

      link.href = url
      link.download =
        reportCard.file?.name || 'rapor.pdf'

      document.body.appendChild(link)
      link.click()
      link.remove()

      // Beri waktu browser memproses download.
      window.setTimeout(() => {
        URL.revokeObjectURL(url)
      }, 1000)
    } catch (err) {
      setActionError(
        err.message || 'Gagal mengunduh rapor.'
      )
    }
  }

  async function handleDelete(reportCard) {
    const confirmed = window.confirm(
      `Hapus rapor ${
        reportCard.file?.name || ''
      }?`
    )

    if (!confirmed) return

    setActionError('')
    setActionSuccess('')

    try {
      await reportCardApi.deleteReportCard(
        reportCard.id
      )

      setActionSuccess('Rapor berhasil dihapus.')

      // Jika data terakhir di halaman terhapus,
      // kembali ke halaman sebelumnya.
      const nextPage =
        reportCards.length === 1 && page > 1
          ? page - 1
          : page

      setPage(nextPage)

      await loadReportCards({
        currentPage: nextPage,
        searchValue: searchSubmitted,
        typeValue: type,
        semesterValue: semester,
      })
    } catch (err) {
      setActionError(
        err.message || 'Gagal menghapus rapor.'
      )
    }
  }

  if (loading && reportCards.length === 0) {
    return (
      <LoadingState message="Memuat data rapor..." />
    )
  }

  if (error && reportCards.length === 0) {
    return (
      <ErrorState
        title="Gagal memuat rapor"
        message={error}
      />
    )
  }

  return (
    <section className="report-cards-page">
      <header className="report-cards-header">
        <div>
          <h1>Data Rapor</h1>
          <p>
            Kelola dokumen rapor siswa.
          </p>
        </div>
      </header>

      {actionError && (
        <div
          className="report-message report-message-error"
          role="alert"
        >
          {actionError}
        </div>
      )}

      {actionSuccess && (
        <div
          className="report-message report-message-success"
          role="status"
        >
          {actionSuccess}
        </div>
      )}

      {error && reportCards.length > 0 && (
        <div
          className="report-message report-message-error"
          role="alert"
        >
          {error}
        </div>
      )}

      <section className="report-upload-section">
        <h2>Upload Rapor</h2>

        <form onSubmit={handleUpload}>
          <div>
            <label htmlFor="report-student-id">
              ID Siswa
            </label>

            <input
              id="report-student-id"
              name="siswa_id"
              type="number"
              min="1"
              value={studentId}
              onChange={(event) =>
                setStudentId(event.target.value)
              }
              required
            />
          </div>

          <div>
            <label htmlFor="report-academic-year">
              ID Tahun Akademik
            </label>

            <select
  id="report-academic-year"
  name="tahun_akademik_id"
  value={academicYearId}
  onChange={(event) =>
    setAcademicYearId(event.target.value)
  }
  disabled={loadingAcademicYears || academicYears.length === 0}
  required
>
  <option value="">
    {loadingAcademicYears
      ? 'Memuat tahun akademik...'
      : 'Pilih tahun akademik'}
  </option>

  {academicYears.map((year) => (
    <option key={year.id} value={year.id}>
      {year.tahun_mulai}/{year.tahun_selesai}
      {' — '}
      {year.semester}
      {year.is_active ? ' (Aktif)' : ''}
    </option>
  ))}
</select>

{academicYearError && (
  <p className="report-message-error" role="alert">
    {academicYearError}
  </p>
)}
          </div>

          <div>
            <label htmlFor="report-type">
              Jenis Rapor
            </label>

            <select
              id="report-type"
              name="jenis_rapor"
              value={reportType}
              onChange={(event) =>
                setReportType(event.target.value)
              }
              required
            >
              <option value="MID_SEMESTER">
                Rapor Tengah Semester
              </option>

              <option value="AKHIR_SEMESTER">
                Rapor Akhir Semester
              </option>
            </select>
          </div>

          <div>
            <label htmlFor="report-file">
              File Rapor (PDF)
            </label>

            <input
              id="report-file"
              name="file"
              type="file"
              accept="application/pdf,.pdf"
              onChange={(event) =>
                setSelectedFile(
                  event.target.files?.[0] || null
                )
              }
              required
            />
          </div>

          <button
            type="submit"
            disabled={uploading}
          >
            {uploading
              ? 'Mengunggah...'
              : 'Upload Rapor'}
          </button>
        </form>
      </section>

      <section className="report-filter-section">
        <h2>Daftar Rapor</h2>

        <form onSubmit={handleSearch}>
          <div>
            <label htmlFor="report-search">
              ID Siswa
            </label>

            <input
              id="report-search"
              type="text"
              inputMode="numeric"
              placeholder="Masukkan ID siswa"
              value={search}
              onChange={(event) =>
                setSearch(event.target.value)
              }
            />
          </div>

          <div>
            <label htmlFor="report-filter-type">
              Jenis Rapor
            </label>

            <select
              id="report-filter-type"
              value={type}
              onChange={(event) => {
                setType(event.target.value)
                setPage(1)
              }}
            >
              <option value="">
                Semua jenis rapor
              </option>

              <option value="MID_SEMESTER">
                Rapor Tengah Semester
              </option>

              <option value="AKHIR_SEMESTER">
                Rapor Akhir Semester
              </option>
            </select>
          </div>

          <div>
            <label htmlFor="report-filter-semester">
              Semester
            </label>

            <select
              id="report-filter-semester"
              value={semester}
              onChange={(event) => {
                setSemester(event.target.value)
                setPage(1)
              }}
            >
              <option value="">
                Semua semester
              </option>

              <option value="Ganjil">
                Ganjil
              </option>

              <option value="Genap">
                Genap
              </option>
            </select>
          </div>

          <button type="submit">
            Cari
          </button>

          <button
            type="button"
            onClick={handleResetFilter}
          >
            Reset
          </button>
        </form>
      </section>

      <div className="report-table-wrapper">
        <table className="report-table">
          <thead>
            <tr>
              <th>No</th>
              <th>Siswa</th>
              <th>NIS</th>
              <th>Tahun Akademik</th>
              <th>Semester</th>
              <th>Jenis Rapor</th>
              <th>File</th>
              <th>Pengunggah</th>
              <th>Aksi</th>
            </tr>
          </thead>

          <tbody>
            {reportCards.length === 0 ? (
              <tr>
                <td colSpan="9">
                  Belum ada data rapor.
                </td>
              </tr>
            ) : (
              reportCards.map((reportCard, index) => (
                <tr key={reportCard.id}>
                  <td>
                    {(page - 1) * ITEMS_PER_PAGE +
                      index +
                      1}
                  </td>

                  <td>
                    {reportCard.student?.name || '-'}
                  </td>

                  <td>
                    {reportCard.student?.nis || '-'}
                  </td>

                  <td>
                    {reportCard.academic_year
                      ? `${
                          reportCard.academic_year
                            .tahun_mulai || ''
                        }/${
                          reportCard.academic_year
                            .tahun_selesai || ''
                        }`
                      : '-'}
                  </td>

                  <td>
                    {reportCard.academic_year?.semester ||
                      '-'}
                  </td>

                  <td>
                    {reportCard.type || '-'}
                  </td>

                  <td>
                    {reportCard.file?.name || '-'}
                  </td>

                  <td>
                    {reportCard.uploaded_by?.name || '-'}
                  </td>

                  <td>
                    <button
                      type="button"
                      onClick={() =>
                        handleDownload(reportCard)
                      }
                    >
                      Download
                    </button>

                    <button
                      type="button"
                      onClick={() =>
                        handleDelete(reportCard)
                      }
                    >
                      Hapus
                    </button>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      <div className="report-pagination">
        <button
          type="button"
          disabled={loading || page <= 1}
          onClick={() =>
            setPage((current) =>
              Math.max(1, current - 1)
            )
          }
        >
          Sebelumnya
        </button>

        <span>
          Halaman {page} dari {lastPage}
        </span>

        <button
          type="button"
          disabled={loading || page >= lastPage}
          onClick={() =>
            setPage((current) =>
              Math.min(lastPage, current + 1)
            )
          }
        >
          Berikutnya
        </button>
      </div>
    </section>
  )
}
