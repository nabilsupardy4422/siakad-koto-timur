import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { assignmentApi } from '../../../services/assignmentApi'
import '../styles/GuruAssignmentSubmissions.css'

function formatDate(value) {
  if (!value) return 'Belum tersedia'

  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return 'Belum tersedia'

  return (
    new Intl.DateTimeFormat('id-ID', {
      dateStyle: 'medium',
      timeStyle: 'short',
      timeZone: 'Asia/Jakarta',
    }).format(date) + ' WIB'
  )
}

function getStatus(submission, deadline) {
  if (!submission) return { label: 'Belum mengumpulkan', type: 'pending' }

  const status = String(submission.status || '').toLowerCase()

  if (status === 'dinilai') return { label: 'Sudah dinilai', type: 'graded' }
  if (status === 'perlu_revisi') return { label: 'Perlu revisi', type: 'revision' }

  const submittedAt = submission.submitted_at
    ? new Date(submission.submitted_at).getTime()
    : NaN
  const deadlineAt = deadline ? new Date(deadline).getTime() : NaN

  if (
    status === 'terlambat' ||
    (!Number.isNaN(submittedAt) &&
      !Number.isNaN(deadlineAt) &&
      submittedAt > deadlineAt)
  ) {
    return { label: 'Terlambat', type: 'late' }
  }

  return { label: 'Sudah mengumpulkan', type: 'submitted' }
}

function getFiles(submission) {
  if (Array.isArray(submission?.files) && submission.files.length) {
    return submission.files
  }

  if (submission?.file?.name) return [{ ...submission.file, id: null }]
  return []
}

function formatFileSize(bytes) {
  const size = Number(bytes)
  if (bytes == null || !Number.isFinite(size) || size < 0) return ''
  if (size < 1024) return `${size} B`
  if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} KB`
  return `${(size / (1024 * 1024)).toFixed(1)} MB`
}

function getFileExtension(name) {
  const parts = String(name || '').split('.')
  if (parts.length < 2 || !parts[parts.length - 1]) return 'FILE'
  return parts.pop().slice(0, 5).toUpperCase()
}

function getErrorMessage(error) {
  return (
    error?.response?.data?.message ||
    error?.payload?.message ||
    error?.message ||
    'Terjadi kesalahan. Silakan coba kembali.'
  )
}

function getFocusableElements(container) {
  if (!container) return []

  return Array.from(
    container.querySelectorAll(
      'a[href], button:not(:disabled), input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [contenteditable="true"], [tabindex]:not([tabindex="-1"])',
    ),
  ).filter((element) => {
    if (element.hasAttribute('hidden') || element.getAttribute('aria-hidden') === 'true') return false
    if (element.closest('[hidden], [aria-hidden="true"], [inert]')) return false
    if (element.getClientRects().length === 0) return false
    return true
  })
}

export default function GuruAssignmentSubmissions({ assignment, onClose }) {
  const [submissions, setSubmissions] = useState([])
  const [pagination, setPagination] = useState(null)
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [downloading, setDownloading] = useState(null)
  const [expandedId, setExpandedId] = useState(null)

  const dialogRef = useRef(null)
  const closeButtonRef = useRef(null)
  const previousFocusRef = useRef(null)
  const onCloseRef = useRef(onClose)

  useEffect(() => {
    onCloseRef.current = onClose
  }, [onClose])

  useEffect(() => {
    previousFocusRef.current = document.activeElement
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    const focusTimer = window.requestAnimationFrame(() => {
      closeButtonRef.current?.focus()
    })

    return () => {
      window.cancelAnimationFrame(focusTimer)
      document.body.style.overflow = previousOverflow
      if (previousFocusRef.current instanceof HTMLElement) {
        previousFocusRef.current.focus()
      }
    }
  }, [])

  const closeDialog = useCallback(() => {
    onCloseRef.current?.()
  }, [])

  useEffect(() => {
    function handleKeyDown(event) {
      if (event.key === 'Escape') {
        event.preventDefault()
        closeDialog()
        return
      }

      if (event.key !== 'Tab') return

      const dialog = dialogRef.current
      const focusable = getFocusableElements(dialog)
      if (!focusable.length) {
        event.preventDefault()
        dialog?.focus()
        return
      }

      const first = focusable[0]
      const last = focusable[focusable.length - 1]

      if (event.shiftKey && (document.activeElement === first || !dialog?.contains(document.activeElement))) {
        event.preventDefault()
        last.focus()
      } else if (!event.shiftKey && (document.activeElement === last || !dialog?.contains(document.activeElement))) {
        event.preventDefault()
        first.focus()
      }
    }

    document.addEventListener('keydown', handleKeyDown)
    return () => document.removeEventListener('keydown', handleKeyDown)
  }, [closeDialog])

  const loadSubmissions = useCallback(async () => {
    setLoading(true)
    setError('')

    try {
      const response = await assignmentApi.getSubmissions(assignment.id, { page })
      const payload = response?.data
      const rows = Array.isArray(payload?.data)
        ? payload.data
        : Array.isArray(payload)
          ? payload
          : []

      setSubmissions(rows)
      setPagination(payload?.meta ?? null)
    } catch (err) {
      setError(getErrorMessage(err))
      setSubmissions([])
      setPagination(null)
    } finally {
      setLoading(false)
    }
  }, [assignment.id, page])

  useEffect(() => {
    loadSubmissions()
  }, [loadSubmissions])

  const summary = useMemo(() => {
    const result = { total: submissions.length, submitted: 0, late: 0, revision: 0, graded: 0 }

    submissions.forEach((submission) => {
      const status = getStatus(submission, assignment.deadline)
      if (status.type === 'submitted') result.submitted += 1
      if (status.type === 'late') result.late += 1
      if (status.type === 'revision') result.revision += 1
      if (status.type === 'graded') result.graded += 1
    })

    return result
  }, [submissions, assignment.deadline])

  async function downloadFile(file) {
    if (!file?.id) {
      setError('Lampiran ini menggunakan format lama dan tidak memiliki ID unduhan.')
      return
    }

    setDownloading(file.id)
    setError('')

    try {
      await assignmentApi.downloadSubmissionAttachment(file.id, file.name || 'lampiran-jawaban')
    } catch (err) {
      setError(getErrorMessage(err))
    } finally {
      setDownloading(null)
    }
  }

  const currentPage = Number(pagination?.current_page || page)
  const lastPage = Number(pagination?.last_page || 1)
  const totalRows = pagination?.total == null ? null : Number(pagination.total)

  return (
    <div
      className="submission-monitor-backdrop"
      onClick={(event) => {
        if (event.target === event.currentTarget) closeDialog()
      }}
    >
      <section
        ref={dialogRef}
        className="submission-monitor"
        role="dialog"
        aria-modal="true"
        aria-labelledby="submission-monitor-title"
        aria-describedby="submission-monitor-help"
        tabIndex={-1}
      >
        <header className="submission-monitor-header">
          <div className="submission-monitor-heading">
            <span className="submission-monitor-eyebrow">KEGIATAN MENGAJAR</span>
            <h2 id="submission-monitor-title">Pemantauan Pengumpulan</h2>
            <p className="submission-monitor-task-title">{assignment.judul || 'Tugas'}</p>
            <p className="submission-monitor-deadline">
              <span aria-hidden="true">◷</span>
              <span>Deadline: {formatDate(assignment.deadline)}</span>
            </p>
          </div>

          <button
            ref={closeButtonRef}
            type="button"
            className="submission-monitor-close"
            onClick={closeDialog}
            aria-label="Tutup pemantauan pengumpulan"
          >
            <span aria-hidden="true">×</span>
            <span>Tutup</span>
          </button>
        </header>

        <p className="submission-monitor-sr-only" id="submission-monitor-help">Periksa ringkasan pengumpulan, jawaban siswa, status, dan lampiran. Gunakan tombol tutup atau Escape untuk menutup dialog.</p>
        <div className="submission-monitor-scroll">
          {error && (
            <div className="submission-monitor-error" role="alert">
              <span>{error}</span>
              <button type="button" onClick={() => setError('')} aria-label="Tutup pesan kesalahan">
                ×
              </button>
            </div>
          )}

          <section className="submission-monitor-summary" aria-label="Ringkasan submission pada halaman aktif">
            <article className="submission-summary-card summary-total">
              <span>Data di halaman ini</span>
              <strong>{summary.total}</strong>
              <small>Submission dimuat</small>
            </article>
            <article className="submission-summary-card summary-submitted">
              <span>Sudah mengumpulkan</span>
              <strong>{summary.submitted}</strong>
              <small>Tepat waktu</small>
            </article>
            <article className="submission-summary-card summary-late">
              <span>Terlambat</span>
              <strong>{summary.late}</strong>
              <small>Lewat deadline</small>
            </article>
            <article className="submission-summary-card summary-revision">
              <span>Perlu revisi</span>
              <strong>{summary.revision}</strong>
              <small>Menunggu perbaikan</small>
            </article>
            <article className="submission-summary-card summary-graded">
              <span>Sudah dinilai</span>
              <strong>{summary.graded}</strong>
              <small>Penilaian selesai</small>
            </article>
          </section>

          <div className="submission-monitor-info">
            Ringkasan mengikuti submission yang dimuat pada halaman aktif
            {totalRows != null ? ` (total ${totalRows} submission tercatat)` : ''}.
            Data ini belum mewakili jumlah seluruh siswa di kelas dan tidak menghitung siswa yang belum mengumpulkan.
          </div>

          <div className="submission-monitor-list-heading">
            <div>
              <h3>Daftar pengumpulan</h3>
              <p>Periksa jawaban, status, waktu pengumpulan, dan lampiran siswa.</p>
            </div>
            <button
              type="button"
              className="submission-refresh-button"
              onClick={loadSubmissions}
              disabled={loading}
            >
              {loading ? 'Memuat…' : 'Muat ulang'}
            </button>
          </div>

          {loading ? (
            <div className="submission-monitor-state" role="status" aria-live="polite">
              <span className="submission-spinner" aria-hidden="true" />
              <strong>Memuat data pengumpulan</strong>
              <p>Mohon tunggu sebentar.</p>
            </div>
          ) : submissions.length === 0 ? (
            <div className="submission-monitor-state" role="status">
              <span className="submission-empty-icon" aria-hidden="true">—</span>
              <strong>Belum ada pengumpulan</strong>
              <p>Belum ada submission yang tercatat pada halaman ini.</p>
            </div>
          ) : (
            <div className="submission-monitor-list" aria-label="Kartu pengumpulan siswa">
              {submissions.map((submission) => {
                const status = getStatus(submission, assignment.deadline)
                const files = getFiles(submission)
                const isExpanded = expandedId === submission.id
                const studentName = submission.siswa?.nama_lengkap || `Siswa #${submission.siswa_id}`

                return (
                  <article className="submission-monitor-card" key={submission.id}>
                    <div className="submission-card-top">
                      <div className="submission-student-avatar" aria-hidden="true">
                        {(studentName.trim()[0] || 'S').toUpperCase()}
                      </div>
                      <div className="submission-student-info">
                        <h4>{studentName}</h4>
                        <p>NIS: {submission.siswa?.nis || '—'}</p>
                      </div>
                      <span className={`submission-status submission-status--${status.type}`}>
                        <span className="submission-status-dot" aria-hidden="true" />
                        {status.label}
                      </span>
                    </div>

                    <div className="submission-card-meta">
                      <span>Waktu pengumpulan</span>
                      <strong>{formatDate(submission.submitted_at)}</strong>
                    </div>

                    <section className="submission-answer" aria-label={`Jawaban ${studentName}`}>
                      <h5>Jawaban siswa</h5>
                      <p>
                        {submission.jawaban?.trim()
                          ? submission.jawaban
                          : 'Siswa tidak mengisi jawaban teks.'}
                      </p>
                    </section>

                    <section className="submission-files" aria-label={`Lampiran jawaban ${studentName}`}>
                      <div className="submission-files-heading">
                        <h5>Lampiran jawaban</h5>
                        <span>{files.length} {files.length === 1 ? 'file' : 'file'}</span>
                      </div>

                      {files.length === 0 ? (
                        <p className="submission-no-files">Tidak ada lampiran pada pengumpulan ini.</p>
                      ) : (
                        <ul>
                          {files.map((file, index) => {
                            const fileSize = formatFileSize(file.size)
                            return (
                              <li className="submission-file-row" key={file.id ?? `${submission.id}-${index}`}>
                                <div className="submission-file-icon" aria-hidden="true">
                                  {getFileExtension(file.name)}
                                </div>
                                <div className="submission-file-info">
                                  <strong title={file.name || 'Lampiran jawaban'}>
                                    {file.name || 'Lampiran jawaban'}
                                  </strong>
                                  <small>{fileSize || file.mime_type || 'File lampiran'}</small>
                                </div>
                                {file.id ? (
                                  <button
                                    type="button"
                                    className="submission-download-button"
                                    disabled={downloading === file.id}
                                    aria-busy={downloading === file.id}
                                    onClick={() => downloadFile(file)}
                                    aria-label={`Unduh lampiran ${file.name || 'jawaban siswa'}`}
                                  >
                                    {downloading === file.id ? 'Mengunduh…' : 'Unduh'}
                                  </button>
                                ) : (
                                  <span className="submission-legacy-label" title="File lama tidak memiliki ID unduhan">
                                    File lama
                                  </span>
                                )}
                              </li>
                            )
                          })}
                        </ul>
                      )}
                    </section>

                    {submission.catatan && (
                      <div className="submission-teacher-note">
                        <strong>Catatan</strong>
                        <p>{submission.catatan}</p>
                      </div>
                    )}

                    <button
                      type="button"
                      className="submission-detail-button"
                      aria-expanded={isExpanded}
                      aria-controls={`submission-extra-${submission.id}`}
                      onClick={() => setExpandedId(isExpanded ? null : submission.id)}
                    >
                      <span>{isExpanded ? 'Sembunyikan informasi' : 'Informasi pengumpulan'}</span>
                      <span aria-hidden="true">{isExpanded ? '−' : '+'}</span>
                    </button>

                    {isExpanded && (
                      <dl className="submission-extra-details" id={`submission-extra-${submission.id}`}>
                        <div>
                          <dt>ID pengumpulan</dt>
                          <dd>{submission.id}</dd>
                        </div>
                        <div>
                          <dt>Status sistem</dt>
                          <dd>{submission.status || '—'}</dd>
                        </div>
                        <div>
                          <dt>Terakhir diperbarui</dt>
                          <dd>{formatDate(submission.updated_at)}</dd>
                        </div>
                      </dl>
                    )}
                  </article>
                )
              })}
            </div>
          )}
        </div>

        {pagination && lastPage > 1 && (
          <nav className="submission-monitor-pagination" aria-label="Navigasi halaman pengumpulan">
            <button
              type="button"
              disabled={loading || currentPage <= 1}
              onClick={() => setPage((current) => Math.max(1, current - 1))}
            >
              Sebelumnya
            </button>
            <span aria-live="polite">
              Halaman <strong>{currentPage}</strong> dari <strong>{lastPage}</strong>
            </span>
            <button
              type="button"
              disabled={loading || currentPage >= lastPage}
              onClick={() => setPage((current) => Math.min(lastPage, current + 1))}
            >
              Berikutnya
            </button>
          </nav>
        )}

        <footer className="submission-monitor-footer">
          <span>
            {totalRows != null
              ? `Total ${totalRows} submission tercatat`
              : `Menampilkan ${submissions.length} submission pada halaman ini`}
          </span>
          <button type="button" className="submission-monitor-done" onClick={closeDialog}>
            Selesai
          </button>
        </footer>
      </section>
    </div>
  )
}
