export function normalizeRole(role) {
    if (!role) {
      return null
    }
  
    if (typeof role === 'string') {
      return role.toUpperCase()
    }
  
    if (typeof role === 'object') {
      const roleCode = role.code || role.name
  
      return typeof roleCode === 'string'
        ? roleCode.toUpperCase()
        : null
    }
  
    return null
  }