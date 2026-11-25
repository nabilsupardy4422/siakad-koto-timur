/**
 * Pure attendance projections for Kepala Sekolah. Backend remains the source
 * of records; a zero recorded meeting is different from missing records.
 * The school calendar uses Asia/Jakarta (WIB), not the browser's timezone.
 */
const STATUSES = ['hadir', 'izin', 'sakit', 'alpa']

function safeCount(value) {
  if (typeof value === 'number') return Number.isSafeInteger(value) && value >= 0 ? value : null
  if (typeof value === 'string' && /^\d+$/.test(value.trim())) {
    const num = Number(value.trim())
    return Number.isSafeInteger(num) ? num : null
  }
  return null
}

function wibDateParts(date) {
  if (!(date instanceof Date) || !Number.isFinite(date.getTime())) {
    throw new TypeError('Tanggal dashboard tidak valid.')
  }
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone: 'Asia/Jakarta', year: 'numeric', month: '2-digit', day: '2-digit',
  }).formatToParts(date)
  const value = (type) => Number(parts.find((part) => part.type === type)?.value)
  return { year: value('year'), month: value('month'), day: value('day') }
}

function dateOnly(date) {
  return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}-${String(date.getUTCDate()).padStart(2, '0')}`
}

export function principalAttendanceMonths(now = new Date()) {
  const { year, month, day } = wibDateParts(now)
  const today = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`
  const monthFormatter = new Intl.DateTimeFormat('id-ID', { timeZone: 'UTC', month: 'short', year: '2-digit' })
  const windows = []

  for (let ago = 5; ago >= 0; ago -= 1) {
    const start = new Date(Date.UTC(year, month - 1 - ago, 1))
    const end = new Date(Date.UTC(start.getUTCFullYear(), start.getUTCMonth() + 1, 0))
    const from = dateOnly(start)
    windows.push({
      key: from.slice(0, 7),
      label: monthFormatter.format(start),
      from,
      to: ago === 0 ? today : dateOnly(end),
    })
  }

  return windows
}

export function normalizePrincipalRecap(payload) {
  const data = payload?.data
  if (!data || typeof data !== 'object' || Array.isArray(data)) {
    throw new Error('Kontrak rekap presensi tidak valid.')
  }
  const total = safeCount(data.total)
  const counts = Object.fromEntries(STATUSES.map((key) => [key, safeCount(data[key])]))
  if (total === null || STATUSES.some((key) => counts[key] === null)) {
    throw new Error('Jumlah presensi tidak valid atau tidak lengkap.')
  }
  const sum = STATUSES.reduce((current, key) => current + counts[key], 0)
  if (!Number.isSafeInteger(sum) || sum !== total) {
    throw new Error('Jumlah status presensi tidak cocok dengan total catatan.')
  }
  return { total, ...counts }
}

export function attendanceRate(recap) {
  if (!recap || !Number.isSafeInteger(recap.total) || recap.total <= 0) return null
  return (recap.hadir / recap.total) * 100
}

export function formatAttendanceRate(rate) {
  return rate === null || !Number.isFinite(rate)
    ? '—'
    : `${new Intl.NumberFormat('id-ID', { maximumFractionDigits: 1 }).format(rate)}%`
}

export function normalizePrincipalAttendance(windows, payloads) {
  if (!Array.isArray(windows) || windows.length !== 6 ||
      !Array.isArray(payloads) || payloads.length !== windows.length) {
    throw new Error('Rentang rekap presensi tidak lengkap.')
  }
  const months = windows.map((window, index) => {
    const recap = normalizePrincipalRecap(payloads[index])
    return { ...window, ...recap, rate: attendanceRate(recap) }
  })
  const total = { total: 0, hadir: 0, izin: 0, sakit: 0, alpa: 0 }
  for (const month of months) {
    for (const key of Object.keys(total)) {
      total[key] += month[key]
      if (!Number.isSafeInteger(total[key])) throw new Error('Rekap presensi terlalu besar.')
    }
  }
  return {
    months,
    totals: { ...total, rate: attendanceRate(total) },
    monthsWithRecords: months.filter((month) => month.total > 0).length,
    from: windows[0].from,
    to: windows[windows.length - 1].to,
  }
}

/** Disconnected lines: no extrapolation through months without recorded attendance. */
export function attendanceTrendGeometry(months) {
  if (!Array.isArray(months) || months.length !== 6) return { points: [], segments: [] }
  const points = months.map((month, index) => {
    const rate = month?.rate
    return {
      key: month?.key ?? String(index),
      label: month?.label ?? '',
      x: 28 + index * 112,
      y: rate === null || !Number.isFinite(rate) || rate < 0 || rate > 100
        ? null : 139 - (rate / 100) * 112,
      rate,
      total: month?.total ?? null,
    }
  })
  const segments = []
  let current = []
  for (const point of points) {
    if (point.y === null) {
      if (current.length > 1) segments.push(current)
      current = []
    } else {
      current.push(point)
    }
  }
  if (current.length > 1) segments.push(current)
  return { points, segments }
}
