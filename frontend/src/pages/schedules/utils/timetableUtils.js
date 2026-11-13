
export const TIMETABLE_DAYS = [
    { key: 'SENIN', label: 'Senin', short: 'Sen' },
    { key: 'SELASA', label: 'Selasa', short: 'Sel' },
    { key: 'RABU', label: 'Rabu', short: 'Rab' },
    { key: 'KAMIS', label: 'Kamis', short: 'Kam' },
    { key: 'JUMAT', label: 'Jumat', short: 'Jum' },
    { key: 'SABTU', label: 'Sabtu', short: 'Sab' },
    { key: 'MINGGU', label: 'Minggu', short: 'Min' },
  ]
  
  const DAY_INDEX = Object.fromEntries(
    TIMETABLE_DAYS.map((day, index) => [day.key, index])
  )
  
  export function parseTime(value) {
    if (!value) return null
  
    const [hour, minute] = String(value).slice(0, 5).split(':').map(Number)
  
    if (
      !Number.isFinite(hour) ||
      !Number.isFinite(minute) ||
      hour < 0 ||
      hour > 23 ||
      minute < 0 ||
      minute > 59
    ) {
      return null
    }
  
    return hour * 60 + minute
  }
  
  export function formatTime(value) {
    if (!value) return '-'
    return String(value).slice(0, 5)
  }
  
  export function formatMinutes(minutes) {
    const hour = Math.floor(minutes / 60)
    const minute = minutes % 60
  
    return `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`
  }
  
  export function getDayIndex(day) {
    return DAY_INDEX[String(day || '').toUpperCase()] ?? -1
  }
  
  export function startOfWeek(date) {
    const result = new Date(date)
    const day = (result.getDay() + 6) % 7
  
    result.setDate(result.getDate() - day)
    result.setHours(0, 0, 0, 0)
  
    return result
  }
  
  export function addDays(date, amount) {
    const result = new Date(date)
    result.setDate(result.getDate() + amount)
    return result
  }
  
  export function addMonths(date, amount) {
    const result = new Date(date)
    const day = result.getDate()
  
    result.setDate(1)
    result.setMonth(result.getMonth() + amount)
  
    const lastDay = new Date(
      result.getFullYear(),
      result.getMonth() + 1,
      0
    ).getDate()
  
    result.setDate(Math.min(day, lastDay))
  
    return result
  }
  
  export function getMonthDays(date) {
    const first = new Date(date.getFullYear(), date.getMonth(), 1)
    const last = new Date(date.getFullYear(), date.getMonth() + 1, 0)
  
    const firstOffset = (first.getDay() + 6) % 7
    const totalCells = Math.ceil((firstOffset + last.getDate()) / 7) * 7
  
    return Array.from({ length: totalCells }, (_, index) => {
      const day = index - firstOffset + 1
  
      return new Date(
        date.getFullYear(),
        date.getMonth(),
        day
      )
    })
  }
  
  export function getDateForDay(date, dayKey) {
    const index = getDayIndex(dayKey)
  
    if (index < 0) return null
  
    const monday = startOfWeek(date)
    return addDays(monday, index)
  }
  
  export function formatDate(date, options = {}) {
    return new Intl.DateTimeFormat('id-ID', options).format(date)
  }
  
  export function getScheduleMinutes(schedule) {
    const start = parseTime(schedule.jam_mulai)
    const end = parseTime(schedule.jam_selesai)
  
    if (start === null || end === null || end <= start) {
      return null
    }
  
    return { start, end, duration: end - start }
  }
  

  export function getNextSchedule(schedules, now = new Date()) {
    const todayIndex = (now.getDay() + 6) % 7
    const currentMinute = now.getHours() * 60 + now.getMinutes()
  
    const candidates = []
  
    schedules.forEach((schedule) => {
      const dayIndex = getDayIndex(schedule.hari)
      const time = getScheduleMinutes(schedule)
  
      if (dayIndex < 0 || !time) return
  
      let daysUntil = (dayIndex - todayIndex + 7) % 7
  
      if (daysUntil === 0 && time.end <= currentMinute) {
        daysUntil = 7
      }
  
      candidates.push({
        schedule,
        daysUntil,
        start: time.start,
        end: time.end,
        isOngoing:
          daysUntil === 0 &&
          time.start <= currentMinute &&
          currentMinute < time.end,
      })
    })
  
    candidates.sort((a, b) => {
      if (a.daysUntil !== b.daysUntil) {
        return a.daysUntil - b.daysUntil
      }
  
      if (a.isOngoing !== b.isOngoing) {
        return a.isOngoing ? -1 : 1
      }
  
      return a.start - b.start
    })
  
    return candidates[0] || null
  }
  
  export function getScheduleTitle(schedule) {
    return (
      schedule?.mapel?.nama ||
      schedule?.mapel?.kode ||
      'Mata Pelajaran'
    )
  }
  
  export function getClassTitle(schedule) {
    return (
      schedule?.kelas?.nama ||
      schedule?.kelas?.nama_kelas ||
      (schedule?.kelas?.id ? `Kelas #${schedule.kelas.id}` : '-')
    )
  }
  
  export function getTeacherTitle(schedule) {
    return (
      schedule?.guru?.nama_lengkap ||
      schedule?.guru?.user?.name ||
      schedule?.guru?.nip ||
      '-'
    )
  }
  