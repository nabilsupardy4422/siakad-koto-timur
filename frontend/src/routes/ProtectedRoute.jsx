import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../context/useAuth'
import LoadingState from '../components/ui/LoadingState'

export default function ProtectedRoute() {
  const {
    status,
    isAuthenticated,
  } = useAuth()

  const location = useLocation()

  if (status === 'loading') {
    return <LoadingState message="Memeriksa sesi pengguna..." />
  }

  if (!isAuthenticated) {
    return (
      <Navigate
        to="/login"
        replace
        state={{ from: location }}
      />
    )
  }

  return <Outlet />
}