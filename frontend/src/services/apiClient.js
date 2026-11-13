
import { normalizeApiError } from './apiError'

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

/**
 * Membuat header request.
 * FormData tidak menggunakan Content-Type manual
 * agar browser membuat multipart boundary.
 */
function buildHeaders(options = {}) {
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

  return headers
}

/**
 * Membaca response JSON atau text.
 */
async function readResponsePayload(response) {
  const contentType =
    response.headers.get('content-type') || ''

  if (contentType.includes('application/json')) {
    try {
      return await response.json()
    } catch {
      return null
    }
  }

  if (!response.ok) {
    try {
      const text = await response.text()

      return text
        ? { message: text }
        : null
    } catch {
      return null
    }
  }

  return null
}

/**
 * Membuat error dari response server.
 */
function createApiError(response, payload) {
  const error = new Error(
    payload?.message ||
      'Terjadi kesalahan pada server.'
  )

  error.status = response.status
  error.payload = payload
  error.errors = payload?.errors || {}

  return normalizeApiError(error)
}

/**
 * Request JSON.
 */
async function request(path, options = {}) {
  const headers = buildHeaders(options)

  const response = await fetch(
    `${API_BASE_URL}${path}`,
    {
      credentials: 'include',
      ...options,
      headers,
    }
  )

  const payload = await readResponsePayload(response)

  if (!response.ok) {
    throw createApiError(response, payload)
  }

  return payload
}

/**
 * Request file/blob.
 *
 * Digunakan untuk:
 * - Download template Excel.
 * - Export jadwal Excel.
 */
async function requestBlob(path, options = {}) {
  const headers = buildHeaders(options)

  // Request download tidak membutuhkan Accept JSON.
  headers.Accept = '*/*'

  const response = await fetch(
    `${API_BASE_URL}${path}`,
    {
      credentials: 'include',
      ...options,
      headers,
    }
  )

  if (!response.ok) {
    const payload =
      await readResponsePayload(response)

    throw createApiError(response, payload)
  }

  return response.blob()
}

/**
 * API Client.
 */
export const apiClient = {
  /**
   * GET JSON.
   */
  get(path) {
    return request(path, {
      method: 'GET',
    })
  },

  /**
   * POST JSON.
   */
  post(path, body = {}) {
    return request(path, {
      method: 'POST',
      body: JSON.stringify(body),
    })
  },

  /**
   * PUT JSON.
   */
  put(path, body = {}) {
    return request(path, {
      method: 'PUT',
      body: JSON.stringify(body),
    })
  },

  /**
   * POST multipart/form-data.
   *
   * Digunakan untuk upload file Excel.
   */
  postForm(path, body) {
    if (
      typeof FormData === 'undefined' ||
      !(body instanceof FormData)
    ) {
      throw new TypeError(
        'postForm membutuhkan objek FormData.'
      )
    }

    return request(path, {
      method: 'POST',
      body,
    })
  },

  /**
   * GET Blob.
   *
   * Digunakan untuk download file.
   */
  getBlob(path) {
    return requestBlob(path, {
      method: 'GET',
    })
  },

  /**
   * DELETE.
   */
  delete(path) {
    return request(path, {
      method: 'DELETE',
    })
  },
}
