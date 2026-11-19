
import { apiClient } from './apiClient'

const BASE = '/profile/google'

export const googleAccountApi = {
  // ==========================================
  // PERIKSA STATUS GOOGLE
  // ==========================================
  status() {
    return apiClient.get(`${BASE}/status`)
  },

  // ==========================================
  // HUBUNGKAN / VERIFIKASI ULANG GOOGLE
  // ==========================================
  link(credential, currentPassword = null) {
    const payload = {
      credential,
    }

    // Password hanya diperlukan ketika
    // pertama kali menghubungkan Google.
    //
    // Untuk verifikasi ulang Google yang
    // sudah tertaut, password boleh kosong.

    if (
      typeof currentPassword === 'string' &&
      currentPassword.length > 0
    ) {
      payload.current_password = currentPassword
    }

    return apiClient.post(`${BASE}/link`, payload)
  },

  // ==========================================
  // LEPASKAN AKUN GOOGLE
  // ==========================================
  unlink(currentPassword) {
    return apiClient.post(`${BASE}/unlink`, {
      current_password: currentPassword,
    })
  },
}
