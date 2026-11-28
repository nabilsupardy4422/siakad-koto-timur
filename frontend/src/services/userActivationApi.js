
import { apiClient } from './apiClient'

export const userActivationApi = {
  // Periksa apakah akun memiliki kode aktivasi aktif.
  // Status lifecycle, expiry, dan versi pembatalan opaque; bukan kode/hash.
  getCodeStatus(userId) {
    return apiClient.get(
      `/users/${userId}/activation-code/status`
    )
  },

  // TU menerbitkan kode baru.
  issueCode(userId, preview) {
    return apiClient.post(
      `/users/${userId}/activation-code`, preview
    )
  },

  // TU membatalkan kode yang sudah diterbitkan.
  cancelCode(userId, codeVersion) {
    return apiClient.delete(
      `/users/${userId}/activation-code`, {code_version:codeVersion}
    )
  },
}
