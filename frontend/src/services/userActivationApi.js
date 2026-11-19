
import { apiClient } from './apiClient'

export const userActivationApi = {
  // Periksa apakah akun memiliki kode aktivasi aktif.
  // Hanya mengembalikan status boolean.
  getCodeStatus(userId) {
    return apiClient.get(
      `/users/${userId}/activation-code/status`
    )
  },

  // TU menerbitkan kode baru.
  issueCode(userId) {
    return apiClient.post(
      `/users/${userId}/activation-code`
    )
  },

  // TU membatalkan kode yang sudah diterbitkan.
  cancelCode(userId) {
    return apiClient.delete(
      `/users/${userId}/activation-code`
    )
  },
}
