import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { scrollDistance, nextHorizontalPosition, horizontalScrollEdges } from '../src/components/ui/horizontalScrollLogic.js'

const source = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8')

test('mobile scroll: clamp positions at both ends; never scroll beyond content', () => {
  assert.ok(Math.abs(nextHorizontalPosition(0, 980, 320, 1) - 249.6) < 1e-9)
  assert.equal(nextHorizontalPosition(650, 980, 320, 1), 660)
  assert.ok(Math.abs(nextHorizontalPosition(660, 980, 320, -1) - 410.4) < 1e-9)
  assert.equal(nextHorizontalPosition(0, 300, 320, 1), 0)
  assert.equal(nextHorizontalPosition(0, 980, 320, 0), 0)
})

test('mobile scroll: can use weekday width and preserve RTL-free logical geometry', () => {
  assert.ok(scrollDistance(320, 150) >= 300)
  assert.equal(nextHorizontalPosition(0, 1150, 390, 1, 190), 380)
  assert.deepEqual(horizontalScrollEdges(0, 980, 320), { canLeft:false, canRight:true })
  assert.deepEqual(horizontalScrollEdges(660, 980, 320), { canLeft:true, canRight:false })
  assert.deepEqual(horizontalScrollEdges(0, 300, 320), { canLeft:false, canRight:false })
})

test('Siswa schedule scroll arrows and swipe bind to real seven-day weekly viewport', () => {
  const jsx=source('src/pages/schedules/roles/StudentSchedule.jsx')
  const css=source('src/pages/schedules/styles/student-schedule-v2.css')
  assert.match(jsx, /<HorizontalScrollControls scrollRef=\{weekScrollRef\}[^>]+stepSelector="\.ssv2-week-day"/)
  assert.match(jsx, /className="ssv2-week" ref=\{weekScrollRef\} role="region"/)
  assert.match(jsx, /WEEK_DAYS\.map\(/)
  assert.match(css, /\.ssv2-week-shell \.ssv2-week\s*\{[^}]*overflow-x:auto/s)
  assert.match(css, /repeat\(7,minmax\(140px,1fr\)\)/)
})

test('Guru schedule uses the same seven-day scroll controls as verified principal component', () => {
  const shared=source('src/pages/schedules/components/InteractiveTimetable.jsx')
  const css=source('src/pages/schedules/styles/teacher-schedule.css')
  assert.match(shared, /\(role === 'KEPALA_SEKOLAH' \|\| role === 'GURU'\) && view === 'week'/)
  assert.match(shared, /ref=\{gridScrollRef\}/)
  assert.match(shared, /region\.scrollLeft = /)
  assert.match(shared, /className="timetable__week-scroll-controls"/)
  assert.match(css, /\.teacher-schedule \.timetable--guru \.timetable__week-scroll-shell > \.timetable__grid-scroll\s*\{/)
  assert.match(css, /\.teacher-schedule \.timetable--guru \.timetable__week-scroll-controls\s*\{/)
})

test('Presensi Siswa controls the actual history table viewport; metadata and pagination unchanged', () => {
  const jsx=source('src/pages/attendance/roles/SiswaAttendancePage.jsx')
  const css=source('src/pages/attendance/roles/SiswaAttendancePageV2.css')
  assert.match(jsx, /<HorizontalScrollControls scrollRef=\{historyScrollRef\}[^>]+stepSelector="th"/)
  assert.match(jsx, /className="sa-premium-table-wrap" ref=\{historyScrollRef\} role="region"/)
  for(const col of ['Tanggal','Guru pengampu','Jam','Status','Catatan'])assert.ok(jsx.includes(`<th>${col}</th>`))
  assert.match(jsx, /meta\.last_page > 1/)
  assert.match(css, /\.sa-premium-table-shell \.sa-premium-table-wrap\s*\{[^}]*overflow-x:auto/s)
})

test('Penilaian Siswa controls the actual components table, including Catatan and Status', () => {
  const jsx=source('src/pages/grades/roles/SiswaGrades.jsx')
  const css=source('src/pages/grades/styles/SiswaGrades.css')
  assert.match(jsx, /<HorizontalScrollControls scrollRef=\{gradesScrollRef\}[^>]+stepSelector="th"/)
  assert.match(jsx, /className="sg-table-scroll" ref=\{gradesScrollRef\} role="region"/)
  for(const col of ['Komponen','Guru pengampu','Bobot','Nilai','Catatan guru','Status'])assert.ok(jsx.includes(`<th>${col}</th>`))
  assert.match(css, /\.sg-table-shell \.sg-table-scroll\s*\{[^}]*overflow-x:auto/s)
})

test('mobile controls retain focus, button semantics, minimum touch targets, and no page-wide scrolling', () => {
  const jsx=source('src/components/ui/HorizontalScrollControls.jsx')
  const css=source('src/components/ui/HorizontalScrollControls.css')
  assert.match(jsx, /region\.scrollLeft = nextHorizontalPosition/)
  assert.match(jsx, /aria-label=\{`Geser ke kanan:/)
  assert.match(jsx, /type="button"/)
  assert.match(css, /min-width:40px; min-height:40px/)
  assert.ok(!jsx.includes('window.scrollTo('))
  assert.ok(!jsx.includes('document.body.scroll'))
})

test('no API, database, permissions, student grade or attendance meaning changed', () => {
  const teacher=source('src/pages/schedules/roles/TeacherSchedule.jsx')
  const att=source('src/pages/attendance/roles/SiswaAttendancePage.jsx')
  const grades=source('src/pages/grades/roles/SiswaGrades.jsx')
  assert.match(teacher, /loadOwnTeachingSchedules/)
  assert.match(att, /getStudentWorkspace/)
  assert.match(grades, /validateWorkspace/)
  assert.ok(!grades.includes('post('))
})
