import { useEffect, useState } from 'react'

import Button from '../../components/ui/Button'
import Modal from '../../components/ui/Modal'
import UserConfirmationPanel from './UserConfirmationPanel'
import { userActivationApi } from '../../services/userActivationApi'

import './UserActivationSlipModal.css'

function getMessage(error) {
  return error?.message ||
    'Permintaan gagal. Silakan coba lagi.'
}

function formatExpiry(value) {
  if (!value) return '-'

  const date = new Date(value)

  if (Number.isNaN(date.getTime())) {
    return '-'
  }

  return `${new Intl.DateTimeFormat('id-ID', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    timeZone: 'Asia/Jakarta',
  }).format(date)} WIB`
}

/**
 * Cetak slip di jendela terpisah.
 * Dashboard SIAKAD tidak ikut dicetak.
 */
function printActivationSlip() {
  const source = document.getElementById(
    'user-activation-print-area'
  )

  if (!source) {
    throw new Error(
      'Slip belum tersedia untuk dicetak.'
    )
  }

  const printWindow = window.open(
    '',
    '_blank',
    'width=860,height=900'
  )

  if (!printWindow) {
    throw new Error(
      'Jendela cetak diblokir. Izinkan pop-up untuk localhost.'
    )
  }

  const html = `<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <title>Slip Aktivasi Akun SIAKAD</title>
  <style>
    @page {
      size: A4 portrait;
      margin: 15mm;
    }

    * {
      box-sizing: border-box;
    }

    html, body {
      margin: 0;
      padding: 0;
    }

    body {
      background: #fff;
      color: #0f172a;
      font: 12px/1.5 Arial, Helvetica, sans-serif;
    }

    .users-activation-slip__paper {
      width: 100%;
      max-width: 180mm;
      margin: 0 auto;
      padding: 0;
      border: 0;
      background: #fff;
      break-inside: avoid;
      page-break-inside: avoid;
    }

    .users-activation-slip__paper-header {
      text-align: center;
      padding-bottom: 13px;
      border-bottom: 2px solid #123f64;
    }

    .users-activation-slip__paper-header h2 {
      margin: 0;
      color: #123f64;
      font-size: 24px;
    }

    .users-activation-slip__paper-header p {
      margin: 3px 0 10px;
    }

    .users-activation-slip__paper-header strong {
      font-size: 14px;
    }

    .users-activation-slip__paper-info {
      display: grid;
      grid-template-columns: 95px minmax(0, 1fr);
      gap: 7px 12px;
      padding: 18px 0;
    }

    .users-activation-slip__paper-info span {
      color: #64748b;
    }

    .users-activation-slip__paper-info strong {
      overflow-wrap: anywhere;
    }

    .users-activation-slip__code-label {
      margin: 0 0 8px;
      text-align: center;
      color: #475569;
    }

    .users-activation-slip__code {
      padding: 14px 10px;
      border: 1px dashed #94a3b8;
      border-radius: 8px;
      background: #f8fafc;
      text-align: center;
      font: 700 15px/1.6 Consolas, 'Courier New', monospace;
      overflow-wrap: anywhere;
    }

    .users-activation-slip__expiry {
      margin: 12px 0 18px;
      text-align: center;
    }

    .users-activation-slip__instructions {
      padding-top: 14px;
      border-top: 1px solid #e2e8f0;
    }

    .users-activation-slip__instructions ol {
      margin: 7px 0 9px;
      padding-left: 20px;
    }

    .users-activation-slip__instructions li {
      margin: 3px 0;
    }

    .users-activation-slip__instructions p {
      margin: 0;
      color: #475569;
    }

    @media print {
      body {
        print-color-adjust: exact;
        -webkit-print-color-adjust: exact;
      }
    }
  </style>
</head>
<body></body>
</html>`

  const printDocument = printWindow.document

  printDocument.open()
  printDocument.write(html)
  printDocument.close()

  printDocument.body.appendChild(
    source.cloneNode(true)
  )

  printWindow.addEventListener(
    'afterprint',
    () => {
      printWindow.close()
    },
    { once: true }
  )

  printWindow.setTimeout(() => {
    if (printWindow.closed) return

    printWindow.focus()
    printWindow.print()
  }, 350)
}

export default function UserActivationSlipModal({
  user,
  onClose,
}) {
  const [issuing, setIssuing] = useState(false)
  const [canceling, setCanceling] = useState(false)

  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [issued, setIssued] = useState(null)
  const [confirmation, setConfirmation] = useState(null)

  // Status kode berasal dari backend MySQL.
  const [hasActiveCode, setHasActiveCode] =
    useState(false)

  const [codeStatus, setCodeStatus] =
    useState('loading')

  const [refreshKey, setRefreshKey] = useState(0)

  const busy = issuing || canceling

  // =========================================
  // PERIKSA STATUS KODE SAAT MODAL DIBUKA
  // =========================================

  useEffect(() => {
    let ignore = false

    async function checkStatus() {
      setCodeStatus('loading')
      setHasActiveCode(false)

      try {
        const response =
          await userActivationApi.getCodeStatus(
            user.id
          )

        if (ignore) return

        const value =
          response?.data?.has_active_code

        if (typeof value !== 'boolean') {
          throw new Error(
            'Format status kode dari server tidak sesuai.'
          )
        }

        setHasActiveCode(value)
        setCodeStatus('ready')
      } catch {
        if (ignore) return

        setHasActiveCode(false)
        setCodeStatus('error')
      }
    }

    checkStatus()

    return () => {
      ignore = true
    }
  }, [user.id, refreshKey])

  // =========================================
  // TUTUP MODAL
  // =========================================

  function finishClose() {
    setConfirmation(null)
    setIssued(null)
    setError('')
    setNotice('')
    onClose()
  }

  function handleClose() {
    if (busy) return

    // Escape atau X pada panel konfirmasi = kembali.
    if (confirmation) {
      setConfirmation(null)
      return
    }

    // Kode sekali pakai tidak dapat ditampilkan kembali.
    if (issued) {
      setConfirmation('close')
      return
    }

    finishClose()
  }

  // =========================================
  // TERBITKAN KODE AKTIVASI
  // =========================================

  function requestIssueConfirmation() {
    if (busy || issued || codeStatus !== 'ready') return
    setError('')
    setConfirmation('issue')
  }

  async function handleIssue() {
    if (
      busy ||
      issued ||
      codeStatus !== 'ready' ||
      confirmation !== 'issue'
    ) {
      return
    }

    setConfirmation(null)
    setIssuing(true)
    setError('')
    setNotice('')

    try {
      const response =
        await userActivationApi.issueCode(user.id)

      const data = response?.data

      if (
        !data?.activation_code ||
        !data?.expires_at ||
        Number(data.user_id) !== Number(user.id)
      ) {
        throw new Error(
          'Respons penerbitan kode tidak sesuai. ' +
          'Periksa status akun sebelum mencoba lagi.'
        )
      }

      setIssued({
        name: data.name,
        username: data.username,
        role_code: data.role_code,
        activation_code: data.activation_code,
        expires_at: data.expires_at,
      })

      // Kode sudah diterbitkan:
      // tombol pembatalan langsung muncul.
      setHasActiveCode(true)
      setCodeStatus('ready')
    } catch (issueError) {
      setError(getMessage(issueError))

      // Jika respons gagal setelah backend
      // memproses request, periksa ulang status.
      setRefreshKey((current) => current + 1)
    } finally {
      setIssuing(false)
    }
  }

  // =========================================
  // BATALKAN KODE AKTIVASI
  // =========================================

  function requestCancelConfirmation() {
    if (busy || !hasActiveCode || codeStatus !== 'ready') return
    setError('')
    setConfirmation('cancel')
  }

  async function handleCancel() {
    if (
      busy ||
      !hasActiveCode ||
      codeStatus !== 'ready' ||
      confirmation !== 'cancel'
    ) {
      return
    }

    setConfirmation(null)
    setCanceling(true)
    setError('')
    setNotice('')

    try {
      const response =
        await userActivationApi.cancelCode(user.id)

      const revoked = response?.data?.revoked

      if (typeof revoked !== 'boolean') {
        throw new Error(
          'Respons pembatalan dari server tidak sesuai.'
        )
      }

      // Hapus kode dari memori React.
      setIssued(null)

      // Sembunyikan tombol pembatalan.
      setHasActiveCode(false)
      setCodeStatus('ready')

      setNotice(
        revoked
          ? 'Kode aktivasi berhasil dibatalkan. ' +
            'Slip lama tidak berlaku. ' +
            'Anda dapat menerbitkan kode baru.'
          : 'Kode sudah tidak tersedia atau ' +
            'tidak dapat dibatalkan.'
      )
    } catch (cancelError) {
      setError(getMessage(cancelError))

      // Pastikan status tidak mengandalkan
      // data lama ketika ada kegagalan.
      setRefreshKey((current) => current + 1)
    } finally {
      setCanceling(false)
    }
  }

  // =========================================
  // CETAK SLIP
  // =========================================

  function handlePrint() {
    if (!issued || busy) return

    setError('')

    try {
      printActivationSlip()
    } catch (printError) {
      setError(getMessage(printError))
    }
  }

  // =========================================
  // RENDER
  // =========================================

  return (
    <Modal
      open
      title="Slip Aktivasi Akun"
      description="Terbitkan dan kelola kode aktivasi akun Guru/Siswa."
      onClose={handleClose}
      size="large"
      footer={
        confirmation ? (
          <>
            <Button
              type="button"
              variant="secondary"
              disabled={busy}
              onClick={() => setConfirmation(null)}
            >
              Kembali
            </Button>
            {confirmation === 'issue' && (
              <Button
                type="button"
                disabled={busy || codeStatus !== 'ready'}
                onClick={handleIssue}
              >
                Ya, Terbitkan Kode
              </Button>
            )}
            {confirmation === 'cancel' && (
              <button
                type="button"
                className="users-activation-slip__cancel-button"
                disabled={busy || codeStatus !== 'ready'}
                onClick={handleCancel}
              >
                Ya, Batalkan Kode
              </button>
            )}
            {confirmation === 'close' && (
              <Button
                type="button"
                disabled={busy}
                onClick={finishClose}
              >
                Ya, Tutup Slip
              </Button>
            )}
          </>
        ) : (
          <>
            <Button
              type="button"
              variant="secondary"
              onClick={handleClose}
              disabled={busy}
            >
              Tutup
            </Button>

            {!issued ? (
              <Button
                type="button"
                loading={issuing}
                disabled={busy || codeStatus !== 'ready'}
                onClick={requestIssueConfirmation}
              >
                {issuing
                  ? 'Menerbitkan...'
                  : 'Terbitkan Kode Aktivasi'}
              </Button>
            ) : (
              <Button
                type="button"
                onClick={handlePrint}
                disabled={busy}
              >
                Cetak Slip
              </Button>
            )}
          </>
        )
      }
    >
      <div className="users-activation-slip">
        {confirmation ? (
          <UserConfirmationPanel
            tone={confirmation === 'cancel' ? 'danger' : 'warning'}
            eyebrow={confirmation === 'close' ? 'Perhatian' : 'Konfirmasi tindakan'}
            title={
              confirmation === 'issue'
                ? 'Terbitkan kode aktivasi baru?'
                : confirmation === 'cancel'
                  ? 'Batalkan kode aktivasi?'
                  : 'Tutup slip aktivasi sekarang?'
            }
            description={
              confirmation === 'issue'
                ? 'Kode baru akan dibuat untuk pengguna ini. Kode sebelumnya, jika ada, akan langsung tidak berlaku.'
                : confirmation === 'cancel'
                  ? 'Kode yang sudah diterbitkan akan dinonaktifkan dan slip lama tidak bisa digunakan.'
                  : 'Kode aktivasi hanya ditampilkan sekali. Pastikan slip sudah dicetak atau disimpan dengan aman sebelum melanjutkan.'
            }
            details={[
              { label: 'Nama pengguna', value: user.name || '-' },
              { label: 'Username', value: user.username || '-' },
              { label: 'Role', value: user.role?.name || user.role?.code || '-' },
            ]}
            note={
              confirmation === 'issue'
                ? 'Kode baru bersifat rahasia, berlaku 7 hari dan hanya muncul sekali setelah penerbitan.'
                : confirmation === 'cancel'
                  ? 'Akun pengguna tidak dihapus dan tetap belum aktivasi. Anda dapat menerbitkan kode baru setelah pembatalan.'
                  : 'Setelah modal ditutup, kode yang baru diterbitkan tidak bisa dilihat ulang. TU perlu menerbitkan ulang kode jika slip hilang.'
            }
          />
        ) : (
          <>

        {/* INFORMASI PENYERAHAN */}

        <div className="users-activation-slip__notice">
          <strong>Penyerahan individual</strong>

          <p>
            Serahkan slip hanya kepada pemilik
            akun setelah identitasnya diverifikasi.
            Jangan kirim kode melalui grup kelas
            atau menyimpan screenshot secara terbuka.
          </p>
        </div>

        {/* PENGELOLAAN KODE */}

        <div className="users-activation-slip__manage">
          <div>
            <strong>Pengelolaan kode oleh TU</strong>

            {codeStatus === 'loading' ? (
              <p>Memeriksa status kode aktivasi...</p>
            ) : codeStatus === 'error' ? (
              <p>
                Status kode belum dapat diperiksa.
                Coba periksa ulang.
              </p>
            ) : hasActiveCode ? (
              <p>
                Akun memiliki kode aktivasi
                yang masih berlaku.
                TU dapat membatalkannya jika diperlukan.
              </p>
            ) : (
              <p>
                Belum ada kode aktivasi aktif
                untuk akun ini. Anda dapat
                menerbitkan kode baru.
              </p>
            )}
          </div>

          {/* TAMPIL HANYA JIKA ADA KODE AKTIF */}

          {codeStatus === 'ready' &&
            hasActiveCode && (
              <button
                type="button"
                className="users-activation-slip__cancel-button"
                onClick={requestCancelConfirmation}
                disabled={busy}
              >
                {canceling
                  ? 'Membatalkan...'
                  : 'Batalkan Kode Aktivasi'}
              </button>
            )}

          {/* JIKA PEMERIKSAAN GAGAL */}

          {codeStatus === 'error' && (
            <Button
              type="button"
              variant="secondary"
              disabled={busy}
              onClick={() =>
                setRefreshKey(
                  (current) => current + 1
                )
              }
            >
              Periksa Ulang
            </Button>
          )}
        </div>

        {/* IDENTITAS PENGGUNA */}

        {!issued && (
          <div className="users-activation-slip__target">
            <span>
              Pengguna yang akan menerima slip
            </span>

            <strong>
              {user.name || '-'}
            </strong>

            <code>{user.username}</code>

            <small>
              Peran:{' '}
              {user.role?.name ||
                user.role?.code ||
                '-'}
            </small>

            <p>
              Kode berlaku 7 hari, hanya dapat
              digunakan sekali, dan penerbitan
              ulang membatalkan kode sebelumnya.
            </p>
          </div>
        )}

        {/* PESAN INFORMASI */}

        {notice && (
          <div
            className="users-activation-slip__success"
            role="status"
          >
            {notice}
          </div>
        )}

        {/* PESAN ERROR */}

        {error && (
          <div
            className="users-activation-slip__error"
            role="alert"
          >
            {error}
          </div>
        )}

        {/* SLIP SETELAH PENERBITAN */}

        {issued && (
          <>
            <div
              className="users-activation-slip__success"
              role="status"
            >
              Kode berhasil diterbitkan.
              Cetak slip sekarang; kode asli tidak
              dapat dilihat ulang setelah modal ditutup.
            </div>

            <section
              id="user-activation-print-area"
              className="users-activation-slip__paper"
              aria-label="Slip kode aktivasi pengguna"
            >
              <div className="users-activation-slip__paper-header">
                <h2>SIAKAD</h2>

                <p>
                  SMA Negeri 1 V Koto Timur
                </p>

                <strong>
                  SLIP AKTIVASI AKUN
                </strong>
              </div>

              <div className="users-activation-slip__paper-info">
                <span>Nama</span>
                <strong>{issued.name}</strong>

                <span>Username</span>
                <strong>{issued.username}</strong>

                <span>Role</span>
                <strong>{issued.role_code}</strong>
              </div>

              <p className="users-activation-slip__code-label">
                Kode aktivasi sekali pakai
              </p>

              <div className="users-activation-slip__code">
                {issued.activation_code}
              </div>

              <p className="users-activation-slip__expiry">
                Berlaku sampai:{' '}
                <strong>
                  {formatExpiry(issued.expires_at)}
                </strong>
              </p>

              <div className="users-activation-slip__instructions">
                <strong>
                  Petunjuk untuk pengguna
                </strong>

                <ol>
                  <li>
                    Buka halaman Aktivasi Akun
                    SIAKAD yang diberikan sekolah.
                  </li>

                  <li>
                    Masukkan username dan kode
                    aktivasi dari slip ini.
                  </li>

                  <li>
                    Buat password pribadi yang
                    kuat dan simpan sendiri.
                  </li>

                  <li>
                    Setelah aktivasi berhasil,
                    login menggunakan password pribadi.
                  </li>
                </ol>

                <p>
                  Rahasiakan slip ini. Jika hilang
                  atau kedaluwarsa, hubungi
                  Tata Usaha untuk kode baru.
                </p>
              </div>
            </section>
          </>
        )}
          </>
        )}
      </div>
    </Modal>
  )
}
