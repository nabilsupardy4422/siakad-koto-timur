export function identityInitials(name) {
  const parts = typeof name === 'string' ? name.trim().split(/\s+/).filter(Boolean) : []
  return parts.slice(0,2).map(part=>Array.from(part)[0].toLocaleUpperCase('id-ID')).join('') || '–'
}
export function completenessView(value) {
  const {filled,total} = value || {}
  if (!Number.isSafeInteger(filled) || !Number.isSafeInteger(total) || total<=0 || filled<0 || filled>total) return null
  return {filled,total,complete:filled===total,percent:filled/total*100}
}

export function directoryTotals(responses) {
  if (!Array.isArray(responses) || responses.length!==3) return null
  const counts=responses.map(r=>r?.meta?.total)
  if (counts.some(n=>!Number.isSafeInteger(n)||n<0)||counts[1]>counts[0]||counts[2]>counts[0]) return null
  return {total:counts[0],ready:counts[1],incomplete:counts[2]}
}
