import { useEffect, useMemo, useState } from 'react'
import { AuthContext } from './authContext'
import { authApi } from '../services/authApi'
import {
  clearAuthToken,
  getAuthToken,
  setAuthToken,
} from '../services/apiClient'

const guestState = {
  status: 'guest',
  user: null,
  role: null,
  permissions: [],
  assignments: [],
}

function authenticatedState(data) {
  return {
    status: 'authenticated',
    user: data.user || null,
    role: data.role || null,
    permissions: data.permissions || [],
    assignments: data.assignments || [],
  }
}

export function AuthProvider({ children }) {
  const [auth, setAuth] = useState({
    status: 'loading',
    user: null,
    role: null,
    permissions: [],
    assignments: [],
  })

  useEffect(() => {
    let mounted = true

    async function restoreSession() {
      const token = getAuthToken()

      if (!token) {
        if (mounted) {
          setAuth(guestState)
        }
        return
      }

      try {
        const response = await authApi.me()
        const data = response?.data || {}

        if (!mounted) {
          return
        }

        setAuth(
          authenticatedState({
            user: data.user,
            role: data.role || data.user?.role || null,
            permissions: data.permissions || [],
            assignments: data.assignments || [],
          }),
        )
      } catch (error) {
        if (!mounted) {
          return
        }

        if (error.status === 401) {
          clearAuthToken()
        }

        setAuth(guestState)
      }
    }

    restoreSession()

    return () => {
      mounted = false
    }
  }, [])

  // Refresh only assignment metadata for Guru. API remains authoritative on every request.
  const isGuru = auth.status === 'authenticated' && (typeof auth.role === 'string' ? auth.role : auth.role?.code) === 'GURU'
  const sessionUserId = auth.user?.id
  useEffect(() => {
    if (!isGuru) return
    const token = getAuthToken()
    let active = true, pending = false
    async function refreshAssignments() {
      if (!active || pending || !token || token !== getAuthToken()) return
      pending = true
      let next = []
      try {
        const response = await authApi.me()
        if (String(response?.data?.user?.id) === String(sessionUserId)) {
          next = Array.isArray(response.data.assignments) ? response.data.assignments : []
        }
      } catch {
        // Fail closed for additional menu privileges; do not change core login state.
      } finally {
        pending = false
      }
      if (!active || token !== getAuthToken()) return
      setAuth(old => old.status === 'authenticated' && old.user?.id === sessionUserId && JSON.stringify(old.assignments) !== JSON.stringify(next) ? {...old, assignments: next} : old)
    }
    const onVisible = () => { if (!document.hidden) refreshAssignments() }
    window.addEventListener('focus', refreshAssignments)
    document.addEventListener('visibilitychange', onVisible)
    const timer = window.setInterval(onVisible, 30000)
    return () => { active = false; window.clearInterval(timer); window.removeEventListener('focus', refreshAssignments); document.removeEventListener('visibilitychange', onVisible) }
  }, [isGuru, sessionUserId])

  async function login(username, password) {
    const response = await authApi.login({
      username,
      password,
    })

    const data = response?.data || {}

    if (!data.token) {
      throw new Error('Token autentikasi tidak diterima dari server.')
    }

    setAuthToken(data.token)

    setAuth(
      authenticatedState({
        user: data.user,
        role: data.role || data.user?.role || null,
        permissions: data.permissions || [],
        assignments: data.assignments || [],
      }),
    )

    return response
  }

  async function logout() {
    try {
      await authApi.logout()
    } finally {
      clearAuthToken()
      setAuth(guestState)
    }
  }

  const value = useMemo(
    () => ({
      ...auth,
      isAuthenticated: auth.status === 'authenticated',
      login,
      logout,
    }),
    [auth],
  )

  return (
    <AuthContext.Provider value={value}>
      {children}
    </AuthContext.Provider>
  )
}