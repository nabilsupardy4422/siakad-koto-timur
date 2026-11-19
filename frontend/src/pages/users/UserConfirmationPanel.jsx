import { useEffect, useRef } from 'react'
import './UserConfirmationPanel.css'

/**
 * Panel konfirmasi di dalam Modal SIAKAD yang sudah ada.
 * Tidak memakai window.confirm dan tidak membuat modal bertumpuk.
 */
export default function UserConfirmationPanel({
  tone = 'warning',
  eyebrow = 'Konfirmasi tindakan',
  title,
  description,
  details = [],
  note,
}) {
  const panelRef = useRef(null)

  useEffect(() => {
    panelRef.current?.focus({ preventScroll: true })
  }, [title])

  return (
    <section
      ref={panelRef}
      tabIndex={-1}
      className={`users-confirm-panel users-confirm-panel--${tone}`}
      role="group"
      aria-label={title}
    >
      <div className="users-confirm-panel__header">
        <span className="users-confirm-panel__icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="25" height="25" fill="none">
            <path
              d="M12 3.5 21 19H3L12 3.5Z"
              stroke="currentColor"
              strokeWidth="1.7"
              strokeLinejoin="round"
            />
            <path
              d="M12 9v4.5M12 16.8h.01"
              stroke="currentColor"
              strokeWidth="1.9"
              strokeLinecap="round"
            />
          </svg>
        </span>
        <div className="users-confirm-panel__heading">
          <span className="users-confirm-panel__eyebrow">{eyebrow}</span>
          <h3>{title}</h3>
          <p>{description}</p>
        </div>
      </div>

      {details.length > 0 && (
        <dl className="users-confirm-panel__details">
          {details.map((item, index) => (
            <div className="users-confirm-panel__detail" key={`${item.label}-${index}`}>
              <dt>{item.label}</dt>
              <dd>{item.value}</dd>
            </div>
          ))}
        </dl>
      )}

      {note && (
        <div className="users-confirm-panel__notice">
          <span className="users-confirm-panel__notice-dot" aria-hidden="true" />
          <p>{note}</p>
        </div>
      )}
    </section>
  )
}
