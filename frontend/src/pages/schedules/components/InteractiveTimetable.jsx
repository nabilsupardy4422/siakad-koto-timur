import { useMemo, useState } from 'react'

import {
  TIMETABLE_DAYS,
  addDays,
  addMonths,
  formatDate,
  getClassTitle,
  getDayIndex,
  getMonthDays,
  getNextSchedule,
  getScheduleMinutes,
  getScheduleTitle,
  getTeacherTitle,
  startOfWeek,
} from '../utils/timetableUtils'


const VIEW_OPTIONS = [
  { value: 'day', label: 'Hari' },
  { value: 'week', label: 'Minggu' },
  { value: 'month', label: 'Bulan' },
]

const HOUR_HEIGHT = 68
const MINUTES_PER_DAY = 24 * 60

function sameDate(a, b) {
  return (
    a.getFullYear() === b.getFullYear() &&
    a.getMonth() === b.getMonth() &&
    a.getDate() === b.getDate()
  )
}

function getDateKey(date) {
  return [
    date.getFullYear(),
    String(date.getMonth() + 1).padStart(2, '0'),
    String(date.getDate()).padStart(2, '0'),
  ].join('-')
}

function getSchedulesForDate(schedules, date) {
  const dayIndex = (date.getDay() + 6) % 7

  return schedules
    .filter((item) => getDayIndex(item.hari) === dayIndex)
    .sort(
      (a, b) =>
        (getScheduleMinutes(a)?.start ?? 0) -
        (getScheduleMinutes(b)?.start ?? 0)
    )
}

function getMonthLabel(date) {
  return formatDate(date, {
    month: 'long',
    year: 'numeric',
  })
}

function getWeekLabel(date) {
  const start = startOfWeek(date)
  const end = addDays(start, 6)

  return `${formatDate(start, {
    day: 'numeric',
    month: 'short',
  })} – ${formatDate(end, {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  })}`
}

function getTimeBounds(schedules) {
  const validTimes = schedules
    .map(getScheduleMinutes)
    .filter(Boolean)

  if (!validTimes.length) {
    return { start: 7 * 60, end: 17 * 60 }
  }

  const earliest = Math.min(...validTimes.map((item) => item.start))
  const latest = Math.max(...validTimes.map((item) => item.end))

  return {
    start: Math.max(0, Math.floor(earliest / 60) * 60),
    end: Math.min(
      MINUTES_PER_DAY,
      Math.ceil(latest / 60) * 60
    ),
  }
}

function getCardStyle(schedule, bounds) {
  const time = getScheduleMinutes(schedule)

  if (!time) return null

  const top = ((time.start - bounds.start) / 60) * HOUR_HEIGHT
  const height = Math.max(
    42,
    (time.duration / 60) * HOUR_HEIGHT
  )

  return {
    top: `${top}px`,
    height: `${height}px`,
  }
}

function TimetableCard({
  schedule,
  role,
  onClick,
  compact = false,
}) {
  return (
    <button
      type="button"
      className={`timetable__event ${compact ? 'timetable__event--compact' : ''}`}
      onClick={() => onClick(schedule)}
      title={`${getScheduleTitle(schedule)} • ${schedule.jam_mulai}–${schedule.jam_selesai}`}
    >
      <strong>{getScheduleTitle(schedule)}</strong>

      <span>
        {schedule.jam_mulai?.slice(0, 5)} –{' '}
        {schedule.jam_selesai?.slice(0, 5)}
      </span>

      {(role === 'GURU' || role === 'KEPALA_SEKOLAH') && (
        <small>{getClassTitle(schedule)}</small>
      )}

      {(role === 'SISWA' || role === 'KEPALA_SEKOLAH') && (
        <small>{getTeacherTitle(schedule)}</small>
      )}
    </button>
  )
}

function MiniCalendar({
  date,
  onChange,
}) {
  const days = getMonthDays(date)
  const today = new Date()

  return (
    <section className="timetable__side-card">
      <div className="timetable__side-heading">
        <h3>{getMonthLabel(date)}</h3>

        <div className="timetable__icon-actions">
          <button
            type="button"
            aria-label="Bulan sebelumnya"
            onClick={() => onChange(addMonths(date, -1))}
          >
            ‹
          </button>

          <button
            type="button"
            aria-label="Bulan berikutnya"
            onClick={() => onChange(addMonths(date, 1))}
          >
            ›
          </button>
        </div>
      </div>

      <div className="timetable__mini-grid">
        {TIMETABLE_DAYS.map((day) => (
          <span key={day.key}>{day.short}</span>
        ))}

        {days.map((item, index) => {
          const inMonth = item.getMonth() === date.getMonth()
          const selected = sameDate(item, date)
          const isToday = sameDate(item, today)

          return (
            <button
              type="button"
              key={`${getDateKey(item)}-${index}`}
              className={[
                'timetable__mini-day',
                !inMonth && 'is-outside',
                selected && 'is-selected',
                isToday && 'is-today',
              ]
                .filter(Boolean)
                .join(' ')}
              onClick={() => onChange(item)}
            >
              {item.getDate()}
            </button>
          )
        })}
      </div>
    </section>
  )
}

function NextScheduleCard({
  schedules,
  role,
  onSelect,
}) {
  const next = useMemo(
    () => getNextSchedule(schedules),
    [schedules]
  )

  return (
    <section className="timetable__side-card">
      <h3>
        {role === 'GURU'
          ? 'Kelas Berikutnya'
          : role === 'KEPALA_SEKOLAH'
            ? 'Jadwal Berikutnya'
            : 'Pelajaran Berikutnya'}
      </h3>

      {!next ? (
        <p className="timetable__muted">
          Belum ada jadwal yang tersedia.
        </p>
      ) : (
        <button
          type="button"
          className="timetable__next"
          onClick={() => onSelect(next.schedule)}
        >
          <span className="timetable__next-day">
            {TIMETABLE_DAYS[
              getDayIndex(next.schedule.hari)
            ]?.short}
          </span>

          <span className="timetable__next-content">
            <strong>{getScheduleTitle(next.schedule)}</strong>

            <small>
              {next.schedule.jam_mulai?.slice(0, 5)} –{' '}
              {next.schedule.jam_selesai?.slice(0, 5)}
            </small>

            <small>
              {role === 'GURU'
                ? getClassTitle(next.schedule)
                : role === 'KEPALA_SEKOLAH'
                  ? `${getClassTitle(next.schedule)} · ${getTeacherTitle(next.schedule)}`
                  : getTeacherTitle(next.schedule)}
            </small>
          </span>
        </button>
      )}
    </section>
  )
}

function ScheduleDetail({
  schedule,
  role,
  onClose,
}) {
  if (!schedule) return null

  const day = TIMETABLE_DAYS[getDayIndex(schedule.hari)]

  return (
    <div
      className="timetable__modal-backdrop"
      role="presentation"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) {
          onClose()
        }
      }}
    >
      <section
        className="timetable__detail"
        role="dialog"
        aria-modal="true"
        aria-labelledby="timetable-detail-title"
      >
        <div className="timetable__detail-heading">
          <div>
            <span className="timetable__eyebrow">DETAIL JADWAL</span>
            <h3 id="timetable-detail-title">
              {getScheduleTitle(schedule)}
            </h3>
          </div>

          <button
            type="button"
            aria-label="Tutup detail"
            onClick={onClose}
          >
            ×
          </button>
        </div>

        <dl className="timetable__detail-list">
          <div>
            <dt>Hari</dt>
            <dd>{day?.label || schedule.hari}</dd>
          </div>

          <div>
            <dt>Waktu</dt>
            <dd>
              {schedule.jam_mulai?.slice(0, 5)} –{' '}
              {schedule.jam_selesai?.slice(0, 5)}
            </dd>
          </div>

          {role === 'GURU' && (
            <div>
              <dt>Kelas</dt>
              <dd>{getClassTitle(schedule)}</dd>
            </div>
          )}

          {role === 'SISWA' && (
            <div>
              <dt>Guru Pengajar</dt>
              <dd>{getTeacherTitle(schedule)}</dd>
            </div>
          )}

          {role === 'KEPALA_SEKOLAH' && (
            <>
              <div>
                <dt>Kelas</dt>
                <dd>{getClassTitle(schedule)}</dd>
              </div>
              <div>
                <dt>Guru Pengajar</dt>
                <dd>{getTeacherTitle(schedule)}</dd>
              </div>
              <div>
                <dt>Mata Pelajaran</dt>
                <dd>{getScheduleTitle(schedule)}</dd>
              </div>
            </>
          )}

          {role === 'GURU' && (
            <div>
              <dt>Jenis Penugasan</dt>
              <dd>
                {schedule.guru_id
                  ? 'Jadwal pembelajaran'
                  : 'Jadwal kelas'}
              </dd>
            </div>
          )}
        </dl>

        <button
          type="button"
          className="timetable__detail-close"
          onClick={onClose}
        >
          Tutup
        </button>
      </section>
    </div>
  )
}

function TimeGrid({
  schedules,
  date,
  view,
  role,
  onSelect,
}) {
  const bounds = getTimeBounds(schedules)
  const hours = []

  for (
    let hour = Math.floor(bounds.start / 60);
    hour <= Math.ceil(bounds.end / 60);
    hour += 1
  ) {
    hours.push(hour)
  }

  const displayedDates =
    view === 'day'
      ? [date]
      : Array.from({ length: 7 }, (_, index) =>
          addDays(startOfWeek(date), index)
        )

  const gridHeight =
    ((bounds.end - bounds.start) / 60) * HOUR_HEIGHT

  return (
    <div className="timetable__grid-scroll">
      <div
        className={`timetable__time-grid ${
          view === 'day' ? 'timetable__time-grid--day' : ''
        }`}
        style={{
          minWidth: view === 'day' ? '520px' : '850px',
        }}
      >
        <div className="timetable__time-axis">
          <div className="timetable__axis-heading">Waktu</div>

          <div
            className="timetable__axis-body"
            style={{ height: `${gridHeight}px` }}
          >
            {hours.map((hour) => (
              <span
                key={hour}
                style={{
                  top: `${
                    ((hour * 60 - bounds.start) / 60) *
                    HOUR_HEIGHT
                  }px`,
                }}
              >
                {String(hour).padStart(2, '0')}:00
              </span>
            ))}
          </div>
        </div>

        {displayedDates.map((item) => {
          const daySchedules = getSchedulesForDate(
            schedules,
            item
          )

          return (
            <div
              className="timetable__day-column"
              key={getDateKey(item)}
            >
              <div
                className={`timetable__day-heading ${
                  sameDate(item, new Date()) ? 'is-today' : ''
                }`}
              >
                <span>
                  {TIMETABLE_DAYS[
                    (item.getDay() + 6) % 7
                  ].label}
                </span>
                <strong>{item.getDate()}</strong>
              </div>

              <div
                className="timetable__day-body"
                style={{ height: `${gridHeight}px` }}
              >
                {hours.map((hour) => (
                  <div
                    key={hour}
                    className="timetable__hour-line"
                    style={{
                      top: `${
                        ((hour * 60 - bounds.start) / 60) *
                        HOUR_HEIGHT
                      }px`,
                    }}
                  />
                ))}

                {daySchedules.map((schedule) => {
                  const style = getCardStyle(schedule, bounds)

                  if (!style) return null

                  return (
                    <div
                      className="timetable__event-position"
                      key={schedule.id}
                      style={style}
                    >
                      <TimetableCard
                        schedule={schedule}
                        role={role}
                        onClick={onSelect}
                      />
                    </div>
                  )
                })}
              </div>
            </div>
          )
        })}
      </div>
    </div>
  )
}

function MonthView({
  schedules,
  date,
  onSelectDate,
  onSelectSchedule,
}) {
  const days = getMonthDays(date)

  return (
    <div className="timetable__month-view">
      <div className="timetable__month-grid">
        {TIMETABLE_DAYS.map((day) => (
          <strong key={day.key}>{day.short}</strong>
        ))}

        {days.map((item, index) => {
          const daySchedules = getSchedulesForDate(
            schedules,
            item
          )

          const inMonth = item.getMonth() === date.getMonth()

          return (
            <button
              type="button"
              key={`${getDateKey(item)}-${index}`}
              className={[
                'timetable__month-cell',
                !inMonth && 'is-outside',
                sameDate(item, new Date()) && 'is-today',
              ]
                .filter(Boolean)
                .join(' ')}
              onClick={() => onSelectDate(item)}
            >
              <span>{item.getDate()}</span>

              {daySchedules.slice(0, 2).map((schedule) => (
                <span
                  key={schedule.id}
                  className="timetable__month-event"
                  onClick={(event) => {
                    event.stopPropagation()
                    onSelectSchedule(schedule)
                  }}
                >
                  {schedule.jam_mulai?.slice(0, 5)}{' '}
                  {getScheduleTitle(schedule)}
                </span>
              ))}

              {daySchedules.length > 2 && (
                <small>+{daySchedules.length - 2} jadwal</small>
              )}
            </button>
          )
        })}
      </div>
    </div>
  )
}

export default function InteractiveTimetable({
  role,
  schedules = [],
  academicYear,
}) {
  const [view, setView] = useState('week')
  const [activeDate, setActiveDate] = useState(new Date())
  const [selectedSchedule, setSelectedSchedule] = useState(null)

  const title = useMemo(() => {
    if (view === 'month') return getMonthLabel(activeDate)
    if (view === 'day') {
      return formatDate(activeDate, {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
      })
    }

    return getWeekLabel(activeDate)
  }, [activeDate, view])

  function navigate(direction) {
    if (view === 'month') {
      setActiveDate((current) =>
        addMonths(current, direction)
      )
      return
    }

    setActiveDate((current) =>
      addDays(current, direction * (view === 'week' ? 7 : 1))
    )
  }

  function selectMiniDate(date) {
    setActiveDate(date)
    setView('day')
  }

  function selectMonthDate(date) {
    setActiveDate(date)
    setView('day')
  }

  const visibleSchedules = useMemo(() => {
    if (view === 'month') return schedules

    if (view === 'day') {
      return getSchedulesForDate(schedules, activeDate)
    }

    return schedules
  }, [schedules, activeDate, view])

  return (
    <div className={`timetable timetable--${role.toLowerCase()}`}>
      <div className="timetable__main">
        <section className="timetable__panel">
          <div className="timetable__toolbar">
            <div>
              <span className="timetable__eyebrow">
                {role === 'GURU'
                  ? 'KEGIATAN MENGAJAR'
                  : role === 'KEPALA_SEKOLAH'
                    ? 'MONITORING AKADEMIK'
                    : 'KEGIATAN PEMBELAJARAN'}
              </span>
              <h2>Timetable</h2>
              <p>
                {academicYear?.tahun_mulai}/{academicYear?.tahun_selesai}
                {' · '}
                Semester {academicYear?.semester === 'GENAP' ? 'Genap' : 'Ganjil'}
              </p>
            </div>

            <div className="timetable__view-switch">
              {VIEW_OPTIONS.map((option) => (
                <button
                  type="button"
                  key={option.value}
                  className={view === option.value ? 'is-active' : ''}
                  onClick={() => setView(option.value)}
                >
                  {option.label}
                </button>
              ))}
            </div>
          </div>

          <div className="timetable__navigation">
            <button
              type="button"
              onClick={() => navigate(-1)}
              aria-label="Sebelumnya"
            >
              ‹
            </button>

            <strong>{title}</strong>

            <button
              type="button"
              onClick={() => navigate(1)}
              aria-label="Berikutnya"
            >
              ›
            </button>

            <button
              type="button"
              className="timetable__today-button"
              onClick={() => setActiveDate(new Date())}
            >
              Hari Ini
            </button>
          </div>

          {view === 'month' ? (
            <MonthView
              schedules={schedules}
              date={activeDate}
              onSelectDate={selectMonthDate}
              onSelectSchedule={setSelectedSchedule}
            />
          ) : (
            <TimeGrid
              schedules={visibleSchedules}
              date={activeDate}
              view={view}
              role={role}
              onSelect={setSelectedSchedule}
            />
          )}

          <div className="timetable__legend">
            <span />
            Jadwal pembelajaran
          </div>
        </section>
      </div>

      <aside className="timetable__sidebar">
        <MiniCalendar
          date={activeDate}
          onChange={selectMiniDate}
        />

        <NextScheduleCard
          schedules={schedules}
          role={role}
          onSelect={setSelectedSchedule}
        />
      </aside>

      <ScheduleDetail
        schedule={selectedSchedule}
        role={role}
        onClose={() => setSelectedSchedule(null)}
      />
    </div>
  )
}
