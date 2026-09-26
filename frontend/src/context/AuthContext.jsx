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
            role: data.user?.role || null,
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
        role: data.user?.role || null,
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