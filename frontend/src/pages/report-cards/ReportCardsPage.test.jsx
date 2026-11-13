import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'

import ReportCardsPage from './ReportCardsPage'
import { useAuth } from '../../context/useAuth'

import TuReportCards from './roles/TuReportCards'
import SiswaReportCards from './roles/SiswaReportCards'

vi.mock('../../context/useAuth', () => ({
  useAuth: vi.fn(),
}))

vi.mock('../../components/ui/LoadingState', () => ({
  default: ({ message }) => <div>{message}</div>,
}))

vi.mock('../../components/ui/ErrorState', () => ({
  default: ({ title, message }) => (
    <div>
      <h1>{title}</h1>
      <p>{message}</p>
    </div>
  ),
}))

vi.mock('./roles/TuReportCards', () => ({
  default: () => <div>Halaman Rapor TU</div>,
}))

vi.mock('./roles/SiswaReportCards', () => ({
  default: () => <div>Halaman Rapor Siswa</div>,
}))

describe('ReportCardsPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('menampilkan loading saat autentikasi berlangsung', () => {
    useAuth.mockReturnValue({
      status: 'loading',
      user: null,
    })

    render(<ReportCardsPage />)

    expect(
      screen.getByText('Memeriksa hak akses rapor...')
    ).toBeInTheDocument()
  })

  it('menolak pengguna yang belum login', () => {
    useAuth.mockReturnValue({
      status: 'guest',
      user: null,
    })

    render(<ReportCardsPage />)

    expect(
      screen.getByText('Sesi tidak tersedia')
    ).toBeInTheDocument()
  })

  it('menampilkan halaman rapor TU', () => {
    useAuth.mockReturnValue({
      status: 'authenticated',
      user: {
        role: {
          code: 'TU',
        },
      },
    })

    render(<ReportCardsPage />)

    expect(
      screen.getByText('Halaman Rapor TU')
    ).toBeInTheDocument()
  })

  it('menampilkan halaman rapor siswa', () => {
    useAuth.mockReturnValue({
      status: 'authenticated',
      user: {
        role: {
          code: 'SISWA',
        },
      },
    })

    render(<ReportCardsPage />)

    expect(
      screen.getByText('Halaman Rapor Siswa')
    ).toBeInTheDocument()
  })

  it('menolak role yang tidak memiliki halaman rapor', () => {
    useAuth.mockReturnValue({
      status: 'authenticated',
      user: {
        role: {
          code: 'GURU',
        },
      },
    })

    render(<ReportCardsPage />)

    expect(
      screen.getByText('Akses tidak tersedia')
    ).toBeInTheDocument()
  })

  it('menolak pengguna tanpa informasi role', () => {
    useAuth.mockReturnValue({
      status: 'authenticated',
      user: {
        role: null,
      },
    })

    render(<ReportCardsPage />)

    expect(
      screen.getByText('Role tidak ditemukan')
    ).toBeInTheDocument()
  })
})
