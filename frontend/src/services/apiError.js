
const DEFAULT_MESSAGES = {
  400: 'Permintaan tidak dapat diproses.',
  401: 'Sesi Anda telah berakhir. Silakan masuk kembali.',
  403: 'Anda tidak memiliki izin untuk melakukan tindakan ini.',
  404: 'Data yang diminta tidak ditemukan.',
  408: 'Permintaan melewati batas waktu. Silakan coba lagi.',
  409: 'Data mengalami konflik dengan kondisi saat ini.',
  413: 'Ukuran data yang dikirim terlalu besar.',
  419: 'Sesi Anda telah berakhir. Silakan muat ulang halaman.',
  422: 'Data yang dimasukkan belum valid.',
  429: 'Terlalu banyak permintaan. Silakan coba beberapa saat lagi.',
  500: 'Terjadi kesalahan pada server.',
  502: 'Server sementara tidak dapat memproses permintaan.',
  503: 'Layanan sedang tidak tersedia. Silakan coba lagi nanti.',
  504: 'Server membutuhkan waktu terlalu lama untuk merespons.',
}

const DEFAULT_ERROR_MESSAGE =
  'Terjadi kesalahan saat memproses permintaan.'

export function getApiErrorMessage(status) {
  return DEFAULT_MESSAGES[status] || DEFAULT_ERROR_MESSAGE
}

// ==========================================
// TERJEMAHAN VALIDASI BACKEND
// ==========================================

function translateValidationMessage(message, field) {
  const text = String(message || '').toLowerCase()

  if (text.includes('has already been taken')) {
    if (field === 'nama') {
      return 'Nama komponen sudah digunakan pada jadwal ini.'
    }

    return 'Data tersebut sudah digunakan.'
  }

  if (text.includes('is required')) {
    if (field === 'nama') {
      return 'Nama komponen wajib diisi.'
    }

    if (field === 'bobot') {
      return 'Bobot wajib diisi.'
    }

    return 'Field wajib diisi.'
  }

  if (
    text.includes('must be a number') ||
    text.includes('must be numeric')
  ) {
    return 'Bobot harus berupa angka yang valid.'
  }

  if (
    text.includes('must not be greater than 100') &&
    field === 'nama'
  ) {
    return 'Nama komponen maksimal 100 karakter.'
  }

  if (text.includes('must be a string')) {
    return 'Format data harus berupa teks.'
  }

  if (text.includes('does not exist')) {
    return 'Data yang dipilih tidak ditemukan.'
  }

  return message
}

// ==========================================
// NORMALISASI VALIDATION ERRORS
// ==========================================

function normalizeValidationErrors(errors) {
  if (
    !errors ||
    typeof errors !== 'object' ||
    Array.isArray(errors)
  ) {
    return {}
  }

  return Object.fromEntries(
    Object.entries(errors).map(([field, messages]) => {
      const messageList = Array.isArray(messages)
        ? messages
        : [messages]

      return [
        field,
        messageList.map((message) =>
          translateValidationMessage(message, field),
        ),
      ]
    }),
  )
}

// ==========================================
// NORMALISASI API ERROR
// ==========================================

export function normalizeApiError(error) {
  const status =
    error?.response?.status ??
    error?.status ??
    null

  // Mengambil response dari Fetch API atau Axios.
  const data =
    error?.response?.data ??
    error?.payload ??
    error?.data ??
    {}

  // Mendukung dua struktur response:
  //
  // 1. Laravel standar:
  // { errors: {...}, message: '...' }
  //
  // 2. Response Import Excel:
  // {
  //   data: {
  //     errors: {
  //       baris_2: [...]
  //     }
  //   },
  //   message: '...'
  // }

  const nestedData =
    data?.data &&
    typeof data.data === 'object'
      ? data.data
      : {}

  const validationErrors =
    nestedData?.errors ??
    data?.errors ??
    error?.validationErrors ??
    error?.errors ??
    {}

  const backendMessage =
    typeof data?.message === 'string' &&
    data.message.trim()
      ? data.message.trim()
      : null

  // Fetch menggunakan TypeError ketika terjadi kegagalan jaringan.
  const isNetworkError =
    error instanceof TypeError ||
    error?.code === 'ERR_NETWORK' ||
    error?.message === 'Network Error' ||
    error?.message === 'Failed to fetch'

  const isTimeout =
    error?.code === 'ECONNABORTED' ||
    error?.code === 'ETIMEDOUT' ||
    error?.name === 'TimeoutError'

  const normalizedValidationErrors =
    normalizeValidationErrors(validationErrors)

  const firstValidationMessage = Object.values(
    normalizedValidationErrors,
  )
    .flat()
    .find(Boolean)

  let message

  if (isNetworkError) {
    message =
      'Tidak dapat terhubung ke server. Periksa koneksi dan pastikan backend berjalan.'
  } else if (isTimeout) {
    message =
      'Permintaan melewati batas waktu. Silakan coba lagi.'
  } else if (status === 422 && firstValidationMessage) {
    message = firstValidationMessage
  } else if (status !== null && status !== 422) {
    message = getApiErrorMessage(status)
  } else {
    message = backendMessage || DEFAULT_ERROR_MESSAGE
  }

  return {
    status,
    message,

    // Detail validasi yang sudah dinormalisasi.
    validationErrors: normalizedValidationErrors,

    // Pertahankan response asli backend.
    // Dibutuhkan AdminSchedule untuk mengambil:
    // error.payload.message
    // error.payload.data.errors
    payload: data,
  }
}
