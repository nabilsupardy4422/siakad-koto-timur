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

  const result = query.toString()
  return result ? `?${result}` : ''
}

async function downloadFile(path, fallbackName = 'bahan-ajar') {
  const token = getAuthToken()

  const response = await fetch(`${API_BASE_URL}${path}`, {
    method: 'GET',
    credentials: 'include',
    headers: {
      Accept: '*/*',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  })

  if (!response.ok) {
    let payload = null

    try {
      payload = await response.json()
    } catch {
      // Respons unduhan mungkin bukan JSON.
    }

    const error = new Error(
      payload?.message || 'File gagal diunduh.',
    )
    error.status = response.status
    throw error
  }

  const blob = await response.blob()
  const disposition = response.headers.get('content-disposition') || ''
  const match = disposition.match(
    /filename\*=UTF-8''([^;]+)|filename="?([^";]+)"?/i,
  )

  let filename = fallbackName

  try {
    filename = decodeURIComponent(
      match?.[1] || match?.[2] || fallbackName,
    )
  } catch {
    filename = match?.[2] || fallbackName
  }

  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')

  anchor.href = url
  anchor.download = filename
  document.body.appendChild(anchor)
  anchor.click()
  anchor.remove()

  // Beri kesempatan browser membaca blob sebelum URL dilepas.
  window.setTimeout(() => URL.revokeObjectURL(url), 1000)
}

export const materialApi = {
  getAll(params = {}) {
    return apiClient.get(`/materials${buildQuery(params)}`)
  },

  create(formData) {
    return apiClient.postForm('/materials', formData)
  },

  update(id, formData) {
    // Laravel/PHP memproses multipart POST dengan method spoofing PUT.
    formData.set('_method', 'PUT')
    return apiClient.postForm(`/materials/${id}`, formData)
  },

  delete(id) {
    return apiClient.delete(`/materials/${id}`)
  },

  // Unduhan kompatibel dengan endpoint lama bahan ajar.
  download(id, filename) {
    return downloadFile(`/materials/${id}/download`, filename)
  },

  // Unduh satu lampiran tertentu.
  downloadAttachment(materialId, attachmentId, filename) {
    return downloadFile(
      `/materials/${materialId}/attachments/${attachmentId}/download`,
      filename,
    )
  },

  // Hapus satu lampiran tanpa menghapus materi atau lampiran lainnya.
  deleteAttachment(materialId, attachmentId) {
    return apiClient.delete(
      `/materials/${materialId}/attachments/${attachmentId}`,
    )
  },
}
