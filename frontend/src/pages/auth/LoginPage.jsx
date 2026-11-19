import { useEffect, useRef, useState } from 'react'
import {
  Link,
  Navigate,
  useLocation,
  useNavigate,
} from 'react-router-dom'

import { useAuth } from '../../context/useAuth'
import { apiClient, setAuthToken } from '../../services/apiClient'
import './LoginPage.css'

const GOOGLE_CLIENT_ID =
  import.meta.env.VITE_GOOGLE_CLIENT_ID?.trim() || ''

const GOOGLE_SCRIPT_URL =
  'https://accounts.google.com/gsi/client'

let googleScriptPromise = null

function ArrowIcon({ direction = 'right' }) {
  return (
    <svg
      width="18"
      height="18"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.9"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      className={direction === 'left' ? 'login-icon--left' : ''}
    >
      <path d="M5 12h14" />
      <path d="m13 6 6 6-6 6" />
    </svg>
  )
}

function LockIcon() {
  return (
    <svg
      width="17"
      height="17"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
    >
      <rect x="4" y="10" width="16" height="11" rx="2" />
      <path d="M8 10V7a4 4 0 0 1 8 0v3" />
    </svg>
  )
}

function loadGoogleScript() {
  if (window.google?.accounts?.id) {
    return Promise.resolve()
  }

  if (googleScriptPromise) {
    return googleScriptPromise
  }

  googleScriptPromise = new Promise((resolve, reject) => {
    const existing = [...document.scripts].find(
      (script) => script.src === GOOGLE_SCRIPT_URL
    )

    const script =
      existing || document.createElement('script')

    function onLoad() {
      if (window.google?.accounts?.id) {
        resolve()
      } else {
        reject(new Error('Layanan Google belum siap. Coba muat ulang halaman.'))
      }
    }

    function onError() {
      reject(new Error('Gagal memuat Google. Periksa koneksi internet.'))
    }

    script.addEventListener('load', onLoad, { once: true })
    script.addEventListener('error', onError, { once: true })

    if (!existing) {
      script.src = GOOGLE_SCRIPT_URL
      script.async = true
      script.defer = true
      script.dataset.siakadGoogleLogin = 'true'
      document.head.appendChild(script)
    }
  }).catch((error) => {
    googleScriptPromise = null
    document
      .querySelector('script[data-siakad-google-login]')
      ?.remove()
    throw error
  })

  return googleScriptPromise
}

function getPasswordLoginError(error) {
  if (error?.status === 401) {
    return 'Username atau password tidak sesuai.'
  }

  if (error?.status === 403) {
    return 'Akun belum aktif atau belum diaktivasi. Hubungi Tata Usaha bila memerlukan bantuan.'
  }

  if (error?.status === 422) {
    return 'Periksa kembali username dan password.'
  }

  if (error?.status === 429) {
    return 'Terlalu banyak percobaan masuk. Coba lagi setelah beberapa saat.'
  }

  return 'Tidak dapat menghubungi server SIAKAD. Periksa koneksi lalu coba lagi.'
}

function getGoogleLoginError(error) {
  if (error?.status === 401) {
    return 'Google belum terhubung dengan akun SIAKAD. Masuk menggunakan username dan password, lalu tautkan Google melalui Profil Saya.'
  }

  if (error?.status === 403) {
    return 'Akun SIAKAD tidak aktif, belum diaktivasi, atau tidak memiliki akses.'
  }

  if (error?.status === 422) {
    return 'Verifikasi Google tidak valid atau telah kedaluwarsa. Silakan pilih akun Google lagi.'
  }

  if (error?.status === 429) {
    return 'Terlalu banyak percobaan Google Login. Silakan coba lagi nanti.'
  }

  if (error?.status === 503) {
    return 'Verifikasi Google sedang tidak tersedia. Gunakan username dan password untuk sementara.'
  }

  return error?.message || 'Login Google gagal. Periksa koneksi dan coba kembali.'
}

function getGooglePasswordRecoveryError(error) {
  if (error?.status === 401 || error?.status === 403) {
    return 'Google ini tidak tertaut ke akun SIAKAD yang memenuhi syarat. Hubungi Tata Usaha.'
  }

  if (error?.status === 422) {
    return 'Verifikasi Google atau challenge tidak valid atau kedaluwarsa. Mulai ulang verifikasi Google.'
  }

  if (error?.status === 429) {
    return 'Terlalu banyak percobaan. Coba kembali setelah beberapa saat.'
  }

  if (error?.status === 503) {
    return 'Layanan pemulihan sementara tidak tersedia. Silakan coba lagi nanti.'
  }

  return 'Password belum berhasil diperbarui. Periksa koneksi dan coba kembali.'
}

function getPasswordGrantError(error) {
  if (error?.status === 401 || error?.status === 403) {
    return 'Akun tidak lagi memenuhi persyaratan pemulihan. Hubungi Tata Usaha.'
  }

  if (error?.status === 422) {
    return 'Izin reset sudah digunakan, tidak valid, atau kedaluwarsa. Verifikasi Google kembali.'
  }

  if (error?.status === 429) {
    return 'Terlalu banyak percobaan. Silakan coba kembali setelah beberapa saat.'
  }

  if (error?.status === 503) {
    return 'Layanan pemulihan sementara tidak tersedia. Silakan coba lagi nanti.'
  }

  return 'Password belum berhasil diperbarui. Mulai ulang verifikasi Google sebelum mencoba lagi.'
}

function getChallengeError(error) {
  if (error?.status === 429) {
    return 'Terlalu banyak permintaan verifikasi. Silakan coba lagi nanti.'
  }

  if (error?.status === 503) {
    return 'Layanan verifikasi sementara tidak tersedia. Silakan coba lagi nanti.'
  }

  return 'Gagal menyiapkan verifikasi Google. Periksa koneksi dan coba kembali.'
}

function isStrongPassword(value) {
  return value.length >= 12 &&
    /[a-z]/.test(value) &&
    /[A-Z]/.test(value) &&
    /[0-9]/.test(value) &&
    /[^A-Za-z0-9\s]/.test(value)
}

function getGoogleRecoveryError(error) {
  if (error?.status === 401) {
    return 'Akun Google tersebut belum ditautkan ke akun SIAKAD yang dapat dipulihkan. Hubungi Tata Usaha jika membutuhkan bantuan.'
  }

  if (error?.status === 403) {
    return 'Akun sekolah tidak memenuhi persyaratan pemulihan. Hubungi Tata Usaha.'
  }

  if (error?.status === 422) {
    return 'Verifikasi Google tidak valid atau telah kedaluwarsa. Silakan coba lagi.'
  }

  if (error?.status === 429) {
    return 'Terlalu banyak percobaan pemulihan. Silakan coba lagi nanti.'
  }

  if (error?.status === 503) {
    return 'Verifikasi Google sementara tidak tersedia. Silakan coba lagi nanti.'
  }

  return 'Pemulihan username belum berhasil. Periksa koneksi dan coba kembali.'
}

// Styling khusus fitur pemulihan dibuat lokal agar file CSS login
// yang sudah disetujui tidak perlu diubah pada tahap ini.
const recoveryStyles = {
  backButton: {
    appearance: 'none',
    border: 0,
    background: 'transparent',
    cursor: 'pointer',
    fontFamily: 'inherit',
    textAlign: 'left',
  },
  entry: {
    border: 0,
    background: 'transparent',
    color: '#175780',
    font: 'inherit',
    fontWeight: 750,
    textDecoration: 'underline',
    textUnderlineOffset: '3px',
    cursor: 'pointer',
  },
  helper: {
    padding: '16px 18px',
    border: '1px solid #dbe7f1',
    borderRadius: '12px',
    background: '#f5f9fd',
    fontSize: '12px',
    lineHeight: 1.8,
    color: '#4d657a',
  },
  success: {
    padding: '22px',
    border: '1px solid #b9e5cf',
    borderRadius: '14px',
    background: '#f1faf5',
    display: 'grid',
    gap: '12px',
  },
  username: {
    padding: '14px 16px',
    border: '1px solid #cbded7',
    borderRadius: '10px',
    background: '#fff',
    fontSize: '22px',
    fontWeight: 750,
    letterSpacing: '-0.02em',
    color: '#153a4b',
    overflowWrap: 'anywhere',
  },
}

function safeDestination(pathname) {
  // Cegah redirect ke URL eksternal atau area publik
  // melalui state navigasi yang tidak diharapkan.
  return typeof pathname === 'string' &&
    (pathname === '/app' || pathname.startsWith('/app/'))
    ? pathname
    : '/app/dashboard'
}

export default function LoginPage() {
  const navigate = useNavigate()
  const location = useLocation()
  const { status, login } = useAuth()

  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [googleSubmitting, setGoogleSubmitting] = useState(false)
  const [mode, setMode] = useState('login')
  const [recoveredUsername, setRecoveredUsername] = useState('')
  const [resetPassword, setResetPassword] = useState('')
  const [resetPasswordConfirmation, setResetPasswordConfirmation] = useState('')
  const [showResetPassword, setShowResetPassword] = useState(false)
  const [passwordChallenge, setPasswordChallenge] = useState(null)
  // Reset grant bersifat rahasia: hanya disimpan di memori React, bukan localStorage.
  const [passwordGrant, setPasswordGrant] = useState(null)
  const [challengeSubmitting, setChallengeSubmitting] = useState(false)
  const [passwordResetComplete, setPasswordResetComplete] = useState(false)
  const [googleWidgetError, setGoogleWidgetError] = useState('')
  const [error, setError] = useState('')
  const [errorTitle, setErrorTitle] = useState('Gagal masuk')

  const googleButtonRef = useRef(null)
  const googleCallbackRef = useRef(null)
  const mountedRef = useRef(false)
  const busyRef = useRef(false)

  const destination = safeDestination(
    location.state?.from?.pathname
  )

  useEffect(() => {
    mountedRef.current = true
    return () => {
      mountedRef.current = false
    }
  }, [])

  function openUsernameRecovery() {
    if (busyRef.current) return

    setMode('username')
    setRecoveredUsername('')
    setError('')
    setGoogleWidgetError('')
    setPassword('')
  }

  function openPasswordRecovery() {
    if (busyRef.current) return

    setMode('password')
    setPassword('')
    setResetPassword('')
    setResetPasswordConfirmation('')
    setShowResetPassword(false)
    setPasswordChallenge(null)
    setPasswordGrant(null)
    setPasswordResetComplete(false)
    setRecoveredUsername('')
    setError('')
    setGoogleWidgetError('')
  }

  function backToLogin({ useRecoveredUsername = false } = {}) {
    if (busyRef.current) return

    if (useRecoveredUsername && recoveredUsername) {
      setUsername(recoveredUsername)
    }

    setMode('login')
    setRecoveredUsername('')
    setPassword('')
    setResetPassword('')
    setResetPasswordConfirmation('')
    setShowResetPassword(false)
    setPasswordChallenge(null)
    setPasswordGrant(null)
    setPasswordResetComplete(false)
    setError('')
    setGoogleWidgetError('')
  }

  async function handleStartPasswordRecovery() {
    if (busyRef.current || passwordChallenge || passwordGrant) return

    if (!GOOGLE_CLIENT_ID) {
      setErrorTitle('Google belum tersedia')
      setError('Google belum dikonfigurasi. Hubungi Tata Usaha sekolah.')
      return
    }

    busyRef.current = true
    setChallengeSubmitting(true)
    setError('')
    setGoogleWidgetError('')

    try {
      const result = await apiClient.post('/recovery/google/password/challenge', {})
      const data = result?.data

      if (
        !/^[a-f0-9]{48}$/.test(data?.challenge_id || '') ||
        !/^[a-f0-9]{64}$/.test(data?.nonce || '') ||
        !Number.isFinite(Number(data?.expires_in)) ||
        Number(data.expires_in) <= 0
      ) {
        throw new Error('Respons challenge tidak sesuai.')
      }

      if (mountedRef.current) {
        setPasswordChallenge({
          challenge_id: data.challenge_id,
          nonce: data.nonce,
          expires_in: Number(data.expires_in),
          issued_at: Date.now(),
        })
      }
    } catch (requestError) {
      if (mountedRef.current) {
        setErrorTitle('Gagal menyiapkan verifikasi')
        setError(getChallengeError(requestError))
      }
    } finally {
      busyRef.current = false
      if (mountedRef.current) setChallengeSubmitting(false)
    }
  }

  function restartPasswordVerification() {
    if (busyRef.current) return
    setPasswordChallenge(null)
    setPasswordGrant(null)
    setResetPassword('')
    setResetPasswordConfirmation('')
    setShowResetPassword(false)
    setError('')
    setGoogleWidgetError('')
  }

  async function handleSubmitNewPassword(event) {
    event.preventDefault()
    if (busyRef.current || !passwordGrant || passwordResetComplete) return

    if (Date.now() >= passwordGrant.issued_at + passwordGrant.expires_in * 1000) {
      setPasswordGrant(null)
      setResetPassword('')
      setResetPasswordConfirmation('')
      setErrorTitle('Verifikasi kedaluwarsa')
      setError('Waktu reset password sudah habis. Silakan verifikasi Google kembali.')
      return
    }

    if (!isStrongPassword(resetPassword)) {
      setErrorTitle('Password belum memenuhi syarat')
      setError('Gunakan minimal 12 karakter dengan huruf besar, huruf kecil, angka, dan simbol.')
      return
    }

    if (resetPassword !== resetPasswordConfirmation) {
      setErrorTitle('Konfirmasi tidak cocok')
      setError('Ulangi password yang sama pada kolom konfirmasi.')
      return
    }

    busyRef.current = true
    setSubmitting(true)
    setError('')

    try {
      // Reset grant bukan token Sanctum; hanya diberikan setelah verifikasi Google.
      await apiClient.post('/recovery/google/password', {
        reset_token: passwordGrant.reset_token,
        password: resetPassword,
        password_confirmation: resetPasswordConfirmation,
      })

      if (mountedRef.current) {
        setUsername(passwordGrant.username)
        setPassword('')
        setResetPassword('')
        setResetPasswordConfirmation('')
        setPasswordGrant(null)
        setPasswordChallenge(null)
        setPasswordResetComplete(true)
      }
    } catch (requestError) {
      if (mountedRef.current) {
        // Izin sekali pakai mungkin telah dihabiskan server; jangan mengulanginya.
        setPasswordGrant(null)
        setResetPassword('')
        setResetPasswordConfirmation('')
        setErrorTitle('Reset password gagal')
        setError(getPasswordGrantError(requestError))
      }
    } finally {
      busyRef.current = false
      if (mountedRef.current) setSubmitting(false)
    }
  }

  async function handlePasswordLogin(event) {
    event.preventDefault()

    if (busyRef.current) return

    if (!username.trim() || !password) {
      setErrorTitle('Periksa formulir')
      setError('Username dan password wajib diisi.')
      return
    }

    busyRef.current = true
    setSubmitting(true)
    setError('')

    try {
      await login(username.trim(), password)
      navigate(destination, { replace: true })
    } catch (requestError) {
      if (mountedRef.current) {
        setErrorTitle('Gagal masuk')
        setError(getPasswordLoginError(requestError))
      }
    } finally {
      busyRef.current = false
      if (mountedRef.current) setSubmitting(false)
    }
  }

  async function handleGoogleCredential(response, flow) {
    // Respons dari popup lama tidak boleh berpindah ke alur lain.
    if (!response?.credential || busyRef.current || flow?.mode !== mode) return

    if (mode === 'password') {
      if (
        !passwordChallenge ||
        passwordGrant ||
        passwordResetComplete ||
        flow.challengeId !== passwordChallenge.challenge_id
      ) return

      if (Date.now() >= passwordChallenge.issued_at + passwordChallenge.expires_in * 1000) {
        setErrorTitle('Verifikasi kedaluwarsa')
        setError('Challenge 5 menit sudah berakhir. Minta verifikasi Google baru.')
        setPasswordChallenge(null)
        return
      }
    }

    busyRef.current = true
    setGoogleSubmitting(true)
    setError('')

    try {
      if (mode === 'password') {
        // Tahap 1: verifikasi Google + nonce untuk mendapatkan izin reset sementara.
        // ID token Google tidak pernah dipakai untuk mengirim password baru.
        const result = await apiClient.post('/recovery/google/password/verify', {
          challenge_id: passwordChallenge.challenge_id,
          credential: response.credential,
        })

        const data = result?.data
        if (
          typeof data?.username !== 'string' || !data.username.trim() ||
          !/^[a-f0-9]{64}$/.test(data?.reset_token || '') ||
          !Number.isFinite(Number(data?.expires_in)) ||
          Number(data.expires_in) <= 0
        ) {
          throw new Error('Respons izin reset tidak sesuai.')
        }

        if (mountedRef.current) {
          setPasswordGrant({
            username: data.username.trim(),
            reset_token: data.reset_token,
            expires_in: Number(data.expires_in),
            issued_at: Date.now(),
          })
          setPasswordChallenge(null)
          setResetPassword('')
          setResetPasswordConfirmation('')
        }
      } else if (mode === 'username') {
        // Pemulihan username tidak menghasilkan token login.
        const result = await apiClient.post('/recovery/google/username', {
          credential: response.credential,
        })

        const recovered = result?.data?.username

        if (typeof recovered !== 'string' || !recovered.trim()) {
          throw new Error('Respons pemulihan username tidak sesuai.')
        }

        if (mountedRef.current) setRecoveredUsername(recovered)
      } else {
        // Google Login lama tetap menggunakan Sanctum.
        const result = await apiClient.post('/login/google', {
          credential: response.credential,
        })

        const token = result?.data?.token

        if (typeof token !== 'string' || !token) {
          throw new Error('Server tidak mengembalikan sesi login yang valid.')
        }

        setAuthToken(token)
        window.location.replace(destination)
      }
    } catch (requestError) {
      if (mountedRef.current) {
        if (mode === 'password') {
          // Setelah gagal, minta challenge baru agar tidak memakai nonce lama.
          setPasswordChallenge(null)
          setPasswordGrant(null)
          setErrorTitle('Verifikasi Google gagal')
          setError(getGooglePasswordRecoveryError(requestError))
        } else {
          setErrorTitle(
            mode === 'username' ? 'Pemulihan username gagal' : 'Login Google gagal'
          )
          setError(
            mode === 'username'
              ? getGoogleRecoveryError(requestError)
              : getGoogleLoginError(requestError)
          )
        }
      }
    } finally {
      busyRef.current = false
      if (mountedRef.current) setGoogleSubmitting(false)
    }
  }

  googleCallbackRef.current = handleGoogleCredential

  useEffect(() => {
    if (
      status === 'authenticated' ||
      !GOOGLE_CLIENT_ID ||
      (mode === 'username' && recoveredUsername) ||
      (mode === 'password' && (!passwordChallenge || passwordGrant || passwordResetComplete))
    ) {
      return undefined
    }

    const buttonContainer = googleButtonRef.current
    if (!buttonContainer) return undefined

    let cancelled = false
    const expectedMode = mode
    const expectedChallengeId = passwordChallenge?.challenge_id || null
    setGoogleWidgetError('')

    loadGoogleScript()
      .then(() => {
        if (cancelled || !buttonContainer.isConnected) return

        buttonContainer.replaceChildren()
        // GIS memakai nonce hanya untuk alur reset password.
        // Mode login/username kembali ke konfigurasi tanpa nonce.
        window.google.accounts.id.initialize({
          client_id: GOOGLE_CLIENT_ID,
          callback: (credentialResponse) => {
            googleCallbackRef.current?.(credentialResponse, {
              mode: expectedMode,
              challengeId: expectedChallengeId,
            })
          },
          auto_select: false,
          ux_mode: 'popup',
          ...(expectedMode === 'password'
            ? { nonce: passwordChallenge.nonce }
            : {}),
        })

        window.google.accounts.id.renderButton(buttonContainer, {
          type: 'standard',
          theme: 'outline',
          size: 'large',
          text: expectedMode === 'login' ? 'signin_with' : 'continue_with',
          shape: 'rectangular',
          logo_alignment: 'left',
          locale: 'id',
          width: Math.min(
            400,
            Math.max(240, buttonContainer.clientWidth || 340)
          ),
        })
      })
      .catch((loadError) => {
        if (!cancelled) setGoogleWidgetError(loadError.message)
      })

    return () => {
      cancelled = true
      buttonContainer.replaceChildren()
    }
  }, [status, mode, recoveredUsername, passwordChallenge, passwordGrant, passwordResetComplete])

  if (status === 'authenticated') {
    return <Navigate to={destination} replace />
  }

  const isBusy = submitting || googleSubmitting || challengeSubmitting

  return (
    <main className="login-page">
      <div className="login-shell">
        <section className="login-brand-panel" aria-label="Tentang SIAKAD">
          <div className="login-brand-visual" aria-hidden="true">
            <img
              src="/images/school/foto-sekolah-sman1-v-koto-timur.jpg"
              alt=""
              className="login-school-photo"
            />
            <div className="login-brand-overlay" />
          </div>

          <div className="login-brand-inner">
            <div className="login-brand-top">
              <img
                src="/images/branding/logo-sman1-v-koto-timur-removebg-preview.png"
                alt="Logo SMA Negeri 1 V Koto Timur"
                className="login-school-logo"
              />
              <div className="login-brand-identity">
                <strong>SIAKAD</strong>
                <span>SMA Negeri 1 V Koto Timur</span>
              </div>
            </div>

            <div className="login-brand-copy">
              <div className="login-eyebrow">
                <span className="login-eyebrow-line" />
                RUANG AKADEMIK DIGITAL
              </div>
              <h1>
                Semua kebutuhan
                <br />
                akademik, <em>lebih dekat.</em>
              </h1>
              <p>
                Satu tempat untuk jadwal, presensi, penilaian,
                dan informasi belajar warga SMA Negeri 1 V Koto Timur.
              </p>
            </div>

            <div className="login-brand-footer">
              <span className="login-brand-footer-dot" aria-hidden="true" />
              <span>Portal akademik sekolah</span>
              <span className="login-brand-footer-mark">KOTO TIMUR / SIAKAD</span>
            </div>
          </div>
        </section>

        <section className="login-form-panel" aria-label="Masuk ke SIAKAD">
          <div className="login-form-container">
            {mode !== 'login' ? (
              <button
                type="button"
                className="login-back-link"
                style={recoveryStyles.backButton}
                disabled={isBusy}
                onClick={() => backToLogin()}
              >
                <ArrowIcon direction="left" />
                Kembali ke Login
              </button>
            ) : (
              <Link className="login-back-link" to="/">
                <ArrowIcon direction="left" />
                Kembali ke Beranda
              </Link>
            )}

            <div className="login-heading">
              <div className="login-section-label">
                <span className="login-section-dot" aria-hidden="true" />
                {mode === 'login' ? 'PORTAL WARGA SEKOLAH' : 'PEMULIHAN AKUN'}
              </div>
              {mode === 'username' ? (
                <>
                  <h2>Lupa username<span>?</span></h2>
                  <p>
                    Gunakan akun Google yang sudah tertaut untuk menemukan
                    username SIAKAD kamu.
                  </p>
                </>
              ) : mode === 'password' ? (
                <>
                  <h2>
                    {passwordResetComplete
                      ? 'Password diperbarui'
                      : passwordGrant
                        ? 'Buat password baru'
                        : 'Lupa password'}<span>.</span>
                  </h2>
                  <p>
                    {passwordResetComplete
                      ? 'Password baru telah tersimpan. Silakan masuk kembali ke SIAKAD.'
                      : passwordGrant
                        ? 'Akun kamu sudah diverifikasi. Sekarang atur password SIAKAD yang baru.'
                        : 'Verifikasi Google yang tertaut ke akun SIAKAD sebelum membuat password baru.'}
                  </p>
                </>
              ) : (
                <>
                  <h2>Selamat datang<span>.</span></h2>
                  <p>
                    Masuk untuk mengakses ruang akademikmu.
                    Gunakan akun yang diberikan oleh sekolah.
                  </p>
                </>
              )}
            </div>

            {mode === 'login' && (
              <form className="login-form" onSubmit={handlePasswordLogin}>
                <div className="form-field">
                  <label htmlFor="login-username">Username</label>
                  <input
                    id="login-username"
                    name="username"
                    type="text"
                    autoComplete="username"
                    autoCapitalize="none"
                    spellCheck={false}
                    placeholder="Masukkan username"
                    value={username}
                    disabled={isBusy}
                    onChange={(event) => setUsername(event.target.value)}
                    required
                  />
                </div>

                <div className="form-field">
                  <div className="login-label-row">
                    <label htmlFor="login-password">Password</label>
                    <span className="login-label-hint">Akun sekolah</span>
                  </div>
                  <div className="password-field">
                    <input
                      id="login-password"
                      name="password"
                      type={showPassword ? 'text' : 'password'}
                      autoComplete="current-password"
                      placeholder="Masukkan password"
                      value={password}
                      disabled={isBusy}
                      onChange={(event) => setPassword(event.target.value)}
                      required
                    />
                    <button
                      type="button"
                      className="password-toggle"
                      disabled={isBusy}
                      aria-label={showPassword ? 'Sembunyikan password' : 'Tampilkan password'}
                      onClick={() => setShowPassword((value) => !value)}
                    >
                      {showPassword ? 'Sembunyikan' : 'Lihat'}
                    </button>
                  </div>
                </div>

                {error && (
                  <div className="login-error" role="alert">
                    <strong>{errorTitle}</strong>
                    <span>{error}</span>
                  </div>
                )}

                <button
                  className="login-submit"
                  type="submit"
                  disabled={isBusy}
                >
                  {submitting ? (
                    <span className="login-submit__loading">
                      <span className="login-submit__spinner" aria-hidden="true" />
                      Memverifikasi akun...
                    </span>
                  ) : (
                    <>
                      <span>Masuk ke SIAKAD</span>
                      <ArrowIcon />
                    </>
                  )}
                </button>
              </form>
            )}

            {mode === 'username' && !recoveredUsername && (
              <div style={recoveryStyles.helper}>
                <strong style={{ display: 'block', color: '#193e5e', marginBottom: 4 }}>
                  Verifikasi kepemilikan akun Google
                </strong>
                Pilih akun Google yang sebelumnya ditautkan di Profil Saya.
                Sistem akan menampilkan username hanya setelah verifikasi
                Google dan pemeriksaan status akun berhasil.
              </div>
            )}

            {mode === 'username' && recoveredUsername && (
              <div style={recoveryStyles.success} role="status" aria-live="polite">
                <div>
                  <strong style={{ color: '#1e6544', fontSize: 15 }}>
                    Username berhasil ditemukan
                  </strong>
                  <p style={{ margin: '6px 0 0', color: '#4d7461', fontSize: 12, lineHeight: 1.65 }}>
                    Username akun SIAKAD yang tertaut ke Google kamu:
                  </p>
                </div>
                <div style={recoveryStyles.username}>
                  {recoveredUsername}
                </div>
                <button
                  type="button"
                  className="login-submit"
                  onClick={() => backToLogin({ useRecoveredUsername: true })}
                >
                  <span>Gunakan username ini</span>
                  <ArrowIcon />
                </button>
                <span style={{ color: '#638477', fontSize: 11, lineHeight: 1.6 }}>
                  Pemulihan ini tidak membuat sesi login baru.
                  Kamu tetap harus memasukkan password SIAKAD untuk masuk.
                </span>
              </div>
            )}

            {mode === 'username' && !recoveredUsername && error && (
              <div className="login-error" role="alert" style={{ marginTop: 16 }}>
                <strong>{errorTitle}</strong>
                <span>{error}</span>
              </div>
            )}

            {mode === 'password' && !passwordResetComplete && !passwordGrant && (
              <>
                <div style={recoveryStyles.helper}>
                  <strong style={{ display: 'block', color: '#193e5e', marginBottom: 4 }}>
                    Langkah 1 — Verifikasi akun Google
                  </strong>
                  Pilih akun Google yang sebelumnya ditautkan di Profil Saya.
                  Setelah berhasil, sistem menampilkan username akun SIAKAD
                  dan mengizinkan kamu membuat password baru.
                </div>

                {!passwordChallenge ? (
                  <button
                    type="button"
                    className="login-submit"
                    style={{ marginTop: 18 }}
                    onClick={handleStartPasswordRecovery}
                    disabled={isBusy}
                  >
                    {challengeSubmitting ? (
                      <span className="login-submit__loading">
                        <span className="login-submit__spinner" aria-hidden="true" />
                        Menyiapkan verifikasi...
                      </span>
                    ) : (
                      <>
                        <span>Mulai verifikasi Google</span>
                        <ArrowIcon />
                      </>
                    )}
                  </button>
                ) : (
                  <div style={{ ...recoveryStyles.helper, marginTop: 16 }} role="status">
                    <strong style={{ color: '#193e5e' }}>Verifikasi siap</strong>
                    <p style={{ margin: '6px 0' }}>
                      Pilih Google pada tombol di bawah. Challenge berlaku 5 menit
                      dan hanya dapat digunakan satu kali.
                    </p>
                    <button
                      type="button"
                      style={recoveryStyles.entry}
                      onClick={restartPasswordVerification}
                      disabled={isBusy}
                    >
                      Mulai verifikasi baru
                    </button>
                  </div>
                )}
                {error && (
                  <div className="login-error" role="alert" style={{ marginTop: 16 }}>
                    <strong>{errorTitle}</strong>
                    <span>{error}</span>
                  </div>
                )}
              </>
            )}

            {mode === 'password' && !passwordResetComplete && passwordGrant && (
              <>
                <div style={recoveryStyles.success} role="status" aria-live="polite">
                  <div>
                    <strong style={{ color: '#1e6544', fontSize: 15 }}>
                      Identitas berhasil diverifikasi
                    </strong>
                    <p style={{ margin: '6px 0 0', color: '#4d7461', fontSize: 12, lineHeight: 1.65 }}>
                      Akun SIAKAD yang terhubung dengan Google:
                    </p>
                  </div>
                  <div style={recoveryStyles.username}>
                    {passwordGrant.username}
                  </div>
                  <span style={{ color: '#638477', fontSize: 12, lineHeight: 1.6 }}>
                    Izin reset berlaku 5 menit setelah verifikasi. Password belum diubah.
                  </span>
                </div>

                <form
                  className="login-form"
                  style={{ marginTop: 20 }}
                  onSubmit={handleSubmitNewPassword}
                >
                  <div className="form-field">
                    <label htmlFor="reset-password">Password baru</label>
                    <div className="password-field">
                      <input
                        id="reset-password"
                        name="new-password"
                        type={showResetPassword ? 'text' : 'password'}
                        autoComplete="new-password"
                        placeholder="Minimal 12 karakter"
                        minLength={12}
                        value={resetPassword}
                        onChange={(event) => setResetPassword(event.target.value)}
                        disabled={isBusy}
                        required
                      />
                      <button
                        type="button"
                        className="password-toggle"
                        disabled={isBusy}
                        onClick={() => setShowResetPassword((value) => !value)}
                        aria-label={showResetPassword ? 'Sembunyikan password baru' : 'Tampilkan password baru'}
                      >
                        {showResetPassword ? 'Sembunyikan' : 'Lihat'}
                      </button>
                    </div>
                  </div>
                  <div className="form-field">
                    <label htmlFor="reset-password-confirmation">Konfirmasi password baru</label>
                    <input
                      id="reset-password-confirmation"
                      name="new-password-confirmation"
                      type={showResetPassword ? 'text' : 'password'}
                      autoComplete="new-password"
                      placeholder="Ulangi password baru"
                      minLength={12}
                      value={resetPasswordConfirmation}
                      onChange={(event) => setResetPasswordConfirmation(event.target.value)}
                      disabled={isBusy}
                      required
                    />
                  </div>
                  {error && (
                    <div className="login-error" role="alert">
                      <strong>{errorTitle}</strong>
                      <span>{error}</span>
                    </div>
                  )}
                  <button type="submit" className="login-submit" disabled={isBusy}>
                    {submitting ? (
                      <span className="login-submit__loading">
                        <span className="login-submit__spinner" aria-hidden="true" />
                        Menyimpan password baru...
                      </span>
                    ) : (
                      <>
                        <span>Simpan password baru</span>
                        <ArrowIcon />
                      </>
                    )}
                  </button>
                </form>
                <button
                  type="button"
                  style={{ ...recoveryStyles.entry, marginTop: 16 }}
                  onClick={restartPasswordVerification}
                  disabled={isBusy}
                >
                  Gunakan akun Google lain / verifikasi ulang
                </button>
              </>
            )}

            {mode === 'password' && passwordResetComplete && (
              <div style={recoveryStyles.success} role="status" aria-live="polite">
                <strong style={{ color: '#1e6544', fontSize: 15 }}>
                  Password berhasil diperbarui
                </strong>
                <p style={{ margin: 0, color: '#4d7461', fontSize: 12, lineHeight: 1.7 }}>
                  Seluruh sesi/token Sanctum lama telah dicabut oleh server.
                  Silakan masuk kembali menggunakan password baru atau Google Login.
                </p>
                <button type="button" className="login-submit" onClick={() => backToLogin()}>
                  <span>Kembali ke Login</span>
                  <ArrowIcon />
                </button>
              </div>
            )}

            {(mode === 'login' ||
              (mode === 'username' && !recoveredUsername) ||
              (mode === 'password' && Boolean(passwordChallenge) && !passwordGrant && !passwordResetComplete)) && (
            <>
            {mode === 'login' && (
            <div className="login-method-divider" aria-hidden="true">
              <span />
              <p>atau lanjutkan dengan</p>
              <span />
            </div>
            )}

            <div
              className={`login-google ${isBusy ? 'login-google--busy' : ''}`}
              style={mode !== 'login' ? { marginTop: 18 } : undefined}
            >
              {GOOGLE_CLIENT_ID ? (
                <>
                  <div
                    className="login-google-button"
                    ref={googleButtonRef}
                    aria-label={
                      mode === 'username'
                        ? 'Pulihkan username dengan Google'
                        : mode === 'password'
                          ? 'Verifikasi Google untuk reset password'
                          : 'Masuk dengan Google'
                    }
                  />
                  {googleSubmitting && (
                    <p className="login-google-status" role="status">
                      <span className="login-submit__spinner login-submit__spinner--dark" aria-hidden="true" />
                      Memverifikasi identitas Google...
                    </p>
                  )}
                  {googleWidgetError && (
                    <p className="login-google-error" role="alert">
                      {googleWidgetError}
                    </p>
                  )}
                </>
              ) : (
                <p className="login-google-error" role="status">
                  {mode === 'login'
                    ? 'Login Google belum dikonfigurasi. Gunakan username dan password.'
                    : 'Verifikasi Google belum dikonfigurasi. Hubungi Tata Usaha sekolah.'}
                </p>
              )}
              <p className="login-google-hint">
                <LockIcon />
                {mode === 'username'
                  ? 'Google digunakan hanya untuk memverifikasi identitas dan menemukan username.'
                  : mode === 'password'
                    ? 'Google hanya memverifikasi akun; password baru dikirim ke Laravel setelah verifikasi.'
                    : 'Hanya untuk akun Google yang sudah ditautkan ke SIAKAD.'}
              </p>
            </div>
            </>
            )}

            {mode === 'login' && (
            <div className="login-activation">
              <div className="login-activation-icon" aria-hidden="true">
                <LockIcon />
              </div>
              <div className="login-activation-copy">
                <strong>Baru menerima akun sekolah?</strong>
                <span>Aktifkan dulu sebelum masuk ke SIAKAD.</span>
                <Link to="/aktivasi-akun">
                  Aktivasi Akun Baru <ArrowIcon />
                </Link>
              </div>
            </div>
            )}

            <p className="login-note">
              {mode !== 'login' ? (
                <>
                  Tidak punya akses ke akun Google tertaut?
                  Hubungi Tata Usaha sekolah.
                </>
              ) : (
                <>
                  <button
                    type="button"
                    style={recoveryStyles.entry}
                    onClick={openUsernameRecovery}
                    disabled={isBusy}
                  >
                    Lupa username?
                  </button>
                  <span style={{ margin: '0 10px', color: '#a6b6c2' }}>·</span>
                  <button
                    type="button"
                    style={recoveryStyles.entry}
                    onClick={openPasswordRecovery}
                    disabled={isBusy}
                  >
                    Lupa password?
                  </button>
                </>
              )}
            </p>
          </div>
        </section>
      </div>
    </main>
  )
}
