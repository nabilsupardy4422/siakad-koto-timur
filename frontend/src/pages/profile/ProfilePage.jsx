import { useEffect, useRef, useState } from 'react'
import PageHeader from '../../components/ui/PageHeader'
import { apiClient } from '../../services/apiClient'
import { googleAccountApi } from '../../services/googleAccountApi'
import ProfilePhotoCard from './ProfilePhotoCard'
import './ProfilePage.css'
import {ProfileBanner, ProfileIdentity, ProfilePassword} from './PersonalProfileWorkspace'
import ToastNotification from '../../components/ui/ToastNotification'
import {useAuth} from '../../context/useAuth'
import { getGoogleEnvironment, googleUnavailableMessage } from '../../utils/googleEnvironment.js'

const GOOGLE_CLIENT_ID =
  import.meta.env.VITE_GOOGLE_CLIENT_ID?.trim() || ''

const GOOGLE_SCRIPT_URL =
  'https://accounts.google.com/gsi/client'

let googleScriptPromise = null

function loadGoogleScript() {
  if (window.google?.accounts?.id) {
    return Promise.resolve()
  }

  if (googleScriptPromise) {
    return googleScriptPromise
  }

  googleScriptPromise = new Promise((resolve, reject) => {
    const existing = document.querySelector(
      'script[data-siakad-google-identity]'
    )

    const script =
      existing || document.createElement('script')

    const onLoad = () => {
      if (window.google?.accounts?.id) {
        resolve()
      } else {
        reject(
          new Error('Layanan verifikasi Google belum siap.')
        )
      }
    }

    const onError = () => {
      reject(
        new Error(
          'Gagal memuat layanan Google. Periksa koneksi internet.'
        )
      )
    }

    script.addEventListener('load', onLoad, {
      once: true,
    })

    script.addEventListener('error', onError, {
      once: true,
    })

    if (!existing) {
      script.src = GOOGLE_SCRIPT_URL
      script.async = true
      script.defer = true
      script.dataset.siakadGoogleIdentity = 'true'

      document.head.appendChild(script)
    }
  }).catch((error) => {
    googleScriptPromise = null
    throw error
  })

  return googleScriptPromise
}

function apiMessage(error, fallback) {
  return error?.message || fallback
}

function readMe(response) {
  const data = response?.data || {}
  const user = data.user || data
  const role = data.role || user.role

  return {
    id: user.id ?? user.user_id ?? null,
    name: user.name || '-',
    username: user.username || '-',
    email: user.email || '-',
    role:
      typeof role === 'string'
        ? role
        : role?.name || role?.code || '-',
    accountStatus: user.account_status || '-',
  }
}

export default function ProfilePage() {
  const {logout} = useAuth()
  const googleEnvironment = getGoogleEnvironment(window.location.origin, GOOGLE_CLIENT_ID)
  const googleAvailable = googleEnvironment.available
  const [profile, setProfile] = useState(null)
  const [tab, setTab] = useState('identity')
  const [revision, setRevision] = useState(0)
  const [account, setAccount] = useState(null)
  const [googleLinked, setGoogleLinked] = useState(null)
  const [googleEmail, setGoogleEmail] = useState(null)
  const [reverifyOpen, setReverifyOpen] = useState(false)
  const [linkOpen, setLinkOpen] = useState(false)
  const [linkPassword, setLinkPassword] = useState('')
  const [showLinkPassword, setShowLinkPassword] = useState(false)
  const [loading, setLoading] = useState(true)
  const [working, setWorking] = useState(false)
  const [widgetError, setWidgetError] = useState('')
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')

  const [unlinkOpen, setUnlinkOpen] = useState(false)
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)

  const googleButtonRef = useRef(null)
  const googleCallbackRef = useRef(null)
  const mountedRef = useRef(true)
  const workingRef = useRef(false)

  // Password SIAKAD hanya diperlukan saat tautan Google pertama.
  // Verifikasi ulang Google yang sama tidak membutuhkannya.
  const linkPasswordReady = linkPassword.trim().length > 0

  // ==========================================
  // MEMUAT INFORMASI AKUN
  // ==========================================

  useEffect(() => {
    mountedRef.current = true
    let cancelled = false

    async function load() {
      setLoading(true)
      setError('')

      const [meResult, statusResult] =
        await Promise.allSettled([
          apiClient.get('/profile'),
          googleAccountApi.status(),
        ])

      if (cancelled) return

      if (meResult.status === 'fulfilled') {
        setProfile(meResult.value.data)
        setAccount(readMe(meResult.value))
      } else {
        setProfile(null)
        setAccount(null)
        setError(
          apiMessage(
            meResult.reason,
            'Informasi akun gagal dimuat.'
          )
        )
      }

      if (
        statusResult.status === 'fulfilled' &&
        typeof statusResult.value?.data?.google_linked ===
          'boolean'
      ) {
        const googleStatus = statusResult.value.data
        const linked = googleStatus.google_linked

        setGoogleLinked(linked)
        setGoogleEmail(
          linked && typeof googleStatus.google_email === 'string'
            ? googleStatus.google_email
            : null
        )
      } else {
        setGoogleLinked(null)
        setGoogleEmail(null)

        setError(
          (current) =>
            current ||
            'Status Google tidak dapat diperiksa. Muat ulang halaman.'
        )
      }

      setLoading(false)
    }

    load()

    return () => {
      cancelled = true
      mountedRef.current = false
    }
  }, [revision])

  // ==========================================
  // HUBUNGKAN GOOGLE
  // ==========================================

  async function handleCredential(response) {
    if (!googleAvailable || !mountedRef.current || tab !== 'security' || !response?.credential || workingRef.current) {
      return
    }

    const firstLink = googleLinked === false
    if (googleLinked !== false && (googleLinked !== true || !reverifyOpen || unlinkOpen)) return

    if (firstLink && (!linkOpen || !linkPasswordReady)) {
      setError('Masukkan password SIAKAD sebelum menautkan Google.')
      return
    }

    workingRef.current = true
    setWorking(true)
    setError('')
    setNotice('')

    try {
      // Penautan baru membutuhkan dua bukti: password SIAKAD
      // dan ID token Google. Password tidak disimpan oleh frontend.
      // Verifikasi ulang mempertahankan alur API yang sudah ada.
      const result = firstLink
        ? await apiClient.post('/profile/google/link', {
            credential: response.credential,
            current_password: linkPassword,
          })
        : await googleAccountApi.link(response.credential)

      if (result?.data?.google_linked !== true) {
        throw new Error(
          'Respons penautan Google tidak sesuai.'
        )
      }

      if (mountedRef.current) {
        const wasLinked = googleLinked === true

        setGoogleLinked(true)
        setGoogleEmail(
          typeof result.data.google_email === 'string'
            ? result.data.google_email
            : null
        )
        setReverifyOpen(false)
        setLinkOpen(false)
        setWidgetError('')

        setNotice(
          wasLinked
            ? 'Akun Google yang sama berhasil diverifikasi ulang.'
            : 'Akun Google berhasil dihubungkan ke akun SIAKAD kamu.'
        )
      }
    } catch (linkError) {
      if (mountedRef.current) {
        setError(
          apiMessage(
            linkError,
            'Gagal menghubungkan akun Google.'
          )
        )
      }
    } finally {
      workingRef.current = false

      if (mountedRef.current) {
        // Hapus password dari state, termasuk jika verifikasi gagal.
        if (firstLink) {
          setLinkPassword('')
          setShowLinkPassword(false)
        }

        setWorking(false)
      }
    }
  }

  googleCallbackRef.current = handleCredential

  // ==========================================
  // GOOGLE IDENTITY SERVICES
  // ==========================================

  useEffect(() => {
    const showGoogleButton =
      (googleLinked === false && linkOpen && linkPasswordReady) ||
      (googleLinked === true && reverifyOpen && !unlinkOpen)

    if (loading || tab !== 'security' || !showGoogleButton || !googleAvailable) {
      return
    }

    let cancelled = false
    const buttonContainer = googleButtonRef.current

    if (!buttonContainer) return

    setWidgetError('')

    loadGoogleScript()
      .then(() => {
        if (
          cancelled ||
          !buttonContainer.isConnected
        ) {
          return
        }

        buttonContainer.replaceChildren()

        window.google.accounts.id.initialize({
          client_id: GOOGLE_CLIENT_ID,

          callback: (response) => {
            if (!cancelled) googleCallbackRef.current?.(response)
          },

          auto_select: false,
          ux_mode: 'popup',
        })

        window.google.accounts.id.renderButton(
          buttonContainer,
          {
            type: 'standard',
            theme: 'outline',
            size: 'large',
            text: 'continue_with',
            shape: 'rectangular',
            logo_alignment: 'left',
            locale: 'id',
            width: Math.min(
              340,
              buttonContainer.clientWidth || 300
            ),
          }
        )
      })
      .catch((loadError) => {
        if (!cancelled) {
          setWidgetError(loadError.message)
        }
      })

    return () => {
      cancelled = true
      buttonContainer.replaceChildren()
    }
  }, [loading, tab, googleLinked, linkOpen, linkPasswordReady, reverifyOpen, unlinkOpen, googleAvailable])

  // ==========================================
  // LEPASKAN GOOGLE
  // ==========================================

  async function handleUnlink(event) {
    event.preventDefault()

    if (!password || workingRef.current) {
      return
    }

    workingRef.current = true
    setWorking(true)
    setError('')
    setNotice('')

    try {
      const result = await googleAccountApi.unlink(
        password
      )

      if (result?.data?.google_linked !== false) {
        throw new Error(
          'Respons pelepasan Google tidak sesuai.'
        )
      }

      setGoogleLinked(false)
      setGoogleEmail(null)
      setLinkPassword('')
      setShowLinkPassword(false)
      setReverifyOpen(false)
      setUnlinkOpen(false)
      setPassword('')
      setShowPassword(false)
      setWidgetError('')

      setNotice(
        'Hubungan Google berhasil dilepas dari akun SIAKAD.'
      )
    } catch (unlinkError) {
      setError(
        apiMessage(
          unlinkError,
          'Gagal melepaskan akun Google.'
        )
      )
    } finally {
      workingRef.current = false
      setWorking(false)
    }
  }

  // ==========================================
  // TAMPILAN PROFIL
  // ==========================================

  return (
    <section className="siakad-profile">
      <PageHeader
        title="Profil Saya"
        description="Identitas resmi, kontak pribadi, foto, dan keamanan akun dalam satu tempat."
      />

      <ToastNotification notice={notice?{type:'success',title:'Berhasil',message:notice}:null} onClose={()=>setNotice('')}/>

      {error && (
        <div
          className="siakad-profile__error"
          role="alert"
        >
          {error}
        </div>
      )}

      {loading ? <div className="siakad-profile__card" role="status">Memuat informasi profil...</div> : !profile ? <button className="pp-button pp-reload" onClick={()=>setRevision(v=>v+1)}>Muat ulang profil</button> : <>
        <ProfileBanner profile={profile}/>
        <nav className="pp-tabs" aria-label="Bagian profil">
          {[['identity','Biodata'],['security','Keamanan'],['photo','Foto profil']].map(([key,label])=><button type="button" key={key} aria-pressed={tab===key} disabled={working} onClick={()=>{setTab(key);setLinkOpen(false);setError('');setLinkPassword('');setPassword('');setShowPassword(false);setShowLinkPassword(false);setReverifyOpen(false);setUnlinkOpen(false)}}>{label}</button>)}
        </nav>
        {tab==='identity'&&<ProfileIdentity profile={profile} onChange={setProfile} onNotice={setNotice} onReload={()=>setRevision(v=>v+1)}/>}
        {tab==='photo'&&account?.id!=null&&<ProfilePhotoCard userId={account.id} name={profile.user.name}/>}
        {tab==='security'&&<div className="pp-grid pp-security">
          <ProfilePassword disabled={working} onChanged={async()=>{try{await logout()}catch{/* logout clears session in finally */}}}/>
          {/* KEAMANAN GOOGLE */}

          <article className="siakad-profile__card">
            <div className="siakad-profile__section-heading">
              <div
                className="siakad-profile__icon siakad-profile__icon--google"
                aria-hidden="true"
              >
                G
              </div>

              <div>
                <h2>Google & pemulihan akun</h2>
                <p>
                  Kelola akses Google dan pemulihan akun mandiri.
                </p>
              </div>
            </div>

            {googleLinked===null&&<p className="pp-alert" role="status">Status Google tidak tersedia. <button type="button" className="pp-button" onClick={()=>setRevision(v=>v+1)}>Periksa ulang</button></p>}
            <div className="siakad-profile__google-row">
              <div>
                <strong>Akun Google</strong>

                <span>
                  {googleLinked === true
                    ? 'Sudah terhubung'
                    : googleLinked === false
                      ? 'Belum terhubung'
                      : 'Status tidak tersedia'}
                </span>
              </div>

              <span
                className={`siakad-profile__badge ${
                  googleLinked
                    ? 'siakad-profile__badge--success'
                    : ''
                }`}
              >
                {googleLinked === true
                  ? 'Terhubung'
                  : googleLinked === false
                    ? 'Belum ditautkan'
                    : 'Tidak tersedia'}
              </span>
            </div>

            {/* GOOGLE BELUM TERTAUT */}

            {googleLinked === false && (
              <div className="siakad-profile__link-box">
                <p>
                  Tautkan akun Google pribadi untuk masuk dan memulihkan akun sekolah.
                </p>

                {googleAvailable ? (
                  <>
                    <button type="button" className="siakad-profile__button siakad-profile__button--secondary" aria-expanded={linkOpen} disabled={working} onClick={()=>{setLinkOpen(v=>!v);setLinkPassword('');setShowLinkPassword(false);setWidgetError('');setError('')}}>{linkOpen?'Batal menautkan':'Tautkan akun Google'}</button>
                    {linkOpen && <>
                    <div className="siakad-profile__verify">
                      <label htmlFor="siakad-link-password">
                        Konfirmasi password SIAKAD
                      </label>

                      <p className="siakad-profile__footnote">
                        Demi keamanan, masukkan password SIAKAD
                        sebelum memilih akun Google. Password ini
                        hanya digunakan untuk verifikasi penautan.
                      </p>

                      <div className="siakad-profile__password-row">
                        <input
                          id="siakad-link-password"
                          type={showLinkPassword ? 'text' : 'password'}
                          value={linkPassword}
                          onChange={(event) =>
                            setLinkPassword(event.target.value)
                          }
                          autoComplete="current-password"
                          placeholder="Password SIAKAD saat ini"
                          disabled={working}
                        />

                        <button
                          type="button"
                          className="siakad-profile__button siakad-profile__button--secondary"
                          disabled={working}
                          onClick={() =>
                            setShowLinkPassword((value) => !value)
                          }
                          aria-label="Tampilkan atau sembunyikan password penautan"
                        >
                          {showLinkPassword ? 'Sembunyikan' : 'Lihat'}
                        </button>
                      </div>
                    </div>

                    {linkPasswordReady ? (
                      <div
                        ref={googleButtonRef}
                        className="siakad-profile__google-button"
                        aria-label="Hubungkan akun Google"
                      />
                    ) : (
                      <small>
                        Isi password SIAKAD untuk mengaktifkan
                        tombol verifikasi Google.
                      </small>
                    )}

                    {widgetError && (
                      <p
                        className="siakad-profile__inline-error"
                        role="alert"
                      >
                        {widgetError}
                      </p>
                    )}

                    {working && (
                      <small role="status">
                        Memverifikasi akun Google...
                      </small>
                    )}
                    </>}
                  </>
                ) : (
                  <p className="siakad-profile__footnote" role="status">
                    {googleUnavailableMessage(googleEnvironment, 'link')}
                  </p>
                )}
              </div>
            )}

            {/* GOOGLE SUDAH TERTAUT */}

            {googleLinked === true && !unlinkOpen && (
              <div className="siakad-profile__link-box">
                <p>
                  Akun Google sudah tertaut sebagai identitas
                  tambahan untuk pemulihan akun SIAKAD.
                </p>

                <dl className="siakad-profile__details">
                  <div>
                    <dt>Email Google yang terhubung</dt>
                    <dd style={{ overflowWrap: 'anywhere' }}>
                      {googleEmail || 'Email belum tercatat'}
                    </dd>
                  </div>
                </dl>

                {googleEmail ? (
                  <p className="siakad-profile__footnote">
                    Alamat ini berasal dari akun Google yang
                    telah diverifikasi, bukan dari email profil SIAKAD.
                  </p>
                ) : (
                  <p className="siakad-profile__footnote">
                    Tautan Google sebelumnya sudah tersimpan, tetapi
                    email belum tercatat. Verifikasi ulang dengan akun
                    Google yang sama untuk menampilkan emailnya.
                  </p>
                )}

                <div className="siakad-profile__actions">
                  <button
                    type="button"
                    className="siakad-profile__button siakad-profile__button--secondary"
                    disabled={working || !googleAvailable}
                    aria-expanded={reverifyOpen}
                    onClick={() => {
                      setReverifyOpen((value) => !value)
                      setError('')
                      setNotice('')
                      setWidgetError('')
                    }}
                  >
                    {reverifyOpen
                      ? 'Tutup Verifikasi'
                      : 'Verifikasi Ulang Google'}
                  </button>

                  <button
                    type="button"
                    className="siakad-profile__button siakad-profile__button--danger"
                    disabled={working}
                    onClick={() => {
                      setReverifyOpen(false)
                      setUnlinkOpen(true)
                      setPassword('')
                      setShowPassword(false)
                      setError('')
                      setNotice('')
                    }}
                  >
                    Lepaskan Akun Google
                  </button>
                </div>

                {!googleAvailable && <p className="siakad-profile__footnote" role="status">
                  {googleUnavailableMessage(googleEnvironment, 'reverify')} Tautan Google yang sudah tersimpan tetap dipertahankan.
                </p>}

                {reverifyOpen && googleAvailable && (
                  <div className="siakad-profile__link-box">
                    <p>
                      Pilih akun Google yang sama dengan tautan sebelumnya.
                      Akun Google berbeda akan ditolak oleh sistem.
                    </p>

                    {googleAvailable ? (
                      <>
                        <div
                          ref={googleButtonRef}
                          className="siakad-profile__google-button"
                          aria-label="Verifikasi ulang akun Google"
                        />

                        {widgetError && (
                          <p
                            className="siakad-profile__inline-error"
                            role="alert"
                          >
                            {widgetError}
                          </p>
                        )}

                        {working && (
                          <small role="status">
                            Memverifikasi ulang akun Google...
                          </small>
                        )}
                      </>
                    ) : (
                      <p className="siakad-profile__footnote" role="status">
                        {googleUnavailableMessage(googleEnvironment, 'reverify')}
                      </p>
                    )}
                  </div>
                )}
              </div>
            )}

            {/* KONFIRMASI PELEPASAN GOOGLE */}

            {googleLinked === true && unlinkOpen && (
              <form
                className="siakad-profile__unlink"
                onSubmit={handleUnlink}
              >
                <h3>Konfirmasi Pelepasan Akun</h3>

                <p>
                  Setelah dilepas, Google tidak dapat
                  digunakan untuk pemulihan akun ini.
                </p>

                <label htmlFor="siakad-unlink-password">
                  Password SIAKAD saat ini
                </label>

                <div className="siakad-profile__password-row">
                  <input
                    id="siakad-unlink-password"
                    type={
                      showPassword
                        ? 'text'
                        : 'password'
                    }
                    value={password}
                    onChange={(event) =>
                      setPassword(event.target.value)
                    }
                    autoComplete="current-password"
                    required
                    disabled={working}
                  />

                  <button
                    type="button"
                    className="siakad-profile__button siakad-profile__button--secondary"
                    onClick={() =>
                      setShowPassword(
                        (value) => !value
                      )
                    }
                    aria-label="Tampilkan atau sembunyikan password"
                  >
                    {showPassword
                      ? 'Sembunyikan'
                      : 'Lihat'}
                  </button>
                </div>

                <div className="siakad-profile__actions">
                  <button
                    type="button"
                    className="siakad-profile__button siakad-profile__button--secondary"
                    disabled={working}
                    onClick={() => {
                      setUnlinkOpen(false)
                      setPassword('')
                      setError('')
                    }}
                  >
                    Batal
                  </button>

                  <button
                    type="submit"
                    className="siakad-profile__button siakad-profile__button--danger"
                    disabled={working || !password}
                  >
                    {working
                      ? 'Memproses...'
                      : 'Ya, Lepaskan Google'}
                  </button>
                </div>
              </form>
            )}

            {/* INFORMASI KEAMANAN */}

            <div className="siakad-profile__security-note">
              <strong>Informasi keamanan</strong>

              <p>
                Penautan Google tidak mengubah
                username, password, atau role sekolah.
                Pemulihan username dan password melalui Google hanya tersedia untuk akun yang telah tertaut.
              </p>
            </div>
          </article>
        </div>}
      </>}
    </section>
  )
}
