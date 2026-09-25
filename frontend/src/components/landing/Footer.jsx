const footerNavigation = [
    { label: 'Beranda', href: '#beranda' },
    { label: 'Fitur', href: '#fitur' },
    { label: 'Tentang', href: '#tentang' },
    { label: 'FAQ', href: '#faq' },
  ]
  
  function Footer() {
    const currentYear = new Date().getFullYear()
  
    return (
      <footer className="site-footer">
        <div className="footer-container">
          <div className="footer-main">
            <div className="footer-brand">
              <a className="footer-brand-link" href="#beranda">
                <img
                  src="/images/branding/logo-sman1-v-koto-timur-removebg-preview.png"
                  alt="Logo SMA Negeri 1 V Koto Timur"
                />
  
                <span className="footer-brand-copy">
                  <strong>SIAKAD</strong>
                  <span>Koto Timur</span>
                </span>
              </a>
  
              <p>
                Sistem Informasi Akademik
                <br />
                SMA Negeri 1 V Koto Timur
              </p>
            </div>
  
            <div className="footer-navigation">
              <span className="footer-label">Navigasi</span>
  
              <nav aria-label="Navigasi footer">
                {footerNavigation.map((item) => (
                  <a key={item.href} href={item.href}>
                    {item.label}
                  </a>
                ))}
              </nav>
            </div>
  
            <div className="footer-access">
              <span className="footer-label">Akses</span>
  
              <a className="footer-login-link" href="/login">
                <span>Masuk ke SIAKAD</span>
                <span aria-hidden="true">→</span>
              </a>
            </div>
          </div>
  
          <div className="footer-bottom">
            <span>
              © {currentYear} SMA Negeri 1 V Koto Timur
            </span>
  
            <span className="footer-bottom-separator" />
  
            <span>SIAKAD Koto Timur</span>
          </div>
        </div>
      </footer>
    )
  }
  
  export default Footer