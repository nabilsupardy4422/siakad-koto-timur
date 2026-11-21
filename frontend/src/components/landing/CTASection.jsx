import './CTASection.css'

function CTASection() {
  return (
    <section className="cta-v2" id="cta" aria-labelledby="cta-v2-title">
      <div className="cta-v2__container">
        <div className="cta-v2__intro">
          <p className="cta-v2__eyebrow">
            <span className="cta-v2__eyebrow-line" aria-hidden="true" />
            GERBANG AKADEMIK DIGITAL
          </p>

          <h2 className="cta-v2__title" id="cta-v2-title">
            Satu langkah menuju <span>layanan akademik.</span>
          </h2>

          <p className="cta-v2__description">
            Jadwal, presensi, nilai, dan bahan ajar dapat diakses melalui
            SIAKAD sesuai peran dan kewenangan warga sekolah.
          </p>
        </div>

        <div className="cta-v2__actions">
          <span className="cta-v2__actions-label">AKSES UNTUK WARGA SEKOLAH</span>

          <a className="cta-v2__primary" href="/login">
            <span>Masuk ke SIAKAD</span>
            <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none">
              <path d="M5 12h14m-6-6 6 6-6 6" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round" />
            </svg>
          </a>

          <a className="cta-v2__secondary" href="#fitur">
            Pelajari layanan SIAKAD
            <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none">
              <path d="M7 17 17 7M8 7h9v9" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round" />
            </svg>
          </a>

          <p className="cta-v2__note">
            Gunakan akun yang telah diberikan atau diaktifkan oleh sekolah.
          </p>
        </div>

        <div className="cta-v2__bottom">
          <span className="cta-v2__school">SMA Negeri 1 V Koto Timur</span>
          <span className="cta-v2__roles">Siswa <span aria-hidden="true">·</span> Guru <span aria-hidden="true">·</span> Tata Usaha <span aria-hidden="true">·</span> Kepala Sekolah</span>
        </div>
      </div>
    </section>
  )
}

export default CTASection
