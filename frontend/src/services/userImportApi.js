import { apiClient } from './apiClient'

export const userImportApi = {
  // Download template Excel.
  downloadTemplate() {
    return apiClient.getBlob('/users/template')
  },

  // Preview saja, tidak menyimpan akun.
  preview(file) {
    const formData = new FormData()
    formData.append('file', file)

    return apiClient.postForm(
      '/users/import/preview',
      formData
    )
  },

  // Import sebenarnya ke database MySQL.
  // Backend akan memvalidasi ulang seluruh file.
  importUsers(file) {
    const formData = new FormData()

    formData.append('file', file)
    formData.append('confirm_import', '1')

    return apiClient.postForm(
      '/users/import',
      formData
    )
  },
}
