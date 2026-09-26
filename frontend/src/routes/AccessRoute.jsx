import { Outlet } from 'react-router-dom'
import { useAuth } from '../context/useAuth'
import ForbiddenState from '../components/ui/ForbiddenState'

export default function AccessRoute({
  permission,
  assignment,
}) {
  const {
    permissions,
    assignments,
  } = useAuth()

  if (
    permission &&
    !permissions.includes(permission)
  ) {
    return <ForbiddenState />
  }

  if (
    assignment &&
    !assignments.some(
      (item) => item.type === assignment,
    )
  ) {
    return <ForbiddenState />
  }

  return <Outlet />
}