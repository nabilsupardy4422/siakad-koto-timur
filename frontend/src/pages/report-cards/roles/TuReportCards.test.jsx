import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'

import TuReportCards from './TuReportCards'
import { reportCardApi } from '../../../services/reportCardApi'

vi.mock('../../../services/reportCardApi', () => ({
  reportCardApi: {
    getReportCards: vi.fn(),
    uploadReportCard: vi.fn(),
    downloadReportCard: vi.fn(),
    deleteReportCard: vi.fn(),
  },
}))

vi.mock('../../../../components/ui/LoadingState', () => ({
  default: ({ message }) => <div>{message}</div>,
}))

vi.mock('../../../../components/ui/ErrorState', () => ({
  default: ({ title, message }) => (
    <div>
      <h1>{title}</h1>
      <p>{message}</p>
    </div>
  ),
}))

describe('TuReportCards', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('menampilkan loading saat data rapor dimuat', () => {
    reportCardApi.getReportCards.mockReturnValue(
      new Promise(() => {})
    )

    render(<TuReportCards />)

    expect(
      screen.getByText('Memuat data rapor...')
    ).toBeInTheDocument()
  })

  it('menampilkan data rapor dari API', async () => {
    reportCardApi.getReportCards.mockResolvedValue({
      data: [
        {
          id: 1,
          student: {
            name: 'Siswa Testing',
            nis: '12345',
          },
          academic_year: {
            tahun_mulai: 2026,
            tahun_selesai: 2027,
            semester: 'Ganjil',
          },
          type: 'semester',
          file: {
            name: 'rapor-ganjil.pdf',
          },
          uploaded_by: {
            name: 'Admin TU',
          },
        },
      ],
      meta: {
        last_page: 1,
      },
    })

    render(<TuReportCards />)

    expect(
      await screen.findByText('Siswa Testing')
    ).toBeInTheDocument()

    expect(
      screen.getByText('12345')
    ).toBeInTheDocument()

    expect(
      screen.getByText('rapor-ganjil.pdf')
    ).toBeInTheDocument()
  })

  it('menampilkan pesan ketika data rapor kosong', async () => {
    reportCardApi.getReportCards.mockResolvedValue({
      data: [],
      meta: {
        last_page: 1,
      },
    })

    render(<TuReportCards />)

    expect(
      await screen.findByText('Belum ada data rapor.')
    ).toBeInTheDocument()
  })

  it('menampilkan error ketika API gagal', async () => {
    reportCardApi.getReportCards.mockRejectedValue(
      new Error('Server tidak dapat dihubungi.')
    )

    render(<TuReportCards />)

    expect(
      await screen.findByText('Gagal memuat rapor')
    ).toBeInTheDocument()

    expect(
      screen.getByText('Server tidak dapat dihubungi.')
    ).toBeInTheDocument()
  })

  it('memanggil API dengan parameter pagination', async () => {
    reportCardApi.getReportCards.mockResolvedValue({
      data: [],
      meta: {
        last_page: 1,
      },
    })

    render(<TuReportCards />)

    await waitFor(() => {
      expect(
        reportCardApi.getReportCards
      ).toHaveBeenCalledWith(
        expect.objectContaining({
          page: 1,
          per_page: 15,
        })
      )
    })
  })
})
