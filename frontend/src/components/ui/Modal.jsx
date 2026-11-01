import { useEffect, useRef } from "react";
import "./Modal.css";

export default function Modal({
  open = false,
  title,
  description,
  onClose,
  children,
  footer,
  size = "medium",
}) {
  const dialogRef = useRef(null);
  const onCloseRef = useRef(onClose);

  // Simpan callback terbaru tanpa menjalankan ulang efek fokus modal.
  useEffect(() => {
    onCloseRef.current = onClose;
  }, [onClose]);

  // Efek ini hanya berjalan saat modal dibuka atau ditutup.
  useEffect(() => {
    if (!open) return;

    const previousActiveElement = document.activeElement;

    dialogRef.current?.focus();

    function handleKeyDown(event) {
      if (event.key === "Escape") {
        onCloseRef.current?.();
      }
    }

    document.addEventListener("keydown", handleKeyDown);

    return () => {
      document.removeEventListener("keydown", handleKeyDown);
      previousActiveElement?.focus?.();
    };
  }, [open]);

  if (!open) return null;

  return (
    <div
      className="ui-modal"
      role="presentation"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) {
          onCloseRef.current?.();
        }
      }}
    >
      <div
        ref={dialogRef}
        className={`ui-modal__dialog ui-modal__dialog--${size}`}
        role="dialog"
        aria-modal="true"
        aria-labelledby="ui-modal-title"
        aria-describedby={description ? "ui-modal-description" : undefined}
        tabIndex={-1}
      >
        <header className="ui-modal__header">
          <div className="ui-modal__heading">
            {title && (
              <h2 id="ui-modal-title" className="ui-modal__title">
                {title}
              </h2>
            )}

            {description && (
              <p id="ui-modal-description" className="ui-modal__description">
                {description}
              </p>
            )}
          </div>

          <button
            type="button"
            className="ui-modal__close"
            onClick={() => onCloseRef.current?.()}
            aria-label="Tutup modal"
          >
            <svg
              viewBox="0 0 24 24"
              width="20"
              height="20"
              fill="none"
              stroke="currentColor"
              strokeWidth="1.8"
              strokeLinecap="round"
              strokeLinejoin="round"
              aria-hidden="true"
            >
              <path d="M18 6 6 18" />
              <path d="m6 6 12 12" />
            </svg>
          </button>
        </header>

        <div className="ui-modal__body">{children}</div>

        {footer && <footer className="ui-modal__footer">{footer}</footer>}
      </div>
    </div>
  );
}
