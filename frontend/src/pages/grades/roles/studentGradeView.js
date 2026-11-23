export function validateWorkspace(payload) {
  const data = payload?.data ?? payload
  if (!data || !Array.isArray(data.subjects) || !data.year || !data.summary || !Array.isArray(data.classes)) {
    throw new Error('Format data nilai siswa dari server tidak sesuai.')
  }
  const seen = new Set()
  for (const subject of data.subjects) {
    if (typeof subject.key !== 'string' || !Array.isArray(subject.components) || !Array.isArray(subject.teachers) || seen.has(subject.key)) {
      throw new Error('Data mata pelajaran tidak konsisten.')
    }
    seen.add(subject.key)
    for (const component of subject.components) {
      if (typeof component.assessed !== 'boolean' || (component.assessed && (component.score === null || !Number.isFinite(Number(component.score))))) {
        throw new Error('Status nilai siswa tidak konsisten.')
      }
    }
  }
  return data
}

export function formatGrade(value) {
  if (value === null || value === undefined || value === '') return '—'
  const number = Number(value)
  return Number.isFinite(number) ? number.toLocaleString('id-ID', { maximumFractionDigits: 2 }) : '—'
}

export function formatWeight(value) {
  return value === null || value === undefined || value === '' ? '—' : `${formatGrade(value)}%`
}

export function uniqueTeacherNames(teachers) {
  return [...new Set((teachers || []).map((teacher) => teacher.name?.trim()).filter(Boolean))].join(' · ')
}
