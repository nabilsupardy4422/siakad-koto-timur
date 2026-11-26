import { useEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'

const MENU_WIDTH = 220
const VIEWPORT_GAP = 8

export default function UserActionsMenu({
  user,
  canUpdate,
  canToggle,
  canReset = false,
  canIssueActivation = false,
  disabled = false,
  onEdit,
  onToggle,
  onReset,
  onIssueActivation,
}) {
  const [open, setOpen] = useState(false)
  const [position, setPosition] = useState({ top: 0, left: 0 })

  const triggerRef = useRef(null)
  const menuRef = useRef(null)

  const isActive = user.account_status === 'active'

  useEffect(() => {
    if (!open) return

    menuRef.current
      ?.querySelector('button')
      ?.focus({ preventScroll: true })

    function handleOutsideClick(event) {
      if (
        !triggerRef.current?.contains(event.target) &&
        !menuRef.current?.contains(event.target)
      ) {
        setOpen(false)
      }
    }

    function handleKeyDown(event) {
      if (event.key === 'Escape') {
        setOpen(false)
        triggerRef.current?.focus()
      }
    }

    function handleScroll() {
      setOpen(false)
    }

    document.addEventListener('pointerdown', handleOutsideClick)
    document.addEventListener('keydown', handleKeyDown)
    window.addEventListener('scroll', handleScroll, true)
    window.addEventListener('resize', handleScroll)

    return () => {
      document.removeEventListener('pointerdown', handleOutsideClick)
      document.removeEventListener('keydown', handleKeyDown)
      window.removeEventListener('scroll', handleScroll, true)
      window.removeEventListener('resize', handleScroll)
    }
  }, [open])

  function toggleMenu() {
    if (disabled) return

    if (open) {
      setOpen(false)
      return
    }

    const rect = triggerRef.current?.getBoundingClientRect()
    if (!rect) return

    const actionCount =
      Number(canUpdate) +
      Number(canReset) +
      Number(canIssueActivation) +
      Number(canToggle)

    const estimatedHeight = actionCount * 44 + 12

    const left = Math.max(
      VIEWPORT_GAP,
      Math.min(
        rect.right - MENU_WIDTH,
        window.innerWidth - MENU_WIDTH - VIEWPORT_GAP
      )
    )

    const fitsBelow =
      rect.bottom + VIEWPORT_GAP + estimatedHeight <=
      window.innerHeight

    const top = fitsBelow
      ? rect.bottom + VIEWPORT_GAP
      : Math.max(
          VIEWPORT_GAP,
          rect.top - estimatedHeight - VIEWPORT_GAP
        )

    setPosition({ top, left })
    setOpen(true)
  }

  return (
    <>
      <button
        ref={triggerRef}
        type="button"
        className="users-page__menu-trigger"
        onClick={toggleMenu}
        aria-label={`Pilihan aksi untuk ${user.name || user.username}`}
        aria-expanded={open}
        disabled={disabled}
      >
        <span aria-hidden="true">⋯</span>
      </button>

      {open &&
        createPortal(
          <div
            ref={menuRef}
            className="users-page__action-popover"
            role="group"
            aria-label={`Aksi akun ${user.name || user.username}`}
            style={{ top: position.top, left: position.left }}
          >
            {canUpdate && (
              <button
                type="button"
                onClick={() => {
                  setOpen(false)
                  onEdit()
                }}
              >
                <span aria-hidden="true">✎</span>
                Edit Pengguna
              </button>
            )}

            {canIssueActivation && (
              <button
                type="button"
                onClick={() => {
                  setOpen(false)
                  onIssueActivation()
                }}
              >
                <span aria-hidden="true">▤</span>
                Detail & keamanan akun
              </button>
            )}

            {canReset && (
              <button
                type="button"
                onClick={() => {
                  setOpen(false)
                  onReset()
                }}
              >
                <span aria-hidden="true">🔑</span>
                Reset Password
              </button>
            )}

            {canToggle && (
              <button
                type="button"
                className={
                  isActive
                    ? 'users-page__action-danger'
                    : 'users-page__action-success'
                }
                onClick={() => {
                  setOpen(false)
                  onToggle()
                }}
              >
                <span aria-hidden="true">
                  {isActive ? '⊘' : '✓'}
                </span>
                {isActive
                  ? 'Nonaktifkan Akun'
                  : 'Aktifkan Akun'}
              </button>
            )}
          </div>,
          document.body
        )}
    </>
  )
}
