import { useNavigate } from 'react-router-dom'

export default function UnauthorizedState({
  message = 'Sesi Anda tidak valid atau telah berakhir.',
}) {
  const navigate = useNavigate()

  function handleLogin() {
    navigate('/login', { replace: true })
  }

  return (
    <div
      className="ui-state ui-state--unauthorized"
      role="alert"
    >
      <div
        className="ui-state__icon"
        aria-hidden="true"
      >
        401
      </div>

      <div className="ui-state__content">
        <strong>Sesi berakhir</strong>

        <p>{message}</p>

        <button
          type="button"
          className="ui-state__button"
          onClick={handleLogin}
        >
          Kembali ke Login
        </button>
      </div>
    </div>
  )
}