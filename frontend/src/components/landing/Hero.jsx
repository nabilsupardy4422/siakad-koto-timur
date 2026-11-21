import './Hero.css'

/**
 * Landing Page — Hero V5 (Clean)
 * Mengembalikan komposisi terang V3 dan menghilangkan semua overlay
 * yang berpotensi bertabrakan dengan ilustrasi. Tidak mengubah auth/API.
 */
function Hero() {
  return (
    <section className="hero-section" id="beranda" aria-labelledby="hero-heading">
      <div className="hero-container">
        <div className="hero-content">
          <div className="hero-eyebrow">
            <span className="hero-eyebrow-rule" aria-hidden="true" />
            <span>SMA Negeri 1 V Koto Timur</span>
          </div>

          <h1 className="hero-title" id="hero-heading">
            Dari V Koto Timur,
            <span> tumbuh bersama.</span>
          </h1>

          <p className="hero-description">
            Kenali sekolah kami dan manfaatkan layanan akademik digital
            untuk mendukung pembelajaran, jadwal, presensi, dan penilaian
            dalam satu lingkungan yang terhubung.
          </p>

          <div className="hero-actions">
            <a className="hero-primary-button" href="/login">
              <span>Masuk ke SIAKAD</span>
              <svg width="19" height="19" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M4.5 12h14m-6-6 6 6-6 6" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
              </svg>
            </a>
            <a className="hero-secondary-button" href="#fitur">
              <span>Jelajahi layanan</span>
              <svg width="19" height="19" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M12 5v14m-6-6 6 6 6-6" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
              </svg>
            </a>
          </div>

          <div className="hero-support">
            <span className="hero-support-marker" aria-hidden="true" />
            <p>
              <strong>Satu portal untuk warga sekolah</strong>
              <span>Siswa · Guru · Tata Usaha · Kepala Sekolah</span>
            </p>
          </div>
        </div>

        {/* Ilustrasi sudah memiliki kartu jadwal/nilai/presensi di dalam gambar.
            Tidak perlu orbit, label, atau caption absolute yang dapat menumpuk. */}
        <figure className="hero-visual">
          <img
            className="hero-avatar-image"
            src="/images/landing/avatar-siswa-siswi-siakad.webp"
            alt="Ilustrasi siswa SMA dan siswi berjilbab putih dengan kartu jadwal, presensi, bahan ajar, dan penilaian"
            width="1122"
            height="1402"
            loading="eager"
            decoding="async"
            fetchPriority="high"
          />
        </figure>
      </div>
    </section>
  )
}

export default Hero
