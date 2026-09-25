import { useState } from 'react'

const roles = [
  {
    id: '01',
    name: 'Siswa',
    eyebrow: 'Untuk Siswa',
    description:
      'Mendapatkan akses terhadap informasi akademik yang dibutuhkan dalam kegiatan belajar secara terstruktur.',
    items: ['Jadwal pelajaran', 'Rekap nilai', 'Informasi presensi', 'Bahan ajar'],
  },
  {
    id: '02',
    name: 'Guru Mata Pelajaran',
    eyebrow: 'Untuk Guru',
    description:
      'Mendukung pengelolaan kegiatan pembelajaran, presensi, penilaian, dan bahan ajar sesuai mata pelajaran yang diampu.',
    items: ['Kelola presensi', 'Input nilai', 'Data siswa yang diajar', 'Bahan ajar'],
  },
  {
    id: '03',
    name: 'Wali Kelas',
    eyebrow: 'Untuk Wali Kelas',
    description:
      'Membantu wali kelas memantau kondisi akademik siswa dalam kelas yang menjadi tanggung jawabnya.',
    items: ['Rekap nilai kelas', 'Rekap presensi', 'Data siswa', 'Evaluasi non-akademik'],
  },
  {
    id: '04',
    name: 'Tata Usaha',
    eyebrow: 'Untuk Tata Usaha',
    description:
      'Mendukung pengelolaan data akademik utama dan kebutuhan administrasi sekolah secara terpusat.',
    items: ['Data guru', 'Data siswa', 'Data mata pelajaran', 'Data kelas'],
  },
  {
    id: '05',
    name: 'Kepala Sekolah',
    eyebrow: 'Untuk Kepala Sekolah',
    description:
      'Menyediakan akses monitoring terhadap informasi akademik sekolah melalui satu sistem terintegrasi.',
    items: ['Monitoring akademik', 'Informasi sekolah', 'Rekap akademik'],
  },
]

function RoleOverview() {
  const [activeRole, setActiveRole] = useState(roles[0])

  return (
    <section className="roles-section" id="pengguna">
      <div className="roles-container">
        <div className="roles-heading">
          <div className="section-eyebrow">
            <span />
            Untuk Siapa?
          </div>

          <h2>
            Satu sistem,
            <br />
            <span>kebutuhan berbeda.</span>
          </h2>

          <p>
            SIAKAD Koto Timur dirancang untuk mendukung kebutuhan
            informasi akademik setiap pengguna sesuai dengan
            peran dan kewenangannya.
          </p>
        </div>

        <div className="roles-directory">
          <div className="roles-list" aria-label="Peran pengguna">
            {roles.map((role) => {
              const isActive = activeRole.id === role.id

              return (
                <button
                  className={`role-item ${isActive ? 'active' : ''}`}
                  key={role.id}
                  type="button"
                  onClick={() => setActiveRole(role)}
                  aria-pressed={isActive}
                >
                  <span className="role-number">{role.id}</span>

                  <span className="role-name">
                    {role.name}
                  </span>

                  <span className="role-arrow" aria-hidden="true">
                    →
                  </span>
                </button>
              )
            })}
          </div>

          <div className="role-detail">
            <div className="role-detail-top">
              <span className="role-detail-label">
                {activeRole.eyebrow}
              </span>

              <span className="role-detail-index">
                {activeRole.id}
              </span>
            </div>

            <h3>{activeRole.name}</h3>

            <p className="role-description">
              {activeRole.description}
            </p>

            <div className="role-capabilities">
              <span className="role-capabilities-label">
                Akses utama
              </span>

              <div className="role-capabilities-list">
                {activeRole.items.map((item) => (
                  <span key={item}>{item}</span>
                ))}
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  )
}

export default RoleOverview