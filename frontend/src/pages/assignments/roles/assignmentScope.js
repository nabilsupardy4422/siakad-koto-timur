import { collectPages, loadOwnTeachingSchedules, ownTeachingSchedules, sameId } from '../../../utils/teachingScope.js'

export function ownAssignments(items, schedules, guruId, yearId) {
  if (guruId == null || yearId == null) return []
  const own = ownTeachingSchedules(schedules,guruId,yearId)
  return items.filter(item => {
    const id = item?.jadwal_pelajaran_id
    const linked = item?.jadwal_pelajaran
    return item?.id != null && own.some(s => sameId(s.id, id)) &&
      (!linked || (sameId(linked.id,id) && sameId(linked.guru_id,guruId) && sameId(linked.tahun_akademik_id,yearId)))
  })
}
export async function loadAssignmentWorkspace(api, scheduleApi, guruId, yearId) {
  const schedules = await loadOwnTeachingSchedules(scheduleApi,guruId,yearId)
  const items = await collectPages(page => api.getAll({academic_year_id:yearId,per_page:100,page}))
  return {schedules, assignments:ownAssignments(items,schedules,guruId,yearId)}
}
export function ownComponents(items, scheduleId) {
  return items.filter(c => c?.id != null && sameId(c.jadwal_pelajaran_id,scheduleId) && (!c.jadwal_pelajaran || sameId(c.jadwal_pelajaran.id,scheduleId)))
}
// Backend config/app.php uses UTC. Send UTC SQL datetime; never device-local time.
export function deadlineToUtc(value) {
  if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/.test(value || '')) return null
  const full = value.length===16 ? value+':00' : value
  const date = new Date(full+'+07:00')
  if (!Number.isFinite(date.getTime()) || new Date(date.getTime()+7*3600000).toISOString().slice(0,19)!==full) return null
  return date.toISOString().slice(0,19).replace('T',' ')
}
export function deadlineToWib(value) {
  if (!value) return ''
  const date=new Date(value)
  return Number.isFinite(date.getTime()) ? new Date(date.getTime()+7*3600000).toISOString().slice(0,16) : ''
}
export function submissionRows(response,assignmentId) {
  if (!Array.isArray(response?.data) || response.data.some(s=>!sameId(s.assignment_id,assignmentId) || s.id==null)) throw new Error('Konteks pengumpulan dari server tidak sesuai.')
  return response.data
}
