
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

export const reportApi = {
  // Konteks penugasan Guru pada periode aktif.
  context() {
    return apiClient.get("/reports/context");
  },

  // Pratinjau dataset yang telah diotorisasi oleh backend.
  preview(reportType, params = {}) {
    return apiClient.get(
      `/reports/${reportType}/preview${buildQuery(params)}`
    );
  },

  // Pertahankan kompatibilitas unduhan PDF/Excel.
  downloadReport(reportType, params = {}) {
    return apiClient.download(
      `/reports/${reportType}${buildQuery(params)}`
    );
  },
};
