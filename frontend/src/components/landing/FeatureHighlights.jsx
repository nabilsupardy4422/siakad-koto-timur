const features = [
    {
      number: '01',
      title: 'Jadwal Pelajaran',
      description:
        'Akses informasi jadwal pembelajaran secara terstruktur berdasarkan kelas dan mata pelajaran.',
      icon: (
        <svg
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.7"
          aria-hidden="true"
        >
          <rect x="3" y="4" width="18" height="17" rx="2" />
          <path d="M8 2v4M16 2v4M3 9h18" />
          <path d="M8 13h3M13 13h3M8 17h3" />
        </svg>
      ),
    },
    {
      number: '02',
      title: 'Presensi',
      description:
        'Kelola dan pantau kehadiran siswa dalam proses pembelajaran secara lebih terstruktur.',
      icon: (
        <svg
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.7"
          aria-hidden="true"
        >
          <circle cx="12" cy="12" r="9" />
          <path d="m8 12 2.5 2.5L16 9" />
        </svg>
      ),
    },
    {
      number: '03',
      title: 'Penilaian',
      description:
        'Kelola nilai dan lihat rekap hasil akademik sesuai peran dan kewenangan pengguna.',
      icon: (
        <svg
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.7"
          aria-hidden="true"
        >
          <path d="M4 19V5M4 19h17" />
          <path d="m7 15 3-4 3 2 5-7" />
        </svg>
      ),
    },
    {
      number: '04',
      title: 'Bahan Ajar',
      description:
        'Guru dapat menyediakan bahan pembelajaran agar materi dapat diakses dengan lebih terorganisir.',
      icon: (
        <svg
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.7"
          aria-hidden="true"
        >
          <path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v16H6.5A2.5 2.5 0 0 0 4 21.5z" />
          <path d="M4 5.5v16M8 7h8M8 11h8" />
        </svg>
      ),
    },
  ]
  
  function FeatureHighlights() {
    return (
      <section className="features-section" id="fitur">
        <div className="features-container">
          <div className="features-heading">
            <div className="section-eyebrow">
              <span aria-hidden="true" />
              <span>Fitur utama</span>
            </div>
  
            <div className="features-heading-layout">
              <h2>
                Kebutuhan akademik
                <br />
                <span>dalam satu sistem.</span>
              </h2>
  
              <p>
                SIAKAD Koto Timur menyatukan berbagai aktivitas akademik
                dalam satu lingkungan informasi yang terstruktur dan
                sesuai dengan kebutuhan pengguna.
              </p>
            </div>
          </div>
  
          <div className="features-list">
            {features.map((feature) => (
              <article className="feature-item" key={feature.number}>
                <div className="feature-number">
                  {feature.number}
                </div>
  
                <div className="feature-icon" aria-hidden="true">
                  {feature.icon}
                </div>
  
                <div className="feature-content">
                  <h3>{feature.title}</h3>
  
                  <p>{feature.description}</p>
                </div>
  
                <span className="feature-link" aria-hidden="true">
                  →
                </span>
              </article>
            ))}
          </div>
        </div>
      </section>
    )
  }
  
  export default FeatureHighlights