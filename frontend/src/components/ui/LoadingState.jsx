export default function LoadingState({
  message = 'Memuat data...',
}) {
  return (
    <div className="ui-state ui-state--loading" role="status">
      <div
        className="ui-state__spinner"
        aria-hidden="true"
      />

      <div className="ui-state__content">
        <strong>Memuat data</strong>
        <p>{message}</p>
      </div>
    </div>
  )
}