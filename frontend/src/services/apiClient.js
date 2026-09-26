const API_BASE_URL =
  import.meta.env.VITE_API_BASE_URL || 'http://127.0.0.1:8000/api'

const AUTH_TOKEN_KEY = 'siakad_auth_token'

export function getAuthToken() {
  return localStorage.getItem(AUTH_TOKEN_KEY)
}

export function setAuthToken(token) {
  localStorage.setItem(AUTH_TOKEN_KEY, token)
}

export function clearAuthToken() {
  localStorage.removeItem(AUTH_TOKEN_KEY)
}

async function request(path, options = {}) {
  const token = getAuthToken()

  const headers = {
    Accept: 'application/json',
    ...(options.body ? { 'Content-Type': 'application/json' } : {}),
    ...(options.headers || {}),
  }

  if (token) {
    headers.Authorization = `Bearer ${token}`
  }

  const response = await fetch(`${API_BASE_URL}${path}`, {
    credentials: 'include',
    ...options,
    headers,
  })

  let payload = null

  try {
    payload = await response.json()
  } catch {
    payload = null
  }

  if (!response.ok) {
    const error = new Error(
      payload?.message || 'Terjadi kesalahan pada server.',
    )

    error.status = response.status
    error.payload = payload

    throw error
  }

  return payload
}

export const apiClient = {
  get(path) {
    return request(path, {
      method: 'GET',
    })
  },

  post(path, body = {}) {
    return request(path, {
      method: 'POST',
      body: JSON.stringify(body),
    })
  },

  put(path, body = {}) {
    return request(path, {
      method: 'PUT',
      body: JSON.stringify(body),
    })
  },

  delete(path) {
    return request(path, {
      method: 'DELETE',
    })
  },
}