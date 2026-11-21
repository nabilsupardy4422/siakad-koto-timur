import './FAQSection.css'

// FAQ publik. Konten mengikuti kontrak autentikasi dan role SIAKAD.
// Wali Kelas adalah penugasan Guru, bukan akun/role terpisah.
const questions = [
  {
    question: 'Bagaimana cara masuk ke SIAKAD?',
    answer:
      'Pilih tombol “Masuk SIAKAD”, lalu gunakan akun sekolah yang telah disediakan. Akses ke dalam sistem mengikuti status akun dan kewenangan pengguna.',
  },
  {
    question: 'Siapa saja yang dapat menggunakan SIAKAD?',
    answer:
      'SIAKAD digunakan oleh Siswa, Guru, Tata Usaha, dan Kepala Sekolah. Guru yang ditugaskan sebagai Wali Kelas mendapatkan akses tambahan sesuai kelas dan tahun akademik yang menjadi tanggung jawabnya.',
  },
  {
    question: 'Informasi apa yang bisa diakses siswa?',
    answer:
      'Siswa dapat mengakses data akademiknya sendiri, antara lain jadwal, presensi, nilai, bahan ajar, tugas, dan rapor sesuai fitur yang tersedia serta hak akses akunnya.',
  },
  {
    question: 'Apakah Wali Kelas memiliki akun yang berbeda dari Guru?',
    answer:
      'Tidak. Wali Kelas merupakan penugasan tambahan pada akun Guru, bukan role akun terpisah. Akses Wali Kelas dibatasi pada kelas dan tahun akademik yang ditugaskan.',
  },
  {
    question: 'Apa yang harus dilakukan jika akun belum aktif atau lupa kata sandi?',
    answer:
      'Hubungi Tata Usaha sekolah. Pengelolaan status akun, aktivasi, dan pengaturan ulang kata sandi dilakukan oleh pihak yang memiliki kewenangan administratif.',
  },
  {
    question: 'Apakah semua pengguna bisa melihat data akademik siswa lain?',
    answer:
      'Tidak. Akses informasi mengikuti role dan lingkup kewenangan. Siswa hanya dapat melihat data miliknya sendiri, sedangkan Guru dan petugas sekolah dibatasi sesuai penugasan dan izin yang berlaku.',
  },
]

function FAQSection() {
  return (
    <section className="faq-v3" id="faq" aria-labelledby="faq-v3-title">
      <div className="faq-v3__container">
        <header className="faq-v3__intro">
          <p className="faq-v3__eyebrow">
            <span className="faq-v3__eyebrow-line" aria-hidden="true" />
            PANDUAN SIAKAD
          </p>
          <h2 id="faq-v3-title">
            Ada pertanyaan?
            <span> Temukan jawabannya.</span>
          </h2>
          <p className="faq-v3__lead">
            Informasi seputar akun, layanan, dan hak akses SIAKAD
            SMA Negeri 1 V Koto Timur, dijelaskan dengan sederhana.
          </p>
        </header>

        <figure className="faq-v3__visual">
          <div className="faq-v3__visual-top">
            <span>BERSAMA MENCARI JAWABAN</span>
            <span className="faq-v3__visual-index">01 / 01</span>
          </div>
          <img
            src="/images/landing/avatar-faq-3d-siswi.webp"
            alt="Ilustrasi 3D siswi SMA berjilbab sedang memikirkan pertanyaan di depan laptop"
            width="1393"
            height="1073"
            loading="lazy"
            decoding="async"
          />
          <figcaption className="faq-v3__visual-caption">
            <span className="faq-v3__visual-dot" aria-hidden="true" />
            Belajar memahami layanan sekolah dengan lebih mudah.
          </figcaption>
        </figure>

        <div className="faq-v3__content">
          <div className="faq-v3__content-header">
            <span>HAL YANG SERING DITANYAKAN</span>
            <span>{String(questions.length).padStart(2, '0')} PERTANYAAN</span>
          </div>

          <div className="faq-v3__list">
            {questions.map((item, index) => (
              <details className="faq-v3__item" key={item.question}>
                <summary className="faq-v3__question">
                  <span className="faq-v3__number">
                    {String(index + 1).padStart(2, '0')}
                  </span>
                  <span className="faq-v3__question-text">{item.question}</span>
                  <span className="faq-v3__toggle" aria-hidden="true">
                    <span />
                    <span />
                  </span>
                </summary>
                <div className="faq-v3__answer">
                  <p>{item.answer}</p>
                </div>
              </details>
            ))}
          </div>

          <aside className="faq-v3__help" aria-label="Informasi bantuan akun">
            <div className="faq-v3__help-copy">
              <strong>Masih memerlukan bantuan akun?</strong>
              <p>
                Untuk aktivasi akun atau kendala kata sandi, silakan
                menghubungi Tata Usaha sekolah.
              </p>
            </div>
            <a href="/login" className="faq-v3__help-link">
              Masuk SIAKAD <span aria-hidden="true">↗</span>
            </a>
          </aside>

          <p className="faq-v3__footnote">
            Fitur dan informasi yang tersedia mengikuti kewenangan akun masing-masing.
          </p>
        </div>
      </div>
    </section>
  )
}

export default FAQSection
