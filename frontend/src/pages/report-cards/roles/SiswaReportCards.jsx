
import { useEffect, useState } from 'react'

import { reportCardApi } from '../../../services/reportCardApi'

import LoadingState from '../../../components/ui/LoadingState'
import ErrorState from '../../../components/ui/ErrorState'

export default function SiswaReportCards() {
  const [reportCards, setReportCards] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [downloadingId, setDownloadingId] = useState(null)

  async function loadReportCards() {
    try {
      setLoading(true)
      setError('')

      const response = await reportCardApi.getReportCards({
        per_page: 15,
      })

      const data = response?.data

      setReportCards(Array.isArray(data) ? data : [])
    } catch (err) {
      setError(
        err.message || 'Gagal mengambil data rapor.'
      )
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    loadReportCards()
  }, [])

  async function handleDownload(reportCard) {
    try {
      setError('')
      setDownloadingId(reportCard.id)

      const blob = await reportCardApi.downloadReportCard(
        reportCard.id
      )

      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')

      link.href = url
      link.download =
        reportCard.file?.name || 'rapor.pdf'

      document.body.appendChild(link)
      link.click()
      link.remove()

      URL.revokeObjectURL(url)
    } catch (err) {
      setError(
        err.message || 'Gagal mengunduh rapor.'
      )
    } finally {
      setDownloadingId(null)
    }
  }

  if (loading) {
    return (
      <LoadingState message="Memuat rapor siswa..." />
    )
  }

  if (error && reportCards.length === 0) {
    return (
      <ErrorState
        title="Rapor tidak tersedia"
        message={error}
      />
    )
  }

  return (
    <section className="report-cards-page">
      <header className="report-cards-header">
        <div>
          <h1>Rapor Saya</h1>
          <p>
            Lihat dan unduh dokumen rapor akademik.
          </p>
        </div>
      </header>

      {error && (
        <div className="report-message report-message-error">
          {error}
        </div>
      )}

      <div className="report-table-wrapper">
        <table className="report-table">
          <thead>
            <tr>
              <th>No</th>
              <th>Tahun Akademik</th>
              <th>Semester</th>
              <th>Jenis Rapor</th>
              <th>Nama File</th>
              <th>Tanggal Upload</th>
              <th>Aksi</th>
            </tr>
          </thead>

          <tbody>
            {reportCards.length === 0 ? (
              <tr>
                <td colSpan="7">
                  Belum ada rapor yang tersedia.
                </td>
              </tr>
            ) : (
              reportCards.map((reportCard, index) => (
                <tr key={reportCard.id}>
                  <td>{index + 1}</td>

                  <td>
                    {reportCard.academic_year?.tahun_mulai}
                    /
                    {reportCard.academic_year?.tahun_selesai}
                  </td>

                  <td>
                    {reportCard.academic_year?.semester || '-'}
                  </td>

                  <td>
                    {reportCard.type || '-'}
                  </td>

                  <td>
                    {reportCard.file?.name || '-'}
                  </td>

                  <td>
                    {reportCard.uploaded_at
                      ? new Date(
                          reportCard.uploaded_at
                        ).toLocaleDateString('id-ID')
                      : '-'}
                  </td>

                  <td>
                    <button
                      type="button"
                      disabled={
                        downloadingId === reportCard.id
                      }
                      onClick={() =>
                        handleDownload(reportCard)
                      }
                    >
                      {downloadingId === reportCard.id
                        ? 'Mengunduh...'
                        : 'Download'}
                    </button>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </section>
  )
}
