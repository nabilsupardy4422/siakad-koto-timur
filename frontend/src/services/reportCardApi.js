import { apiClient } from "./apiClient";

function buildQuery(params = {}) {
  const searchParams = new URLSearchParams();

  Object.entries(params).forEach(([key, value]) => {
    if (value === undefined || value === null || value === "") {
      return;
    }

    searchParams.set(key, String(value));
  });

  const queryString = searchParams.toString();

  return queryString ? `?${queryString}` : "";
}

export const reportCardApi = {
  // Mengambil daftar rapor.
  getReportCards(params = {}) {
    return apiClient.get(`/report-cards${buildQuery(params)}`);
  },

  // Mengambil detail rapor.
  getReportCard(id) {
    return apiClient.get(`/report-cards/${id}`);
  },

  // Upload rapor oleh TU.
  uploadReportCard(formData) {
    return apiClient.postForm("/report-cards", formData);
  },

  // Download file rapor.
  downloadReportCard(id) {
    return apiClient.download(`/report-cards/${id}/download`);
  },

  // Menghapus rapor oleh TU.
  deleteReportCard(id) {
    return apiClient.delete(`/report-cards/${id}`);
  },
};
