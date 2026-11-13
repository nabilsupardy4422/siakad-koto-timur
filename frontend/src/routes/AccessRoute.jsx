
import { Outlet } from 'react-router-dom'
import { useAuth } from '../context/useAuth'
import ForbiddenState from '../components/ui/ForbiddenState'

export default function AccessRoute({
  role,
  permission,
  assignment,
}) {
  const {
    role: userRole,
    permissions = [],
    assignments = [],
  } = useAuth()

  const roleValue =
    typeof userRole === 'string'
      ? userRole
      : userRole?.code ||
        userRole?.name ||
        userRole?.nama ||
        ''

  const normalizedRole = String(roleValue)
    .trim()
    .toUpperCase()

  if (role && normalizedRole !== role.toUpperCase()) {
    return <ForbiddenState />
  }

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
