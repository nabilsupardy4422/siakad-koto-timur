// Kontrak nyata /api/students = { data: StudentResource[], meta: {...} }.
export function parseStudentMatches(payload) {
  if (!payload || !Array.isArray(payload.data) || !payload.meta ||
      !Number.isInteger(Number(payload.meta.current_page))) {
    throw new Error('Kontrak daftar siswa tidak valid.')
  }
  const ids = new Set()
  return payload.data.map((row) => {
    if (!Number.isSafeInteger(Number(row?.id)) || Number(row.id) <= 0 ||
        typeof row?.nama_lengkap !== 'string' || !row.nama_lengkap.trim() ||
        ids.has(Number(row.id))) {
      throw new Error('Data identitas siswa tidak valid.')
    }
    ids.add(Number(row.id))
    return {
      id: Number(row.id),
      nama_lengkap: row.nama_lengkap,
      nis: row.nis || '',
      nisn: row.nisn || '',
    }
  })
}

export function studentOptionLabel(student) {
  return `${student.nama_lengkap} · NIS ${student.nis || '—'} · NISN ${student.nisn || '—'}`
}
