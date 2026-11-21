import './Footer.css'

const footerNavigation = [
  { label: 'Beranda', href: '#beranda' },
  { label: 'Mengenal Sekolah', href: '#tentang' },
  { label: 'Layanan SIAKAD', href: '#fitur' },
  { label: 'Peran Pengguna', href: '#pengguna' },
  { label: 'FAQ', href: '#faq' },
]

function Footer() {
  const currentYear = new Date().getFullYear()

  return (
    <footer className="footer-v2">
      <div className="footer-v2__container">
        <div className="footer-v2__main">
          <div className="footer-v2__identity">
            <a className="footer-v2__brand" href="#beranda" aria-label="SMA Negeri 1 V Koto Timur — kembali ke beranda">
              <img
                src="/images/branding/logo-sman1-v-koto-timur-removebg-preview.png"
                alt=""
                width="48"
                height="48"
                loading="lazy"
              />
              <span className="footer-v2__brand-copy">
                <strong>SMA Negeri 1 V Koto Timur</strong>
                <span>Sistem Informasi Akademik</span>
              </span>
            </a>
            <p>
              Sekolah menengah atas negeri di Kecamatan V Koto Timur,
              Kabupaten Padang Pariaman, Sumatera Barat.
            </p>
          </div>

          <div className="footer-v2__nav-col">
            <span className="footer-v2__label">JELAJAHI</span>
            <nav aria-label="Navigasi footer">
              {footerNavigation.map((item) => (
                <a key={item.href} href={item.href}>{item.label}</a>
              ))}
            </nav>
          </div>

          <div className="footer-v2__access">
            <span className="footer-v2__label">AKSES SIAKAD</span>
            <p>Untuk siswa, guru, Tata Usaha, dan kepala sekolah yang memiliki akun.</p>
            <a className="footer-v2__login" href="/login">
              Masuk ke SIAKAD
              <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none">
                <path d="M5 12h14m-6-6 6 6-6 6" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round" />
              </svg>
            </a>
          </div>
        </div>

        <div className="footer-v2__bottom">
          <span>© {currentYear} SMA Negeri 1 V Koto Timur</span>
          <span>Informasi akademik sekolah, dalam satu layanan.</span>
        </div>
      </div>
    </footer>
  )
}

export default Footer
