function CTASection() {
    return (
      <section className="cta-section" id="cta">
        <div className="cta-pattern" aria-hidden="true">
          <span className="cta-pattern-line cta-pattern-line-one" />
          <span className="cta-pattern-line cta-pattern-line-two" />
          <span className="cta-pattern-line cta-pattern-line-three" />
          <span className="cta-pattern-circle" />
        </div>
  
        <div className="cta-container">
          <div className="cta-main">
            <div className="cta-eyebrow">
              <span />
              Akses SIAKAD
            </div>
  
            <h2>
              Satu sistem untuk
              <br />
              <span>kebutuhan akademik.</span>
            </h2>
  
            <p>
              Masuk ke SIAKAD Koto Timur untuk mengakses informasi
              akademik sesuai dengan peran dan kewenangan Anda.
            </p>
          </div>
  
          <div className="cta-actions">
            <a className="cta-primary" href="/login">
              <span>Masuk ke SIAKAD</span>
              <span aria-hidden="true">→</span>
            </a>
  
            <a className="cta-secondary" href="#fitur">
              <span>Jelajahi Fitur</span>
              <span aria-hidden="true">→</span>
            </a>
  
            <div className="cta-school">
              <strong>SMA Negeri 1 V Koto Timur</strong>
              <span>Sistem Informasi Akademik</span>
            </div>
          </div>
        </div>
  
        <div className="cta-watermark" aria-hidden="true">
          <img
            src="/images/branding/logo-sman1-v-koto-timur-removebg-preview.png"
            alt=""
          />
        </div>
      </section>
    )
  }
  
  export default CTASection