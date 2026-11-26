export const yearLabel = (period) => `${period.tahun_mulai}/${period.tahun_selesai}`;
export const semesterLabel = (value) => value === 'GANJIL' ? 'Ganjil' : value === 'GENAP' ? 'Genap' : '—';
export const validStart = (value) => /^[1-9]\d{3}$/.test(String(value)) && Number(value) <= 9998;
export const endYear = (value) => validStart(value) ? String(Number(value) + 1) : '';
export const activePeriod = (period) => [true, 1, '1'].includes(period.is_active);
// Fail closed when the server does not provide the TU dependency flag.
export const lockedPeriod = (period) => activePeriod(period) || period.identity_locked !== false;
export function formPayload(form, first = false) {
  if (!validStart(form.tahun_mulai) || !['GANJIL', 'GENAP'].includes(form.semester)) return null;
  return { tahun_mulai: Number(form.tahun_mulai), tahun_selesai: Number(endYear(form.tahun_mulai)), semester: form.semester, is_active: first };
}
export function activationPayload(period, activePeriods) {
  return { ...(activePeriods ? { expected_active_ids: activePeriods.map(item => Number(item.id)) } : {}), tahun_mulai: Number(period.tahun_mulai), tahun_selesai: Number(period.tahun_selesai), semester: period.semester, is_active: true };
}
export function canImport(preview) {
  const summary = preview?.summary;
  return Boolean(summary && summary.total_rows > 0 && summary.invalid_rows === 0 && summary.valid_rows === summary.total_rows && Array.isArray(preview.rows) && preview.rows.length === summary.total_rows && preview.rows.every(row => row.valid === true));
}
export function errorMessages(error) {
  const errors = error?.validationErrors || error?.payload?.data?.errors || error?.payload?.errors || {};
  const messages = Object.values(errors).flat().filter(Boolean);
  if (messages.length) return messages;
  if (error instanceof TypeError) return ['Tidak dapat terhubung ke server. Periksa koneksi lalu coba lagi.'];
  return [error?.message || 'Permintaan gagal. Silakan coba lagi.'];
}
