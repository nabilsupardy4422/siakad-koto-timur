/** Student schedule: validate server context and every paginated row, fail closed. */
export const WEEK_DAYS = ['SENIN', 'SELASA', 'RABU', 'KAMIS', 'JUMAT', 'SABTU', 'MINGGU'];
export const DAY_LABELS = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];

const positiveId = (id) => Number.isSafeInteger(Number(id)) && Number(id) > 0;
const validClock = (clock) => typeof clock === 'string' && /^([01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/.test(clock);
const minutes = (clock) => Number(clock.slice(0, 2)) * 60 + Number(clock.slice(3, 5));
const fail = () => { throw new Error('Data jadwal tidak konsisten dengan penempatan kelas dan periode aktif. Muat ulang atau hubungi Tata Usaha.'); };

export function verifyStudentSchedulePage(response, prior = null) {
  const ctx = response?.meta?.student_context;
  const meta = response?.meta;
  if (!ctx || !meta || !Array.isArray(response?.data) ||
      !['active', 'scheduled', 'ended', 'unassigned'].includes(ctx.status) ||
      !positiveId(ctx.academic_year?.id) ||
      !Number.isInteger(Number(ctx.academic_year.tahun_mulai)) ||
      !Number.isInteger(Number(ctx.academic_year.tahun_selesai)) ||
      !['GANJIL', 'GENAP'].includes(ctx.academic_year.semester) ||
      !/^\d{4}-\d{2}-\d{2}$/.test(ctx.as_of || '') ||
      !Number.isInteger(meta.current_page) || meta.current_page < 1 ||
      !Number.isInteger(meta.last_page) || meta.last_page < 1 ||
      meta.current_page > meta.last_page || !Number.isInteger(meta.total) || meta.total < 0 ||
      !Number.isInteger(meta.per_page) || meta.per_page < 1) fail();

  if (prior && (
      ctx.status !== prior.status || ctx.as_of !== prior.as_of ||
      Number(ctx.academic_year.id) !== Number(prior.academic_year.id) ||
      Number(ctx.class?.id || 0) !== Number(prior.class?.id || 0))) fail();

  if (ctx.status === 'active') {
    if (!positiveId(ctx.class?.id) || !ctx.class?.nama || ![10, 11, 12].includes(Number(ctx.class.tingkat)) ||
        !/^\d{4}-\d{2}-\d{2}$/.test(ctx.starts_on || '') ||
        ctx.starts_on > ctx.as_of || (ctx.ends_on && ctx.ends_on < ctx.as_of)) fail();
  } else if (ctx.class || response.data.length || meta.total !== 0) fail();

  for (const row of response.data) {
    if (!positiveId(row?.id) || ctx.status !== 'active' ||
        Number(row.tahun_akademik_id) !== Number(ctx.academic_year.id) ||
        Number(row.kelas_id) !== Number(ctx.class.id) ||
        Number(row.kelas?.id) !== Number(ctx.class.id) ||
        row.kelas?.nama !== ctx.class.nama || Number(row.kelas?.tingkat) !== Number(ctx.class.tingkat) ||
        Number(row.tahun_akademik?.id) !== Number(ctx.academic_year.id) ||
        row.tahun_akademik?.semester !== ctx.academic_year.semester ||
        Number(row.mapel?.id) !== Number(row.mapel_id) || !row.mapel?.nama ||
        Number(row.guru?.id) !== Number(row.guru_id) || !row.guru?.nama_lengkap ||
        !WEEK_DAYS.includes(String(row.hari).toUpperCase()) ||
        !validClock(row.jam_mulai) || !validClock(row.jam_selesai) ||
        minutes(row.jam_mulai) >= minutes(row.jam_selesai)) fail();
  }

  return { ctx, rows: response.data, meta };
}

export async function getCompleteStudentSchedule(getPage, { maxPages = 100 } = {}) {
  const seen = new Set();
  const rows = [];
  let ctx = null;
  let pageNumber = 1;
  let lastPage = null;
  let expectedTotal = null;
  do {
    if (pageNumber > maxPages) fail();
    const result = verifyStudentSchedulePage(await getPage(pageNumber), ctx);
    if (result.meta.current_page !== pageNumber ||
        (lastPage !== null && result.meta.last_page !== lastPage) ||
        (expectedTotal !== null && result.meta.total !== expectedTotal)) fail();
    lastPage = result.meta.last_page;
    expectedTotal = result.meta.total;
    ctx = result.ctx;
    for (const row of result.rows) {
      if (seen.has(Number(row.id))) fail();
      seen.add(Number(row.id));
      rows.push(row);
    }
    pageNumber += 1;
  } while (pageNumber <= lastPage);
  if (rows.length !== expectedTotal) fail();
  return { context: ctx, schedules: sortStudentSchedules(rows) };
}

export function sortStudentSchedules(rows) {
  return [...rows].sort((a, b) =>
    WEEK_DAYS.indexOf(String(a.hari).toUpperCase()) - WEEK_DAYS.indexOf(String(b.hari).toUpperCase()) ||
    minutes(a.jam_mulai) - minutes(b.jam_mulai) ||
    String(a.mapel?.nama).localeCompare(String(b.mapel?.nama), 'id')
  );
}

export const displayClock = (v) => String(v || '').slice(0, 5);
export const yearTitle = (y) => y ? `${y.tahun_mulai}/${y.tahun_selesai} · ${y.semester === 'GENAP' ? 'Genap' : 'Ganjil'}` : 'Belum tersedia';

export function wibNow(date = new Date()) {
  const parts = Object.fromEntries(new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Asia/Jakarta', year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
  }).formatToParts(date).filter((p) => p.type !== 'literal').map((p) => [p.type, p.value]));
  const weekday = (new Date(Date.UTC(Number(parts.year), Number(parts.month) - 1, Number(parts.day))).getUTCDay() + 6) % 7;
  return { weekday, minute: Number(parts.hour) * 60 + Number(parts.minute), date: `${parts.year}-${parts.month}-${parts.day}` };
}

export function nextWeeklyClass(schedules, now = wibNow()) {
  const candidates = schedules.map((row) => {
    const day = WEEK_DAYS.indexOf(String(row.hari).toUpperCase());
    let ahead = (day - now.weekday + 7) % 7;
    const start = minutes(row.jam_mulai), end = minutes(row.jam_selesai);
    if (ahead === 0 && end <= now.minute) ahead = 7;
    return { schedule: row, ahead, start, underway: ahead === 0 && start <= now.minute && now.minute < end };
  });
  return candidates.sort((a, b) => a.ahead - b.ahead || a.start - b.start)[0] || null;
}
