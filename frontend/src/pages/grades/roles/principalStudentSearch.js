/** Search only by students' names and their official NISN identifiers.
 *  NIS is a separate local identifier and is deliberately not searchable here.
 */
export function matchesPrincipalStudentSearch(row, rawQuery) {
  const query = String(rawQuery ?? '').trim().normalize('NFKC').toLocaleLowerCase('id-ID')
  if (!query) return true
  if (!row) return false

  const name = String(row.studentName ?? '').normalize('NFKC').toLocaleLowerCase('id-ID')
  const nisn = String(row.nisn ?? '').trim().normalize('NFKC').toLocaleLowerCase('id-ID')
  return name.includes(query) || (nisn !== '-' && nisn.includes(query))
}
