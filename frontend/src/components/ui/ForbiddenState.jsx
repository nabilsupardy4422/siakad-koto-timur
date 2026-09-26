import { useNavigate } from 'react-router-dom'

export default function ForbiddenState({
  message = 'Anda tidak memiliki akses ke halaman atau data ini.',
}) {
  const navigate = useNavigate()

  function handleBack() {
    navigate('/app/dashboard')
  }

  return (
    <div
      className="ui-state ui-state--forbidden"
      role="alert"
    >
      <div
        className="ui-state__icon"
        aria-hidden="true"
      >
        403
      </div>

      <div className="ui-state__content">
        <strong>Akses ditolak</strong>

        <p>{message}</p>

        <button
          type="button"
          className="ui-state__button"
          onClick={handleBack}
        >
          Kembali ke Dashboard
        </button>
      </div>
    </div>
  )
}