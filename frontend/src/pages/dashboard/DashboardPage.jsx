
import { useEffect, useState } from 'react'

import { useAuth } from '../../context/useAuth'
import LoadingState from '../../components/ui/LoadingState'
import ErrorState from '../../components/ui/ErrorState'

import { dashboardApi } from '../../services/dashboardApi'

import TUDashboard from './roles/TUDashboard'
import GuruDashboard from './roles/GuruDashboard'
import KepalaSekolahDashboard from './roles/KepalaSekolahDashboard'
import SiswaDashboard from './roles/SiswaDashboard'

export default function DashboardPage() {
  const { user, role } = useAuth()
  const roleCode = role?.code || user?.role?.code || null

  const [dashboard, setDashboard] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  useEffect(() => {
    let active = true

    async function loadDashboard() {
      if (roleCode !== 'TU') {
        setLoading(false)
        return
      }

      setLoading(true)
      setError(null)

      try {
        const response = await dashboardApi.getDashboard()

        if (!active) return

        setDashboard(response?.data ?? null)
      } catch (apiError) {
        if (!active) return

        setError(apiError)
      } finally {
        if (active) {
          setLoading(false)
        }
      }
    }

    loadDashboard()

    return () => {
      active = false
    }
  }, [roleCode])

  if (roleCode === 'GURU') {
    return <GuruDashboard />
  }

  if (roleCode === 'KEPALA_SEKOLAH') {
    return <KepalaSekolahDashboard />
  }

  if (roleCode === 'SISWA') {
    return <SiswaDashboard />
  }

  if (loading) {
    return <LoadingState message="Memuat dashboard..." />
  }

  if (error) {
    return (
      <ErrorState
        message={error.message || 'Dashboard tidak dapat dimuat.'}
      />
    )
  }

  if (!dashboard) {
    return (
      <ErrorState message="Data dashboard tidak tersedia." />
    )
  }

  if (roleCode === 'TU' && dashboard.role === 'TU') {
    return (
      <TUDashboard
        user={user}
        dashboard={dashboard}
      />
    )
  }

  return (
    <ErrorState message="Role pengguna tidak dikenali untuk dashboard." />
  )
}
