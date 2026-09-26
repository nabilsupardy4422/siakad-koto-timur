export default function ErrorState({
  title = "Terjadi kesalahan",
  message = "Data tidak dapat dimuat. Silakan coba lagi.",
  onRetry = null,
}) {
  return (
    <div className="ui-state ui-state--error" role="alert">
      <div className="ui-state__icon" aria-hidden="true">
        !
      </div>

      <div className="ui-state__content">
        <strong>{title}</strong>

        <p>{message}</p>

        {onRetry && (
          <button type="button" className="ui-state__button" onClick={onRetry}>
            Coba lagi
          </button>
        )}
      </div>
    </div>
  );
}
