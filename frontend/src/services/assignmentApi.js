import { apiClient, getAuthToken } from './apiClient'

const API_BASE_URL =
  import.meta.env.VITE_API_BASE_URL || 'http://127.0.0.1:8000/api'

function buildQuery(params = {}) {
  const query = new URLSearchParams()

  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') {
      query.set(key, String(value))
    }
  })

  const value = query.toString()
  return value ? `?${value}` : ''
}

function getAttachmentUrl(attachmentId, action) {
  return `${API_BASE_URL}/submission-attachments/${encodeURIComponent(
    attachmentId
  )}/${action}`
}

async function fetchAttachment(attachmentId, action) {
  if (
    attachmentId === undefined ||
    attachmentId === null ||
    String(attachmentId).trim() === ''
  ) {
    throw new Error('ID lampiran jawaban tidak valid.')
  }

  const token = getAuthToken()
  const response = await fetch(getAttachmentUrl(attachmentId, action), {
    method: 'GET',
    credentials: 'include',
    headers: {
      Accept: '*/*',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  })

  if (!response.ok) {
    let message = 'Lampiran jawaban gagal diakses.'

    try {
      const payload = await response.json()
      message = payload?.message || message
    } catch {
      // Respons mungkin bukan JSON.
    }

    throw new Error(message)
  }

  return response
}

function getFilename(response, fallback = 'lampiran-jawaban') {
  const disposition = response.headers.get('content-disposition') || ''
  const utf8Match = disposition.match(/filename\*=UTF-8''([^;]+)/i)

  if (utf8Match?.[1]) {
    try {
      return decodeURIComponent(utf8Match[1].trim())
    } catch {
      return utf8Match[1].trim()
    }
  }

  const filenameMatch = disposition.match(/filename="?([^";]+)"?/i)

  if (filenameMatch?.[1]) {
    return filenameMatch[1].trim()
  }

  return fallback
}

function saveBlob(blob, filename) {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')

  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()

  window.setTimeout(() => URL.revokeObjectURL(url), 1000)
}

async function fetchAssignmentAttachment(attachmentId) {
  if (
    attachmentId === undefined ||
    attachmentId === null ||
    String(attachmentId).trim() === ''
  ) {
    throw new Error('ID lampiran tugas tidak valid.')
  }

  const token = getAuthToken()
  const response = await fetch(
    `${API_BASE_URL}/assignment-attachments/${encodeURIComponent(
      attachmentId
    )}/download`,
    {
      method: 'GET',
      credentials: 'include',
      headers: {
        Accept: '*/*',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
    }
  )

  if (!response.ok) {
    let message = 'Lampiran tugas gagal diunduh.'

    try {
      const payload = await response.json()
      message = payload?.message || message
    } catch {
      // Respons mungkin bukan JSON.
    }

    throw new Error(message)
  }

  return response
}

export const assignmentApi = {
  // Mengambil daftar tugas.
  getAll(params = {}) {
    return apiClient.get(`/assignments${buildQuery(params)}`)
  },

  // Mengambil detail tugas.
  getById(id) {
    return apiClient.get(`/assignments/${id}`)
  },

  // Membuat tugas tanpa lampiran.
  create(payload) {
    return apiClient.post('/assignments', payload)
  },

  // Membuat tugas dengan beberapa lampiran melalui FormData.
  createWithFile(formData) {
    return apiClient.postForm('/assignments', formData)
  },

  // Memperbarui tugas tanpa lampiran baru.
  update(id, payload) {
    return apiClient.put(`/assignments/${id}`, payload)
  },

  // Memperbarui tugas dengan lampiran baru atau penghapusan terpilih.
  updateWithFile(id, formData) {
    formData.set('_method', 'PUT')
    return apiClient.postForm(`/assignments/${id}`, formData)
  },

  // Menghapus tugas.
  delete(id) {
    return apiClient.delete(`/assignments/${id}`)
  },

  // Mengambil daftar submission suatu tugas; mendukung pagination.
  getSubmissions(assignmentId, params = {}) {
    return apiClient.get(
      `/assignments/${assignmentId}/submissions${buildQuery(params)}`
    )
  },

  // Mengambil detail submission berdasarkan ID.
  getSubmissionById(submissionId) {
    return apiClient.get(`/submissions/${submissionId}`)
  },

  // Mengumpulkan jawaban tugas baru.
  submitWithFile(assignmentId, formData) {
    return apiClient.postForm(
      `/assignments/${assignmentId}/submissions`,
      formData
    )
  },

  // Merevisi submission yang sudah ada.
  updateSubmissionWithFile(submissionId, formData) {
    formData.set('_method', 'PUT')
    return apiClient.postForm(`/submissions/${submissionId}`, formData)
  },

  // Membuka lampiran submission melalui endpoint privat.
  async viewSubmissionAttachment(attachmentId) {
    const response = await fetchAttachment(attachmentId, 'view')
    const blob = await response.blob()

    return {
      blob,
      filename: getFilename(response),
      contentType: response.headers.get('content-type') || '',
    }
  },

  // Mengunduh lampiran jawaban siswa.
  async downloadSubmissionAttachment(
    attachmentId,
    filename = 'lampiran-jawaban'
  ) {
    const response = await fetchAttachment(attachmentId, 'download')
    const blob = await response.blob()
    saveBlob(blob, getFilename(response, filename))
  },

  // Mengunduh lampiran tugas berdasarkan ID lampiran.
  async downloadAssignmentAttachment(
    attachmentId,
    filename = 'lampiran-tugas'
  ) {
    const response = await fetchAssignmentAttachment(attachmentId)
    const blob = await response.blob()
    saveBlob(blob, getFilename(response, filename))
  },

  // Mengunduh lampiran tugas melalui endpoint lama berdasarkan ID tugas.
  async download(id, filename = 'lampiran-tugas') {
    const token = getAuthToken()
    const response = await fetch(
      `${API_BASE_URL}/assignments/${encodeURIComponent(id)}/download`,
      {
        method: 'GET',
        credentials: 'include',
        headers: {
          Accept: '*/*',
          ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
      }
    )

    if (!response.ok) {
      let message = 'Lampiran tugas gagal diunduh.'

      try {
        const payload = await response.json()
        message = payload?.message || message
      } catch {
        // Respons mungkin bukan JSON.
      }

      throw new Error(message)
    }

    const blob = await response.blob()
    saveBlob(blob, getFilename(response, filename))
  },
}
