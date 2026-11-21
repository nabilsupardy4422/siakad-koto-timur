import './AboutSection.css'

/**
 * Pengenalan sekolah pada landing page publik.
 * Sumber identitas: Kemendikdasmen, NPSN 10305568.
 * Tidak membutuhkan API, state autentikasi, atau data akademik privat.
 */
function AboutSection() {
  return (
    <section className="about-section" id="tentang" aria-labelledby="about-heading">
      <div className="about-container">
        <div className="about-copy">
          <p className="about-eyebrow">
            <span aria-hidden="true" />
            Mengenal sekolah
          </p>

          <h2 id="about-heading" className="about-title">
            Lebih dekat dengan <span>SMA Negeri 1 V Koto Timur.</span>
          </h2>

          <p className="about-lead">
            SMA Negeri 1 V Koto Timur merupakan sekolah menengah atas negeri
            di Kecamatan V Koto Timur, Kabupaten Padang Pariaman, Sumatera Barat.
          </p>

          <p className="about-description">
            Halaman ini memperkenalkan identitas sekolah sekaligus menjadi
            gerbang menuju SIAKAD, layanan akademik digital untuk warga sekolah.
          </p>

          <a
            className="about-external-link"
            href="https://sman1-likotim.sch.id/"
            target="_blank"
            rel="noopener noreferrer"
            aria-label="Kunjungi website resmi SMA Negeri 1 V Koto Timur, terbuka di tab baru"
          >
            Kunjungi website sekolah
            <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none">
              <path d="M7 17 17 7M8 7h9v9" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" />
            </svg>
          </a>
        </div>

        <div className="about-identity" aria-label="Identitas resmi sekolah">
          <div className="about-identity-top">
            <div className="about-crest-wrap">
              <img
                src="/images/branding/logo-sman1-v-koto-timur-removebg-preview.png"
                alt="Lambang SMA Negeri 1 V Koto Timur"
                width="78"
                height="78"
                loading="lazy"
                decoding="async"
              />
            </div>
            <div className="about-identity-heading">
              <span className="about-identity-kicker">IDENTITAS SEKOLAH</span>
              <strong>SMAN 1 V KOTO TIMUR</strong>
              <span>Sekolah Menengah Atas Negeri</span>
            </div>
          </div>

          <dl className="about-facts">
            <div>
              <dt>NPSN</dt>
              <dd>10305568</dd>
            </div>
            <div>
              <dt>Lokasi</dt>
              <dd>V Koto Timur, Padang Pariaman</dd>
            </div>
            <div>
              <dt>Provinsi</dt>
              <dd>Sumatera Barat</dd>
            </div>
          </dl>

          <div className="about-identity-foot">
            <span className="about-foot-marker" aria-hidden="true" />
            <span>Identitas sekolah berdasarkan data Kemendikdasmen</span>
          </div>
        </div>
      </div>
    </section>
  )
}

export default AboutSection
