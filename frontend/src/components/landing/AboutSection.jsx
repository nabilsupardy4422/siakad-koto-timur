function AboutSection() {
    return (
      <section className="about-section" id="tentang">
        <div className="about-container">
          <div className="about-intro">
            <div className="section-eyebrow">
              <span />
              Tentang SIAKAD
            </div>
  
            <h2>
              Teknologi yang
              <br />
              mendukung aktivitas
              <br />
              <span>akademik sekolah.</span>
            </h2>
          </div>
  
          <div className="about-main">
            <div className="about-copy">
              <p className="about-lead">
                SIAKAD Koto Timur merupakan platform informasi akademik
                yang dirancang untuk membantu pengelolaan dan akses
                informasi akademik secara lebih terstruktur.
              </p>
  
              <p className="about-description">
                Sistem menghubungkan kebutuhan siswa, guru, wali kelas,
                tata usaha, dan pimpinan sekolah dalam satu lingkungan
                informasi akademik.
              </p>
            </div>
  
            <div className="about-school">
              <img
                src="/images/branding/logo-sman1-v-koto-timur-removebg-preview.png"
                alt="Logo SMA Negeri 1 V Koto Timur"
              />
  
              <div className="about-school-info">
                <strong>SMA Negeri 1 V Koto Timur</strong>
                <span>Sistem Informasi Akademik</span>
              </div>
            </div>
          </div>
  
          <div className="about-highlights">
            <div className="about-highlight">
              <span className="about-highlight-number">01</span>
  
              <div>
                <strong>Terintegrasi</strong>
                <span>
                  Informasi akademik berada dalam satu sistem.
                </span>
              </div>
            </div>
  
            <div className="about-highlight">
              <span className="about-highlight-number">02</span>
  
              <div>
                <strong>Sesuai Peran</strong>
                <span>
                  Akses disesuaikan dengan kewenangan pengguna.
                </span>
              </div>
            </div>
  
            <div className="about-highlight">
              <span className="about-highlight-number">03</span>
  
              <div>
                <strong>Terstruktur</strong>
                <span>
                  Data akademik tersusun agar mudah dikelola dan diakses.
                </span>
              </div>
            </div>
          </div>
        </div>
      </section>
    )
  }
  
  export default AboutSection