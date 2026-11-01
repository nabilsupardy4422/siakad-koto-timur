import { useEffect, useState } from 'react'
import { dashboardApi } from '../../../services/dashboardApi'

export function useDashboard() {
  const [dashboard, setDashboard] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  useEffect(() => {
    let active = true

    async function loadDashboard() {
      setLoading(true)
      setError(null)

      try {
        const response = await dashboardApi.getDashboard()

        if (active) {
          setDashboard(response?.data ?? response)
        }
      } catch (apiError) {
        if (active) {
          setError(apiError)
        }
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
  }, [])

  return {
    dashboard,
    loading,
    error,
  }
}