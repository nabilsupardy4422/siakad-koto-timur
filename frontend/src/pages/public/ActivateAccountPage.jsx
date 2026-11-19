import { useState } from 'react'
import { Link } from 'react-router-dom'

import './ActivateAccountPage.css'

const API_BASE_URL =
  import.meta.env.VITE_API_BASE_URL ||
  'http://127.0.0.1:8000/api'

const initialForm = {
  username: '',
  activation_code: '',
  password: '',
  password_confirmation: '',
}

function firstError(errors, field) {
  const value = errors?.[field]

  return Array.isArray(value)
    ? value[0] || ''
    : value || ''
}

export default function ActivateAccountPage() {
  const [form, setForm] = useState(initialForm)
  const [fieldErrors, setFieldErrors] = useState({})
  const [message, setMessage] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [success, setSuccess] = useState(false)
  const [showPassword, setShowPassword] = useState(false)

  // ========================================
  // FORM HANDLER
  // ========================================

  function handleChange(event) {
    const { name, value } = event.target

    setForm((current) => ({
      ...current,
      [name]: value,
    }))

    setFieldErrors((current) => ({
      ...current,
      [name]: '',
    }))

    setMessage('')
  }

  // ========================================
  // ACTIVATE ACCOUNT
  // ========================================

  async function handleSubmit(event) {
    event.preventDefault()

    if (submitting || success) return

    const errors = {}

    const username = form.username.trim()
    const activationCode =
      form.activation_code.trim()

    if (!username) {
      errors.username = 'Username wajib diisi.'
    }

    if (!activationCode) {
      errors.activation_code =
        'Kode aktivasi wajib diisi.'
    }

    if (!form.password) {
      errors.password =
        'Password baru wajib diisi.'
    }

    if (!form.password_confirmation) {
      errors.password_confirmation =
        'Konfirmasi password wajib diisi.'
    } else if (
      form.password !== form.password_confirmation
    ) {
      errors.password_confirmation =
        'Konfirmasi password tidak sama.'
    }

    if (Object.keys(errors).length > 0) {
      setFieldErrors(errors)
      return
    }

    setSubmitting(true)
    setMessage('')
    setFieldErrors({})

    try {
      // Endpoint publik.
      // Tidak mengirim token atau cookie login TU.
      const response = await fetch(
        `${API_BASE_URL}/account/activate`,
        {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
          },
          credentials: 'omit',
          cache: 'no-store',
          body: JSON.stringify({
            username,
            activation_code: activationCode,
            password: form.password,
            password_confirmation:
              form.password_confirmation,
          }),
        }
      )

      const result = await response
        .json()
        .catch(() => null)

      if (!response.ok) {
        setFieldErrors(result?.errors || {})

        setMessage(
          result?.message ||
            'Aktivasi gagal. Periksa kembali data atau hubungi Tata Usaha.'
        )

        return
      }

      // Bersihkan kode dan password dari state.
      setForm(initialForm)
      setShowPassword(false)
      setSuccess(true)

      setMessage(
        result?.message ||
          'Aktivasi berhasil. Silakan masuk menggunakan password pribadi.'
      )
    } catch {
      setMessage(
        'Tidak dapat menghubungi server. Periksa koneksi dan coba lagi.'
      )
    } finally {
      setSubmitting(false)
    }
  }

  // ========================================
  // RENDER
  // ========================================

  return (
    <main className="account-activation">
      <section
        className="account-activation__card"
        aria-labelledby="activation-title"
      >
        {/* HEADER */}

        <header className="account-activation__header">
          <div
            className="account-activation__emblem"
            aria-hidden="true"
          >
            S
          </div>

          <div>
            <strong>SIAKAD</strong>
            <span>SMA Negeri 1 V Koto Timur</span>
          </div>
        </header>

        {success ? (
          /* ==================================
             SUCCESS STATE
             ================================== */

          <div
            className="account-activation__success"
            role="status"
          >
            <div
              className="account-activation__success-icon"
              aria-hidden="true"
            >
              ✓
            </div>

            <h1 id="activation-title">
              Akun berhasil diaktivasi
            </h1>

            <p>{message}</p>

            <p>
              Akun Anda sekarang dapat digunakan.
              Silakan login menggunakan username
              dan password pribadi yang baru dibuat.
            </p>

            <Link
              to="/login"
              className="account-activation__primary-link"
            >
              Masuk ke SIAKAD →
            </Link>
          </div>
        ) : (
          <>
            {/* INTRO */}

            <div className="account-activation__intro">
              <span className="account-activation__eyebrow">
                AKTIVASI AKUN
              </span>

              <h1 id="activation-title">
                Buat password pribadi
              </h1>

              <p>
                Masukkan username dan kode aktivasi
                dari slip yang diberikan Tata Usaha.
                Kode hanya dapat digunakan sekali
                dan memiliki masa berlaku terbatas.
              </p>
            </div>

            {/* ERROR MESSAGE */}

            {message && (
              <div
                className="account-activation__alert"
                role="alert"
              >
                {message}
              </div>
            )}

            {/* ACTIVATION FORM */}

            <form
              className="account-activation__form"
              onSubmit={handleSubmit}
              noValidate
            >
              {/* USERNAME */}

              <div className="account-activation__field">
                <label htmlFor="activation-username">
                  Username
                </label>

                <input
                  id="activation-username"
                  name="username"
                  type="text"
                  autoComplete="username"
                  maxLength={100}
                  value={form.username}
                  onChange={handleChange}
                  disabled={submitting}
                  placeholder="Username pada slip aktivasi"
                  aria-invalid={Boolean(
                    firstError(fieldErrors, 'username')
                  )}
                />

                {firstError(fieldErrors, 'username') && (
                  <small role="alert">
                    {firstError(fieldErrors, 'username')}
                  </small>
                )}
              </div>

              {/* ACTIVATION CODE */}

              <div className="account-activation__field">
                <label htmlFor="activation-code">
                  Kode aktivasi
                </label>

                <input
                  id="activation-code"
                  name="activation_code"
                  type="text"
                  autoComplete="off"
                  spellCheck={false}
                  maxLength={80}
                  value={form.activation_code}
                  onChange={handleChange}
                  disabled={submitting}
                  placeholder="XXXX-XXXX-XXXX-XXXX-XXXX-XXXX-XXXX-XXXX"
                  aria-invalid={Boolean(
                    firstError(
                      fieldErrors,
                      'activation_code'
                    )
                  )}
                />

                {firstError(
                  fieldErrors,
                  'activation_code'
                ) && (
                  <small role="alert">
                    {firstError(
                      fieldErrors,
                      'activation_code'
                    )}
                  </small>
                )}
              </div>

              {/* NEW PASSWORD */}

              <div className="account-activation__field">
                <div className="account-activation__label-row">
                  <label htmlFor="activation-password">
                    Password baru
                  </label>

                  <button
                    type="button"
                    className="account-activation__reveal"
                    disabled={submitting}
                    onClick={() =>
                      setShowPassword(
                        (current) => !current
                      )
                    }
                  >
                    {showPassword
                      ? 'Sembunyikan'
                      : 'Tampilkan'}
                  </button>
                </div>

                <input
                  id="activation-password"
                  name="password"
                  type={
                    showPassword
                      ? 'text'
                      : 'password'
                  }
                  autoComplete="new-password"
                  value={form.password}
                  onChange={handleChange}
                  disabled={submitting}
                  placeholder="Buat password yang kuat"
                  aria-invalid={Boolean(
                    firstError(
                      fieldErrors,
                      'password'
                    )
                  )}
                />

                {firstError(
                  fieldErrors,
                  'password'
                ) && (
                  <small role="alert">
                    {firstError(
                      fieldErrors,
                      'password'
                    )}
                  </small>
                )}
              </div>

              {/* CONFIRM PASSWORD */}

              <div className="account-activation__field">
                <label
                  htmlFor="activation-password-confirmation"
                >
                  Konfirmasi password baru
                </label>

                <input
                  id="activation-password-confirmation"
                  name="password_confirmation"
                  type={
                    showPassword
                      ? 'text'
                      : 'password'
                  }
                  autoComplete="new-password"
                  value={form.password_confirmation}
                  onChange={handleChange}
                  disabled={submitting}
                  placeholder="Ulangi password baru"
                  aria-invalid={Boolean(
                    firstError(
                      fieldErrors,
                      'password_confirmation'
                    )
                  )}
                />

                {firstError(
                  fieldErrors,
                  'password_confirmation'
                ) && (
                  <small role="alert">
                    {firstError(
                      fieldErrors,
                      'password_confirmation'
                    )}
                  </small>
                )}
              </div>

              {/* SUBMIT */}

              <button
                type="submit"
                className="account-activation__submit"
                disabled={submitting}
              >
                {submitting
                  ? 'Memproses aktivasi...'
                  : 'Aktivasi Akun'}
              </button>
            </form>

            {/* HELP */}

            <p className="account-activation__help">
              Slip hilang atau kode kedaluwarsa?
              Hubungi Tata Usaha untuk menerbitkan
              kode baru. Jangan bagikan kode
              aktivasi ataupun password kepada
              orang lain.
            </p>
          </>
        )}
      </section>
    </main>
  )
}
