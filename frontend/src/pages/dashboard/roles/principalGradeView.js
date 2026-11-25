/**
 * The existing Laravel /grades/recap endpoint aggregates raw component scores.
 * A 0-grade period must NOT be displayed as an average score of zero.
 * Scores are not semester grades or final report-card values.
 */
function numericScore(value) {
  if (typeof value === 'number') {
    return Number.isFinite(value) && value >= 0 && value <= 100 ? value : null
  }
  if (typeof value === 'string' && /^(?:\d+)(?:\.\d+)?$/.test(value.trim())) {
    const parsed = Number(value.trim())
    return Number.isFinite(parsed) && parsed >= 0 && parsed <= 100 ? parsed : null
  }
  return null
}

function countRecords(value) {
  if (typeof value === 'number') return Number.isSafeInteger(value) && value >= 0 ? value : null
  if (typeof value === 'string' && /^\d+$/.test(value.trim())) {
    const parsed = Number(value.trim())
    return Number.isSafeInteger(parsed) ? parsed : null
  }
  return null
}

export function normalizePrincipalGradeRecap(response) {
  if (!response || typeof response !== 'object' || !response.data || typeof response.data !== 'object') {
    throw new Error('Respons rekap nilai tidak valid.')
  }
  const data = response.data
  const total = countRecords(data.total)
  if (total === null) throw new Error('Jumlah nilai pada rekap tidak valid.')

  if (total === 0) {
    if (data.tertinggi !== null || data.terendah !== null) {
      throw new Error('Rekap nilai kosong memiliki rentang yang tidak konsisten.')
    }
    return { total: 0, average: null, lowest: null, highest: null }
  }

  const average = numericScore(data.rata_rata)
  const lowest = numericScore(data.terendah)
  const highest = numericScore(data.tertinggi)
  if (average === null || lowest === null || highest === null || lowest > average || average > highest) {
    throw new Error('Statistik nilai tidak lengkap atau tidak konsisten.')
  }

  return { total, average, lowest, highest }
}

export function formatPrincipalGrade(value) {
  return value === null
    ? '—'
    : new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 }).format(value)
}

export function principalGradeBarWidth(value) {
  if (typeof value !== 'number' || !Number.isFinite(value) || value < 0 || value > 100) return 0
  return value
}
