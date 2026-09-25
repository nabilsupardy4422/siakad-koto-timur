import { useState } from 'react'

const faqs = [
  {
    question: 'Apa itu SIAKAD Koto Timur?',
    answer:
      'SIAKAD Koto Timur merupakan sistem informasi akademik sekolah yang digunakan untuk mendukung pengelolaan dan akses informasi akademik secara terstruktur.',
  },
  {
    question: 'Siapa saja yang dapat menggunakan SIAKAD?',
    answer:
      'Sistem digunakan oleh siswa, guru, wali kelas, tata usaha, dan kepala sekolah sesuai dengan peran dan kewenangan masing-masing.',
  },
  {
    question: 'Apa yang dapat dilakukan siswa?',
    answer:
      'Siswa dapat mengakses informasi akademik yang berkaitan dengan kegiatan belajarnya, seperti jadwal pelajaran, presensi, nilai, dan bahan ajar.',
  },
  {
    question: 'Apa yang dapat dilakukan guru?',
    answer:
      'Guru mata pelajaran dapat mengelola presensi, memasukkan nilai, melihat siswa yang diajar, dan menyediakan bahan ajar sesuai dengan kewenangannya.',
  },
  {
    question: 'Bagaimana akses Wali Kelas dalam sistem?',
    answer:
      'Wali Kelas merupakan konteks penugasan pada guru. Guru yang mendapatkan penugasan sebagai wali kelas dapat mengakses informasi kelas yang menjadi tanggung jawabnya sesuai dengan tahun akademik.',
  },
  {
    question: 'Apa yang dapat dilakukan Tata Usaha?',
    answer:
      'Tata Usaha memiliki akses untuk mengelola data akademik utama sekolah sesuai dengan kewenangan administratif yang diberikan oleh sistem.',
  },
  {
    question: 'Siapa yang dapat melihat informasi akademik sekolah secara menyeluruh?',
    answer:
      'Kepala Sekolah memiliki akses monitoring terhadap informasi akademik sekolah sesuai dengan ruang lingkup kewenangannya.',
  },
]

function FAQSection() {
  const [openIndex, setOpenIndex] = useState(null)

  const toggleFAQ = (index) => {
    setOpenIndex((currentIndex) =>
      currentIndex === index ? null : index,
    )
  }

  return (
    <section className="faq-section" id="faq">
      <div className="faq-container">
        <div className="faq-intro">
          <div className="section-eyebrow">
            <span />
            FAQ
          </div>

          <h2>
            Pertanyaan yang
            <br />
            <span>sering ditanyakan.</span>
          </h2>

          <p>
            Informasi dasar mengenai SIAKAD Koto Timur
            dan penggunaannya di lingkungan sekolah.
          </p>
        </div>

        <div className="faq-list">
          {faqs.map((faq, index) => {
            const isOpen = openIndex === index

            return (
              <article
                className={`faq-item ${isOpen ? 'open' : ''}`}
                key={faq.question}
              >
                <button
                  className="faq-question"
                  type="button"
                  aria-expanded={isOpen}
                  onClick={() => toggleFAQ(index)}
                >
                  <span className="faq-number">
                    {String(index + 1).padStart(2, '0')}
                  </span>

                  <span className="faq-question-text">
                    {faq.question}
                  </span>

                  <span className="faq-toggle" aria-hidden="true">
                    <span />
                    <span />
                  </span>
                </button>

                <div
                  className="faq-answer"
                  aria-hidden={!isOpen}
                >
                  <p>{faq.answer}</p>
                </div>
              </article>
            )
          })}
        </div>
      </div>
    </section>
  )
}

export default FAQSection