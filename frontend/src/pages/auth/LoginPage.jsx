import { useState } from 'react'
import { Navigate, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../../context/useAuth'

function getErrorMessage(error) {
  if (error?.status === 401) {
    return 'Username atau password tidak sesuai.'
  }

  if (error?.status === 403) {
    return 'Akun ini tidak dapat digunakan untuk masuk.'
  }

  if (error?.status === 422) {
    return 'Periksa kembali username dan password.'
  }

  return 'Tidak dapat terhubung ke server. Silakan coba lagi.'
}

export default function LoginPage() {
  const navigate = useNavigate()
  const location = useLocation()

  const { status, login } = useAuth()

  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [error, setError] = useState('')
  const [submitting, setSubmitting] = useState(false)

  if (status === 'authenticated') {
    return <Navigate to="/app/dashboard" replace />
  }

  function handleBackToHome() {
    navigate('/')
  }

  async function handleSubmit(event) {
    event.preventDefault()

    if (!username.trim() || !password) {
      setError('Username dan password wajib diisi.')
      return
    }

    setError('')
    setSubmitting(true)

    try {
      await login(username.trim(), password)

      const destination =
        location.state?.from?.pathname || '/app/dashboard'

      navigate(destination, { replace: true })
    } catch (requestError) {
      setError(getErrorMessage(requestError))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <main className="login-page">
      <section className="login-brand-panel">
        <div className="login-brand-visual">
          <img
            src="/images/school/foto-sekolah-sman1-v-koto-timur.jpg"
            alt="Gedung SMA Negeri 1 V Koto Timur"
            className="login-school-photo"
          />

          <div className="login-brand-overlay" aria-hidden="true" />
        </div>

        <div className="login-brand-inner">
          <img
            src="/images/branding/logo-sman1-v-koto-timur-removebg-preview.png"
            alt="Logo SMA Negeri 1 V Koto Timur"
            className="login-school-logo"
          />

          <div className="login-brand-copy">
            <p className="login-eyebrow">SIAKAD KOTO TIMUR</p>

            <h1>
              Sistem Informasi
              <br />
              Akademik Sekolah
            </h1>

            <p>
              Satu akses untuk mengelola informasi akademik
              SMA Negeri 1 V Koto Timur.
            </p>
          </div>

          <p className="login-brand-footer">
            SMA Negeri 1 V Koto Timur
          </p>
        </div>
      </section>

      <section className="login-form-panel">
        <div className="login-form-container">
          <button
            type="button"
            className="login-back-link"
            onClick={handleBackToHome}
          >
            <span aria-hidden="true">←</span>
            <span>Kembali ke Beranda</span>
          </button>

          <div className="login-heading">
            <p className="login-section-label">AKSES SISTEM</p>

            <h2>Masuk ke SIAKAD</h2>

            <p>
              Gunakan akun sekolah Anda untuk melanjutkan.
            </p>
          </div>

          <form className="login-form" onSubmit={handleSubmit}>
            <div className="form-field">
              <label htmlFor="username">Username</label>

              <input
                id="username"
                name="username"
                type="text"
                autoComplete="username"
                value={username}
                onChange={(event) => setUsername(event.target.value)}
                placeholder="Masukkan username"
                disabled={submitting}
              />
            </div>

            <div className="form-field">
              <label htmlFor="password">Password</label>

              <div className="password-field">
                <input
                  id="password"
                  name="password"
                  type={showPassword ? 'text' : 'password'}
                  autoComplete="current-password"
                  value={password}
                  onChange={(event) => setPassword(event.target.value)}
                  placeholder="Masukkan password"
                  disabled={submitting}
                />

                <button
                  type="button"
                  className="password-toggle"
                  onClick={() =>
                    setShowPassword((current) => !current)
                  }
                  aria-label={
                    showPassword
                      ? 'Sembunyikan password'
                      : 'Tampilkan password'
                  }
                  disabled={submitting}
                >
                  {showPassword ? 'Sembunyikan' : 'Lihat'}
                </button>
              </div>
            </div>

            {error && (
              <div className="login-error" role="alert">
                <strong>Gagal masuk</strong>
                <span>{error}</span>
              </div>
            )}

            <button
              type="submit"
              className="login-submit"
              disabled={submitting}
            >
              {submitting ? (
                <>
                  <span
                    className="login-submit__spinner"
                    aria-hidden="true"
                  />
                  <span>Memproses...</span>
                </>
              ) : (
                <>
                  <span>Masuk</span>
                  <span aria-hidden="true">→</span>
                </>
              )}
            </button>
          </form>

          <p className="login-note">
            Akses sistem diberikan sesuai akun dan hak akses
            masing-masing pengguna.
          </p>
        </div>
      </section>
    </main>
  )
}