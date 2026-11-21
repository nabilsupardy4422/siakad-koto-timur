import { useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import './FeatureHighlights.css'

/**
 * Landing page only. The content below is an illustration of capabilities,
 * NOT authenticated academic data. No backend requests are made here.
 */
const services = [
  {
    id: 'jadwal',
    label: 'Jadwal Pelajaran',
    caption: 'Temukan waktu dan kegiatan belajar.',
    title: 'Jadwal belajar, lebih mudah diikuti.',
    description:
      'Informasi pelajaran tersusun berdasarkan hari, kelas, dan waktu sehingga siswa dan guru lebih mudah melihat agenda pembelajaran.',
    scope: 'Siswa dan guru sesuai hak akses',
  },
  {
    id: 'presensi',
    label: 'Presensi',
    caption: 'Catat dan tinjau kehadiran.',
    title: 'Kehadiran tercatat dengan jelas.',
    description:
      'Guru mencatat kehadiran pada jadwal yang menjadi tanggung jawabnya. Rekap dapat dilihat oleh pengguna sesuai kewenangan.',
    scope: 'Guru, wali kelas, dan siswa sesuai hak akses',
  },
  {
    id: 'penilaian',
    label: 'Penilaian',
    caption: 'Kelola komponen dan rekap hasil.',
    title: 'Informasi hasil belajar lebih tertata.',
    description:
      'Komponen penilaian dan hasil akademik dikelola sesuai mata pelajaran, kelas, dan kewenangan masing-masing pengguna.',
    scope: 'Guru, wali kelas, dan siswa sesuai hak akses',
  },
  {
    id: 'materi',
    label: 'Bahan Ajar',
    caption: 'Materi belajar dalam satu tempat.',
    title: 'Bahan pembelajaran mudah ditemukan.',
    description:
      'Guru dapat menyiapkan materi pembelajaran, sedangkan siswa mengakses dokumen yang tersedia untuk kelas dan pelajarannya.',
    scope: 'Guru dan siswa sesuai hak akses',
  },
]

function Icon({ name, size = 20 }) {
  const common = {
    width: size,
    height: size,
    viewBox: '0 0 24 24',
    fill: 'none',
    stroke: 'currentColor',
    strokeWidth: 1.7,
    strokeLinecap: 'round',
    strokeLinejoin: 'round',
    'aria-hidden': true,
  }
  if (name === 'jadwal') return <svg {...common}><rect x="3" y="5" width="18" height="16" rx="2" /><path d="M7.5 3v4M16.5 3v4M3 10h18M8 14h3M13 14h3" /></svg>
  if (name === 'presensi') return <svg {...common}><circle cx="12" cy="12" r="9" /><path d="m8.2 12 2.6 2.6 5.4-5.4" /></svg>
  if (name === 'penilaian') return <svg {...common}><path d="M4 4v16h16M8 15l3-4 3 2 5-6" /></svg>
  if (name === 'materi') return <svg {...common}><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v16H6.5A2.5 2.5 0 0 0 4 21.5Z" /><path d="M4 5.5v16M9 8h7M9 12h7" /></svg>
  if (name === 'arrow') return <svg {...common}><path d="M5 12h14m-6-6 6 6-6 6" /></svg>
  if (name === 'file') return <svg {...common}><path d="M7 3h7l4 4v14H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z" /><path d="M14 3v5h4M9 12h6M9 16h6" /></svg>
  return null
}

function Preview({ id }) {
  if (id === 'jadwal') {
    return (
      <div className="features-preview-content">
        <div className="features-weekdays" aria-hidden="true">
          {['Sen', 'Sel', 'Rab', 'Kam', 'Jum'].map((day, index) => <span className={index === 0 ? 'is-current' : ''} key={day}>{day}</span>)}
        </div>
        <div className="features-mock-rows">
          {[
            ['07.30', 'Matematika', 'Mata pelajaran'],
            ['09.15', 'Bahasa Indonesia', 'Mata pelajaran'],
            ['11.00', 'Biologi', 'Mata pelajaran'],
          ].map(([time, title, meta]) => (
            <div className="features-schedule-row" key={time}>
              <span className="features-time">{time}</span>
              <span className="features-row-main"><strong>{title}</strong><small>{meta}</small></span>
              <span className="features-row-line" aria-hidden="true" />
            </div>
          ))}
        </div>
      </div>
    )
  }
  if (id === 'presensi') {
    return (
      <div className="features-preview-content">
        <div className="features-presence-legend">
          {['Hadir', 'Izin', 'Sakit', 'Alpa'].map((status, i) => <span key={status}><i className={`features-status-dot status-${i}`} />{status}</span>)}
        </div>
        <div className="features-mock-rows">
          {[
            ['Siswa 01', 'Hadir', 0],
            ['Siswa 02', 'Izin', 1],
            ['Siswa 03', 'Sakit', 2],
          ].map(([name, status, index]) => (
            <div className="features-presence-row" key={name}>
              <span className="features-student-avatar" aria-hidden="true">{String(index + 1).padStart(2, '0')}</span>
              <strong>{name}</strong>
              <span className={`features-status-tag status-${index}`}>{status}</span>
            </div>
          ))}
        </div>
      </div>
    )
  }
  if (id === 'penilaian') {
    return (
      <div className="features-preview-content">
        <div className="features-grade-intro"><span>Komponen nilai</span><span className="features-demo-tag">Contoh daftar</span></div>
        <div className="features-mock-rows">
          {['Tugas', 'Ujian Tengah Semester', 'Ujian Akhir Semester'].map((name, index) => (
            <div className="features-grade-row" key={name}>
              <span className="features-grade-number">{String(index + 1).padStart(2, '0')}</span>
              <strong>{name}</strong>
              <span className="features-grade-placeholder">—</span>
            </div>
          ))}
        </div>
        <p className="features-preview-note">Hasil nilai hanya dapat diakses sesuai izin akun.</p>
      </div>
    )
  }
  return (
    <div className="features-preview-content">
      <div className="features-material-head"><span>Dokumen pembelajaran</span><span className="features-demo-tag">Contoh daftar</span></div>
      <div className="features-mock-rows">
        {[
          ['Ringkasan materi.pdf', 'PDF'],
          ['Bahan pembelajaran.pptx', 'PPTX'],
          ['Latihan soal.pdf', 'PDF'],
        ].map(([title, ext]) => (
          <div className="features-material-row" key={title}>
            <span className="features-material-icon"><Icon name="file" size={17} /></span>
            <strong>{title}</strong>
            <span className="features-material-ext">{ext}</span>
          </div>
        ))}
      </div>
    </div>
  )
}

export default function FeatureHighlights() {
  const [activeIndex, setActiveIndex] = useState(0)
  const tabRefs = useRef([])
  const service = services[activeIndex]

  const handleKeyDown = (event, index) => {
    let next = index
    if (event.key === 'ArrowRight' || event.key === 'ArrowDown') next = (index + 1) % services.length
    else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') next = (index - 1 + services.length) % services.length
    else if (event.key === 'Home') next = 0
    else if (event.key === 'End') next = services.length - 1
    else return
    event.preventDefault()
    setActiveIndex(next)
    tabRefs.current[next]?.focus()
  }

  return (
    <section className="features-section" id="fitur" aria-labelledby="features-title">
      <div className="features-container">
        <header className="features-heading">
          <div>
            <p className="features-eyebrow"><span aria-hidden="true" /> LAYANAN SIAKAD</p>
            <h2 id="features-title">Aktivitas akademik, <span>lebih tertata.</span></h2>
          </div>
          <p className="features-lead">Empat layanan utama membantu warga sekolah mengakses informasi pembelajaran dengan lebih jelas dan sesuai kewenangannya.</p>
        </header>

        <div className="features-explorer">
          <div className="features-picker">
            <div className="features-picker-label"><span>Pilih layanan</span><span>01 — 04</span></div>
            <div role="tablist" aria-label="Jelajahi layanan SIAKAD" aria-orientation="vertical" className="features-tabs">
              {services.map((item, index) => (
                <button
                  key={item.id}
                  type="button"
                  role="tab"
                  id={`features-tab-${item.id}`}
                  aria-selected={activeIndex === index}
                  aria-controls="features-panel"
                  tabIndex={activeIndex === index ? 0 : -1}
                  ref={(node) => { tabRefs.current[index] = node }}
                  className={`features-tab${activeIndex === index ? ' is-active' : ''}`}
                  onClick={() => setActiveIndex(index)}
                  onKeyDown={(event) => handleKeyDown(event, index)}
                >
                  <span className="features-tab-number">{String(index + 1).padStart(2, '0')}</span>
                  <span className="features-tab-icon"><Icon name={item.id} /></span>
                  <span className="features-tab-text"><strong>{item.label}</strong><small>{item.caption}</small></span>
                  <span className="features-tab-arrow"><Icon name="arrow" size={17}/></span>
                </button>
              ))}
            </div>
            <div className="features-picker-footnote">Dirancang untuk kebutuhan siswa, guru, dan pengelola sekolah.</div>
          </div>

          <article className="features-stage" id="features-panel" role="tabpanel" aria-labelledby={`features-tab-${service.id}`} tabIndex={0}>
            <div className="features-stage-top"><span><i aria-hidden="true" /> PRATINJAU LAYANAN</span><span>{String(activeIndex + 1).padStart(2, '0')} / 04</span></div>
            <div className="features-stage-content" key={service.id}>
              <div className="features-stage-copy">
                <h3>{service.title}</h3>
                <p>{service.description}</p>
              </div>
              <div className="features-preview" aria-label={`Contoh tampilan ${service.label}; bukan data sekolah asli`}>
                <div className="features-preview-bar">
                  <span className="features-preview-brand"><span className="features-brand-square"><Icon name={service.id} size={17}/></span>{service.label}</span>
                  <span className="features-preview-sample">ILUSTRASI TAMPILAN</span>
                </div>
                <Preview id={service.id} />
              </div>
              <div className="features-stage-bottom">
                <span>{service.scope}</span>
                <Link to="/login" aria-label="Masuk SIAKAD melalui halaman login">Masuk ke SIAKAD <Icon name="arrow" size={17}/></Link>
              </div>
            </div>
          </article>
        </div>
      </div>
    </section>
  )
}
