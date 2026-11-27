export function placementLabel(placement) {
  if (!placement) return 'Belum diperiksa'
  if (placement.academic_status==='graduate') return 'Lulus'
  if (placement.academic_status==='withdraw') return 'Pindah keluar'
  return ({effective:placement.class_name || 'Kelas belum diketahui', unassigned:'Belum ditempatkan', future:'Belum mulai', historical:placement.class_name ? `${placement.class_name} (riwayat)` : 'Riwayat penempatan', conflict:'Konflik penempatan', period_unavailable:'Periode belum tersedia'})[placement.status] || 'Belum diketahui'
}

export function academicProfileRoute(user) {
  const code=user?.role?.code
  if (!['GURU','SISWA'].includes(code)) return null
  const base=code==='GURU'?'teachers':'students'
  const validId=id=>/^[1-9]\d*$/.test(String(id))
  if(user.academic_profile?.status==='linked' && validId(user.academic_profile.id)) return `/app/${base}?profile_id=${user.academic_profile.id}`
  if(user.academic_profile?.status==='unlinked' && user.account_status==='active' && validId(user.id)) return `/app/${base}?link_user=${user.id}`
  return null
}
