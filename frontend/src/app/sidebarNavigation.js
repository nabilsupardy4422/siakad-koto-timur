/**
 * Client-side menu visibility is only a presentation concern.
 * Laravel/route guards remain the authorization source of truth.
 * Preserve the established role + permission + wali-kelas assignment contract.
 */
export function selectVisibleSidebarItems(navigation, role, permissions = [], assignments = []) {
  const roleValue = typeof role === 'string'
    ? role
    : role?.code || role?.name || role?.nama || ''
  const roleCode = String(roleValue).trim().toUpperCase()

  const visible = (navigation[roleCode] || []).filter((item) => {
    if (item.label === 'Bahan Ajar' && !['GURU', 'SISWA'].includes(roleCode)) return false
    if (item.permission && !permissions.includes(item.permission)) return false
    if (item.assignment && !assignments.some((assignment) => assignment.type === item.assignment)) return false
    return true
  })

  // Do not leave orphan section headings in the compact navigation.
  return visible.filter((item, index) => {
    if (!item.section) return true
    for (let i = index + 1; i < visible.length; i += 1) {
      if (visible[i].section) return false
      return true
    }
    return false
  })
}
