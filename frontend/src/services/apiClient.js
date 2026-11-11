
import { normalizeApiError } from './apiError'

const API_BASE_URL =
  import.meta.env.VITE_API_BASE_URL ||
  'http://127.0.0.1:8000/api'

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

  const isFormData =
    typeof FormData !== 'undefined' &&
    options.body instanceof FormData

  const headers = {
    Accept: 'application/json',
    ...(
      options.body && !isFormData
        ? { 'Content-Type': 'application/json' }
        : {}
    ),
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

  const contentType =
    response.headers.get('content-type') || ''

  if (contentType.includes('application/json')) {
    try {
      payload = await response.json()
    } catch {
      payload = null
    }
  } else if (!response.ok) {
    try {
      const text = await response.text()
      payload = text ? { message: text } : null
    } catch {
      payload = null
    }
  }

  if (!response.ok) {
    const error = new Error(
      payload?.message || 'Terjadi kesalahan pada server.'
    )

    error.status = response.status
    error.payload = payload
    error.errors = payload?.errors || {}

    throw normalizeApiError(error)
  }

  return payload
}

// Mengunduh file PDF atau Excel dari backend.
async function download(path) {
  const token = getAuthToken()

  const headers = {
    Accept: '*/*',
  }

  if (token) {
    headers.Authorization = `Bearer ${token}`
  }

  const response = await fetch(`${API_BASE_URL}${path}`, {
    method: 'GET',
    credentials: 'include',
    headers,
  })

  if (!response.ok) {
    let payload = null

    const contentType =
      response.headers.get('content-type') || ''

    if (contentType.includes('application/json')) {
      try {
        payload = await response.json()
      } catch {
        payload = null
      }
    } else {
      try {
        const text = await response.text()
        payload = text ? { message: text } : null
      } catch {
        payload = null
      }
    }

    const error = new Error(
      payload?.message || 'Gagal mengunduh file.'
    )

    error.status = response.status
    error.payload = payload
    error.errors = payload?.errors || {}

    throw normalizeApiError(error)
  }

  return response.blob()
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

  // Mengirim multipart/form-data untuk upload file.
  // Browser membuat Content-Type beserta boundary secara otomatis.
  postForm(path, body) {
    if (!(body instanceof FormData)) {
      throw new TypeError(
        'postForm membutuhkan objek FormData.'
      )
    }

    return request(path, {
      method: 'POST',
      body,
    })
  },

  // Download file sebagai Blob.
  download,

  delete(path) {
    return request(path, {
      method: 'DELETE',
    })
  },
}
