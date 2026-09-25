function Hero() {
    return (
      <section className="hero-section" id="beranda">
        <div className="hero-container">
          <div className="hero-content">
            <div className="hero-eyebrow">
              <span aria-hidden="true" />
              <span>Sistem Informasi Akademik</span>
            </div>
  
            <h1 className="hero-title">
              Satu sistem untuk
              <br />
              kemajuan
              <br />
              <span>akademik sekolah.</span>
            </h1>
  
            <p className="hero-description">
              SIAKAD Koto Timur menyatukan informasi akademik sekolah dalam
              satu sistem yang terstruktur, mudah diakses, dan sesuai dengan
              kebutuhan setiap warga sekolah.
            </p>
  
            <div className="hero-actions">
              <a className="hero-primary-button" href="/login">
                <span>Masuk ke SIAKAD</span>
                <span aria-hidden="true">→</span>
              </a>
  
              <a className="hero-secondary-button" href="#fitur">
                <span>Jelajahi fitur</span>
                <span aria-hidden="true">→</span>
              </a>
            </div>
  
            <div className="hero-info">
              <div className="hero-info-item">
                <strong>SMA Negeri 1 V Koto Timur</strong>
                <span>Platform informasi akademik sekolah</span>
              </div>
  
              <div className="hero-info-divider" />
  
              <div className="hero-info-item">
                <strong>Terintegrasi</strong>
                <span>Jadwal · Presensi · Penilaian · Materi</span>
              </div>
            </div>
          </div>
  
          <div className="hero-visual">
            <figure className="hero-image-frame">
              <img
                src="/images/school/foto-sekolah-sman1-v-koto-timur.jpg"
                alt="Lingkungan SMA Negeri 1 V Koto Timur"
              />
            </figure>
  
            <div className="hero-image-caption">
              <span className="hero-caption-number">01</span>
  
              <div>
                <strong>SMA Negeri 1 V Koto Timur</strong>
                <span>V Koto Timur · Sumatera Barat</span>
              </div>
            </div>
          </div>
        </div>
      </section>
    )
  }
  
  export default Hero