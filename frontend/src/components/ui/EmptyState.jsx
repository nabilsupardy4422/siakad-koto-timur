export default function EmptyState({
  title = "Belum ada data",
  message = "Belum ada data yang tersedia untuk ditampilkan.",
  action = null,
}) {
  return (
    <div className="ui-state ui-state--empty">
      <div className="ui-state__icon" aria-hidden="true">
        ...
      </div>

      <div className="ui-state__content">
        <strong>{title}</strong>

        <p>{message}</p>

        {action && <div className="ui-state__action">{action}</div>}
      </div>
    </div>
  );
}
