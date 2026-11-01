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

export const userApi = {
  getAll(params = {}) {
    return apiClient.get(`/users${buildQuery(params)}`);
  },

  getById(id) {
    return apiClient.get(`/users/${id}`);
  },

  create(payload) {
    return apiClient.post("/users", payload);
  },

  update(id, payload) {
    return apiClient.put(`/users/${id}`, payload);
  },

  activate(id) {
    return apiClient.post(`/users/${id}/activate`);
  },

  deactivate(id) {
    return apiClient.post(`/users/${id}/deactivate`);
  },
};
