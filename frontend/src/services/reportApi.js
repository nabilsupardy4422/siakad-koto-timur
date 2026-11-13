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
  // Download laporan PDF atau Excel.
  downloadReport(reportType, params = {}) {
    return apiClient.download(`/reports/${reportType}${buildQuery(params)}`);
  },
};
