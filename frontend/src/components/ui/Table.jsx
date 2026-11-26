import { useRef } from 'react'
import HorizontalScrollControls from './HorizontalScrollControls.jsx'
import './Table.css'

export default function Table({
  columns = [],
  data = [],
  rowKey = 'id',
  emptyMessage = 'Tidak ada data.',
  renderCell,
  scrollLabel,
}) {
  const scrollRef = useRef(null)
  const table = (
    <div
      className={`ui-table-wrapper${scrollLabel ? ' ui-table-wrapper--guided' : ''}`}
      ref={scrollRef}
      role={scrollLabel ? 'region' : undefined}
      aria-label={scrollLabel}
      tabIndex={scrollLabel ? 0 : undefined}
    >
      <table className="ui-table">
        <thead className="ui-table__head">
          <tr>
            {columns.map((column) => (
              <th
                key={column.key}
                scope="col"
                className={column.className || ''}
              >
                {column.label}
              </th>
            ))}
          </tr>
        </thead>

        <tbody className="ui-table__body">
          {data.length > 0 ? (
            data.map((row, index) => (
              <tr key={row[rowKey] ?? index}>
                {columns.map((column) => (
                  <td
                    key={column.key}
                    className={column.className || ''}
                  >
                    {renderCell
                      ? renderCell(row, column)
                      : row[column.key] ?? '-'}
                  </td>
                ))}
              </tr>
            ))
          ) : (
            <tr>
              <td
                colSpan={columns.length || 1}
                className="ui-table__empty"
              >
                {emptyMessage}
              </td>
            </tr>
          )}
        </tbody>
      </table>
    </div>
  )
  if (!scrollLabel) return table

  return (
    <div className="ui-table-scroll-shell">
      <HorizontalScrollControls scrollRef={scrollRef} label={scrollLabel} endLabel="Ke aksi" />
      {table}
      <HorizontalScrollControls scrollRef={scrollRef} label={`${scrollLabel} (bawah)`} endLabel="Ke aksi" />
    </div>
  )
}