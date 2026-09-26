export default function Table({
    columns = [],
    data = [],
    rowKey = 'id',
    emptyMessage = 'Tidak ada data.',
    renderCell,
  }) {
    return (
      <div className="ui-table-wrapper">
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
                        : row[column.key] ?? '—'}
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
  }