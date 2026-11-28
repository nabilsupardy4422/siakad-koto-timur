// GET /api/academic-years returns ALL periods as { data: [...] } with no
// pagination metadata. Do not apply the paginated-collection contract to it.
export function getAcademicYears(response) {
  if (!response || !Array.isArray(response.data) || response.meta !== undefined) {
    throw new Error('Respons tahun akademik tidak sesuai kontrak API.')
  }
  return response.data
}

// The API supplies paginated collections. Never treat a failed or truncated
// response as a valid empty monitoring result.
export function getCollection(response, expectedPage) {
  if (!response || !Array.isArray(response.data)) {
    throw new Error('Respons daftar akademik tidak sesuai kontrak API.')
  }

  const meta = response.meta
  const current = Number(meta?.current_page)
  const last = Number(meta?.last_page)
  if (!Number.isSafeInteger(current) || current !== expectedPage ||
      !Number.isSafeInteger(last) || last < 1 || current > last) {
    throw new Error('Informasi pagination akademik tidak valid.')
  }

  if (last > 100) {
    throw new Error('Data terlalu banyak untuk ditampilkan utuh. Persempit periode atau hubungi administrator.')
  }

  return { data: response.data, lastPage: last }
}

export async function getAllPages(fetchPage, perPage = 100) {
  const all = []
  let lastPage = 1
  for (let page = 1; page <= lastPage; page += 1) {
    const { data, lastPage: last } = getCollection(await fetchPage(page, perPage), page)
    if (page > 1 && last !== lastPage) {
      throw new Error('Pagination berubah saat monitoring dimuat. Silakan muat ulang.')
    }
    lastPage = last
    all.push(...data)
  }
  return all
}

export function isActivePeriod(period) {
  return period?.is_active === true || period?.is_active === 1 || period?.is_active === '1'
}

export function strictId(value) {
  const id = Number(value)
  if (!Number.isSafeInteger(id) || id <= 0) throw new Error('ID data penilaian tidak valid.')
  return id
}

// Keep 0 distinct from an unrecorded grade. A record with invalid score must
// not silently turn into a valid zero or a fabricated missing grade.
export function scoreOf(grade) {
  if (!grade) return null
  if (grade.nilai === null || grade.nilai === undefined || grade.nilai === '') {
    throw new Error('Nilai tercatat memiliki skor kosong. Periksa integritas data.')
  }
  const score = Number(grade.nilai)
  if (!Number.isFinite(score) || score < 0 || score > 100) {
    throw new Error('Skor yang diterima tidak berada pada rentang 0–100.')
  }
  return score
}
