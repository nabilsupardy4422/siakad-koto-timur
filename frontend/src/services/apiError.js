const DEFAULT_MESSAGES = {
  400: "Permintaan tidak dapat diproses.",
  401: "Sesi Anda telah berakhir. Silakan masuk kembali.",
  403: "Anda tidak memiliki akses ke data atau fitur ini.",
  404: "Data yang diminta tidak ditemukan.",
  422: "Data yang dimasukkan belum valid.",
  429: "Terlalu banyak permintaan. Silakan coba beberapa saat lagi.",
  500: "Terjadi kesalahan pada server.",
};

export function getApiErrorMessage(status) {
  return (
    DEFAULT_MESSAGES[status] || "Terjadi kesalahan saat memproses permintaan."
  );
}

export function normalizeApiError(error) {
  const status = error?.status || error?.response?.status || null;

  return {
    status,
    message: getApiErrorMessage(status),
    validationErrors: error?.errors || error?.response?.data?.errors || {},
  };
}
