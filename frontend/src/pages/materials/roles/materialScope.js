import { collectPages, ownTeachingSchedules, sameId, scheduleOwnerId, scheduleYearId } from '../../../utils/teachingScope.js'

export function materialScheduleId(item) {
  return item?.jadwal_pelajaran_id ?? item?.jadwal_pelajaran?.id ?? null
}

// Names are for display only. Never identify a teaching assignment by a subject or class name.
export function ownMaterials(items, schedules, guruId, yearId) {
  const allowed = new Set(ownTeachingSchedules(schedules, guruId, yearId).map(s => String(s.id)))
  return items.filter(item => {
    const schedule = item?.jadwal_pelajaran
    const id = materialScheduleId(item)
    return item?.id != null && id != null && allowed.has(String(id)) &&
      sameId(id, schedule?.id) && sameId(scheduleOwnerId(schedule), guruId) &&
      sameId(scheduleYearId(schedule), yearId)
  })
}

export async function loadMaterialWorkspace(scheduleApi, materialApi, guruId, yearId) {
  if (guruId == null || String(guruId) === '' || yearId == null || String(yearId) === '') {
    throw new Error('Identitas Guru atau tahun akademik belum tersedia.')
  }
  const params = { scope: 'teaching', academic_year_id: yearId, per_page: 100 }
  const [scheduleRows, materialRows] = await Promise.all([
    collectPages(page => scheduleApi.getAll({ ...params, teacher_id: guruId, history: 1, page })),
    collectPages(page => materialApi.getAll({ ...params, page })),
  ])
  const schedules = ownTeachingSchedules(scheduleRows, guruId, yearId)
  return { schedules, materials: ownMaterials(materialRows, schedules, guruId, yearId) }
}

export function canSelectMaterialSchedule(schedules, id, guruId, yearId) {
  return ownTeachingSchedules(schedules, guruId, yearId).some(schedule => sameId(schedule.id, id))
}
