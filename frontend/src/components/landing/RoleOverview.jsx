import './RoleOverview.css'

/**
 * Informasi publik tentang konteks akses SIAKAD.
 * Wali Kelas adalah penugasan tambahan pada Guru, BUKAN role akun kelima.
 * Tidak menggunakan API ataupun state autentikasi.
 */
const roleProfiles = [
  {
    number: '01',
    name: 'Siswa',
    focus: 'Belajar dengan informasi yang jelas.',
    description:
      'Melihat jadwal, presensi, nilai, bahan ajar, tugas, dan rapor milik sendiri.',
    scope: 'Data akademik pribadi',
  },
  {
    number: '02',
    name: 'Guru',
    focus: 'Mengelola kegiatan mengajar.',
    description:
      'Mengelola presensi, materi, tugas, dan penilaian sesuai kelas serta mata pelajaran yang ditugaskan.',
    scope: 'Kelas dan pelajaran yang diajar',
  },
  {
    number: '03',
    name: 'Tata Usaha',
    focus: 'Menata administrasi akademik.',
    description:
      'Mengelola akun pengguna dan data akademik sekolah sesuai kewenangan administratif.',
    scope: 'Administrasi sekolah',
  },
  {
    number: '04',
    name: 'Kepala Sekolah',
    focus: 'Memantau gambaran akademik.',
    description:
      'Melihat informasi, ringkasan, dan laporan akademik sekolah sesuai hak akses pemantauan.',
    scope: 'Pemantauan tingkat sekolah',
  },
]

function RoleOverview() {
  return (
    <section className="roles-section" id="pengguna" aria-labelledby="roles-title">
      <div className="roles-container">
        <header className="roles-heading">
          <div className="roles-heading-main">
            <p className="roles-eyebrow">
              <span className="roles-eyebrow-line" aria-hidden="true" />
              PENGGUNA SIAKAD
            </p>
            <h2 id="roles-title">
              Setiap peran,
              <span> ruang kerja yang tepat.</span>
            </h2>
          </div>
          <div className="roles-heading-aside">
            <p>
              Setiap warga sekolah mendapatkan informasi dan layanan yang relevan
              dengan tanggung jawabnya. Semua tetap berada dalam satu sistem.
            </p>
            <div className="roles-role-count" aria-label="Empat peran akun utama">
              <strong>04</strong>
              <span>Peran akun utama</span>
            </div>
          </div>
        </header>

        <div className="roles-matrix" aria-label="Ruang akses empat peran SIAKAD">
          {roleProfiles.map((role) => (
            <article className="roles-persona" key={role.number}>
              <div className="roles-persona-meta">
                <span className="roles-persona-number">{role.number}</span>
                <span className="roles-persona-name">{role.name}</span>
              </div>

              <h3>{role.focus}</h3>
              <p className="roles-persona-description">{role.description}</p>

              <div className="roles-persona-scope">
                <span>Lingkup akses</span>
                <strong>{role.scope}</strong>
              </div>
            </article>
          ))}
        </div>

        <aside className="roles-assignment" aria-label="Penjelasan tugas Wali Kelas">
          <div className="roles-assignment-heading">
            <span className="roles-assignment-kicker">PENUGASAN TAMBAHAN</span>
            <h3>Guru dapat menjadi Wali Kelas.</h3>
          </div>
          <p>
            Guru yang mendapatkan penugasan Wali Kelas dapat memantau data,
            rekap presensi, nilai, dan evaluasi pada kelas yang menjadi tanggung
            jawabnya. <strong>Wali Kelas bukan akun atau role terpisah.</strong>
          </p>
        </aside>
      </div>
    </section>
  )
}

export default RoleOverview
