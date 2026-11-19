import { useState } from 'react'
import Button from '../../components/ui/Button'
import Modal from '../../components/ui/Modal'
import UserConfirmationPanel from './UserConfirmationPanel'
import { userImportApi } from '../../services/userImportApi'
import './UserImportButton.css'

const MAX_FILE_SIZE = 2 * 1024 * 1024
const ALLOWED_IMPORT_ROLES = ['GURU', 'SISWA']

function getErrorMessage(error, fallback) {
  return (
    error?.response?.data?.message ||
    error?.data?.message ||
    error?.message ||
    fallback
  )
}

function formatFileSize(bytes) {
  return `${(bytes / 1024).toFixed(1)} KB`
}

export default function UserImportButton({ onImported }) {
  const [open, setOpen] = useState(false)
  const [file, setFile] = useState(null)
  const [preview, setPreview] = useState(null)
  const [importResult, setImportResult] = useState(null)
  const [loading, setLoading] = useState(false)
  const [importing, setImporting] = useState(false)
  const [downloading, setDownloading] = useState(false)
  const [error, setError] = useState('')
  const [confirmingImport, setConfirmingImport] = useState(false)

  const busy = loading || importing || downloading

  function openModal() {
    setConfirmingImport(false)
    setFile(null)
    setPreview(null)
    setImportResult(null)
    setError('')
    setOpen(true)
  }

  function closeModal() {
    if (busy) return

    // Tombol X / Escape saat konfirmasi kembali ke preview.
    if (confirmingImport) {
      setConfirmingImport(false)
      return
    }

    setOpen(false)
    setFile(null)
    setPreview(null)
    setImportResult(null)
    setError('')
  }

  function handleFileChange(event) {
    const selectedFile = event.target.files?.[0]

    setConfirmingImport(false)
    setFile(null)
    setPreview(null)
    setImportResult(null)
    setError('')

    if (!selectedFile) return

    const fileName = selectedFile.name.toLowerCase()

    if (!fileName.endsWith('.xlsx')) {
      setError('Format file harus Excel .xlsx.')
      return
    }

    if (selectedFile.size === 0) {
      setError('File Excel tidak boleh kosong.')
      return
    }

    if (selectedFile.size > MAX_FILE_SIZE) {
      setError('Ukuran file Excel maksimal 2 MB.')
      return
    }

    setFile(selectedFile)
  }

  async function handleDownloadTemplate() {
    if (busy) return

    setDownloading(true)
    setError('')

    try {
      const blob = await userImportApi.downloadTemplate()
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')

      link.href = url
      link.download = 'template-import-pengguna.xlsx'

      document.body.appendChild(link)
      link.click()
      link.remove()

      window.setTimeout(() => {
        URL.revokeObjectURL(url)
      }, 10000)
    } catch (downloadError) {
      setError(
        getErrorMessage(
          downloadError,
          'Gagal mengunduh template Excel.'
        )
      )
    } finally {
      setDownloading(false)
    }
  }

  async function handlePreview() {
    if (!file || busy || importResult || confirmingImport) return

    setLoading(true)
    setError('')
    setPreview(null)

    try {
      const response = await userImportApi.preview(file)

      if (
        !response?.data?.summary ||
        !Array.isArray(response?.data?.rows)
      ) {
        throw new Error(
          'Format hasil preview dari server tidak sesuai.'
        )
      }

      setPreview(response.data)
    } catch (previewError) {
      setError(
        getErrorMessage(
          previewError,
          'Gagal memeriksa isi file Excel.'
        )
      )
    } finally {
      setLoading(false)
    }
  }

  const summary = preview?.summary ?? null
  const rows = preview?.rows ?? []

  const hasValidPreview =
    Boolean(summary) &&
    rows.length > 0 &&
    summary.total_rows === rows.length &&
    summary.invalid_rows === 0 &&
    summary.valid_rows === rows.length &&
    rows.every((row) => row.valid === true)

  const hasRestrictedRole = rows.some(
    (row) => !ALLOWED_IMPORT_ROLES.includes(row.role_code)
  )

  const canImport =
    Boolean(file) &&
    hasValidPreview &&
    !hasRestrictedRole &&
    !busy &&
    !importResult

  function requestImportConfirmation() {
    if (!canImport) return
    setError('')
    setConfirmingImport(true)
  }

  async function handleImport() {
    // Request penyimpanan hanya berjalan setelah pengguna
    // menekan tombol "Ya, Import" pada panel konfirmasi.
    if (!canImport || !confirmingImport) return

    setImporting(true)
    setError('')

    try {
      // Laravel memvalidasi ulang file asli.
      const response = await userImportApi.importUsers(file)
      const result = response?.data

      if (
        !result ||
        !Number.isInteger(result.created_count) ||
        !Array.isArray(result.users)
      ) {
        throw new Error(
          'Respons import dari server tidak sesuai. ' +
          'Periksa daftar pengguna sebelum mencoba ulang.'
        )
      }

      setImportResult(result)
      setPreview(null)
      setConfirmingImport(false)

      // Refresh daftar pengguna pada UsersPage.
      // Tidak perlu reload seluruh halaman browser.
      onImported?.(result)
    } catch (importError) {
      setConfirmingImport(false)
      setError(
        getErrorMessage(
          importError,
          'Import gagal. Periksa kondisi database sebelum mengulangi proses.'
        )
      )
    } finally {
      setImporting(false)
    }
  }

  return (
    <>
      <Button
        type="button"
        variant="outline"
        onClick={openModal}
      >
        Import Excel
      </Button>

      <Modal
        open={open}
        title="Import Pengguna dari Excel"
        description={
          importResult
            ? 'Akun telah disimpan ke database.'
            : 'Upload file Excel dan periksa datanya sebelum disimpan.'
        }
        onClose={closeModal}
        size="user-import"
        footer={
          confirmingImport ? (
            <>
              <Button
                type="button"
                variant="secondary"
                disabled={busy}
                onClick={() => setConfirmingImport(false)}
              >
                Kembali ke Preview
              </Button>
              <Button
                type="button"
                disabled={!canImport}
                loading={importing}
                onClick={handleImport}
              >
                {importing
                  ? 'Menyimpan Akun...'
                  : `Ya, Import ${rows.length} Akun`}
              </Button>
            </>
          ) : (
            <>
              <Button
                type="button"
                variant="secondary"
                disabled={busy}
                onClick={closeModal}
              >
                {importResult ? 'Selesai' : 'Tutup'}
              </Button>

              {!importResult && (
                <Button
                  type="button"
                  disabled={!file || busy}
                  loading={loading}
                  onClick={handlePreview}
                >
                  {loading ? 'Memeriksa...' : 'Periksa File'}
                </Button>
              )}

              {canImport && (
                <Button
                  type="button"
                  disabled={busy}
                  onClick={requestImportConfirmation}
                >
                  Konfirmasi Import {rows.length} Akun
                </Button>
              )}
            </>
          )
        }
      >
        <div className="users-import">
          {confirmingImport ? (
            <UserConfirmationPanel
              tone="warning"
              eyebrow="Konfirmasi akhir"
              title="Simpan akun pengguna baru?"
              description={`Anda akan membuat ${rows.length} akun baru di database SIAKAD. Pastikan data dan role pengguna sudah benar.`}
              details={[
                { label: 'File Excel', value: file?.name || '-' },
                { label: 'Akun valid', value: `${rows.length} akun` },
                { label: 'Role import', value: 'Guru & Siswa' },
              ]}
              note="Seluruh akun hasil import wajib aktivasi menggunakan slip dari TU. Laravel akan memvalidasi ulang file sebelum menyimpan data."
            />
          ) : (
            <>
          <div className="users-import__notice">
            <strong>
              {importResult
                ? 'Import Selesai'
                : 'Import Pengguna SIAKAD'}
            </strong>

            <p>
              {importResult
                ? (
                  'Akun sudah tersimpan. ' +
                  'Pengguna harus melakukan aktivasi ' +
                  'sebelum dapat login.'
                )
                : (
                  'Periksa file terlebih dahulu. ' +
                  'Data hanya disimpan setelah TU ' +
                  'menekan Konfirmasi Import.'
                )
              }
            </p>
          </div>

          {!importResult && (
            <>
              <div className="users-import__template">
                <div>
                  <h3>Template Excel Resmi</h3>
                  <p>
                    Gunakan template SIAKAD agar
                    struktur kolom sesuai.
                    Import massal hanya untuk
                    akun Guru dan Siswa.
                  </p>
                </div>

                <Button
                  type="button"
                  variant="outline"
                  loading={downloading}
                  disabled={busy}
                  onClick={handleDownloadTemplate}
                >
                  Download Template
                </Button>
              </div>

              <div className="users-import__upload">
                <label htmlFor="user-import-file">
                  Pilih File Excel
                </label>

                <input
                  id="user-import-file"
                  type="file"
                  accept=".xlsx"
                  disabled={busy}
                  onClick={(event) => {
                    event.currentTarget.value = ''
                  }}
                  onChange={handleFileChange}
                />

                <small>
                  Format .xlsx, maksimal 2 MB
                  dan 1.000 baris data.
                </small>

                {file && (
                  <div className="users-import__selected-file">
                    <span aria-hidden="true">📄</span>

                    <div>
                      <strong>{file.name}</strong>
                      <small>
                        {formatFileSize(file.size)}
                      </small>
                    </div>
                  </div>
                )}
              </div>
            </>
          )}

          {error && (
            <div
              className="users-import__error"
              role="alert"
            >
              {error}
            </div>
          )}

          {loading && (
            <p
              className="users-import__loading"
              role="status"
            >
              Sedang memvalidasi file Excel...
            </p>
          )}

          {importing && (
            <p
              className="users-import__loading"
              role="status"
            >
              Sedang menyimpan seluruh akun
              dalam transaksi database.
              Jangan menutup halaman.
            </p>
          )}

          {preview && summary && !importResult && (
            <div className="users-import__preview">
              <div className="users-import__preview-header">
                <h3>Hasil Validasi Excel</h3>
                <p>
                  Data ini belum disimpan ke database.
                </p>
              </div>

              <div className="users-import__stats">
                <div className="users-import__stat">
                  <span>Total Data</span>
                  <strong>{summary.total_rows}</strong>
                </div>

                <div className="users-import__stat users-import__stat--valid">
                  <span>Data Valid</span>
                  <strong>{summary.valid_rows}</strong>
                </div>

                <div className="users-import__stat users-import__stat--invalid">
                  <span>Data Bermasalah</span>
                  <strong>{summary.invalid_rows}</strong>
                </div>
              </div>

              <div className="users-import__table-wrapper">
                <table className="users-import__table">
                  <thead>
                    <tr>
                      <th scope="col">Baris</th>
                      <th scope="col">Pengguna</th>
                      <th scope="col">Role</th>
                      <th scope="col">Status</th>
                      <th scope="col">Keterangan</th>
                    </tr>
                  </thead>

                  <tbody>
                    {rows.map((row) => (
                      <tr key={row.row}>
                        <td>{row.row}</td>

                        <td>
                          <div className="users-import__identity">
                            <strong>{row.name || '-'}</strong>
                            <span>{row.username || '-'}</span>
                            <small>
                              {row.email || 'Tanpa email'}
                            </small>
                          </div>
                        </td>

                        <td>{row.role_code || '-'}</td>

                        <td>
                          <span
                            className={
                              row.valid
                                ? 'users-import__status users-import__status--valid'
                                : 'users-import__status users-import__status--invalid'
                            }
                          >
                            {row.valid ? 'Valid' : 'Bermasalah'}
                          </span>
                        </td>

                        <td>
                          {row.valid ? (
                            <span className="users-import__valid-text">
                              Lolos validasi awal
                            </span>
                          ) : (
                            <ul className="users-import__row-errors">
                              {(row.errors || []).map(
                                (message, index) => (
                                  <li key={index}>{message}</li>
                                )
                              )}
                            </ul>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              {hasRestrictedRole && (
                <div className="users-import__result-warning">
                  Import massal hanya mendukung role
                  GURU dan SISWA. Akun TU dan
                  KEPALA_SEKOLAH tidak dapat dibuat
                  melalui fitur ini.
                </div>
              )}

              {summary.invalid_rows > 0 ? (
                <div className="users-import__result-warning">
                  Terdapat {summary.invalid_rows} baris
                  bermasalah. Perbaiki Excel dan upload ulang
                  sebelum menyimpan akun.
                </div>
              ) : !hasRestrictedRole ? (
                  <div className="users-import__result-success">
                    Seluruh {summary.valid_rows} data pengguna
                    telah lolos pemeriksaan dan siap diimpor.
                    Klik Konfirmasi Import untuk melanjutkan
                    ke tahap persetujuan. Data belum disimpan
                    ke sistem SIAKAD.
                  </div>
              ) : null}
            </div>
          )}

          {importResult && (
            <div className="users-import__preview">
              <div className="users-import__result-success">
                Berhasil membuat{' '}
                <strong>{importResult.created_count}</strong>{' '}
                akun baru di database SIAKAD.
                Semua akun hasil import memerlukan aktivasi.
              </div>

              <div className="users-import__preview-header">
                <h3>Daftar Akun yang Dibuat</h3>
                <p>
                  Berikut ringkasan akun hasil import.
                  Password tidak ditampilkan.
                </p>
              </div>

              <div className="users-import__table-wrapper">
                <table className="users-import__table">
                  <thead>
                    <tr>
                      <th scope="col">ID</th>
                      <th scope="col">Nama</th>
                      <th scope="col">Username</th>
                      <th scope="col">Role</th>
                      <th scope="col">Aktivasi</th>
                    </tr>
                  </thead>

                  <tbody>
                    {importResult.users
                      .slice(0, 20)
                      .map((user) => (
                        <tr key={user.id}>
                          <td>{user.id}</td>
                          <td>{user.name}</td>
                          <td>{user.username}</td>
                          <td>{user.role_code}</td>

                          <td>
                            <span className="users-import__status users-import__status--invalid">
                              Belum Aktivasi
                            </span>
                          </td>
                        </tr>
                      ))}
                  </tbody>
                </table>
              </div>

              {importResult.users.length > 20 && (
                <p className="users-import__loading">
                  Menampilkan 20 akun pertama dari{' '}
                  {importResult.users.length} akun yang dibuat.
                </p>
              )}

              <div className="users-import__notice">
                <strong>Langkah Selanjutnya</strong>
                <p>
                  TU perlu menerbitkan slip kode aktivasi
                  individual untuk setiap akun baru.
                  Buka menu Aksi pengguna dan pilih
                  Terbitkan Slip Aktivasi untuk mencetak
                  slip secara individual.
                </p>
              </div>
            </div>
          )}
            </>
          )}
        </div>
      </Modal>
    </>
  )
}
