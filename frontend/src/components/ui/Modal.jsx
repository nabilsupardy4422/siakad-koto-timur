import { useEffect, useId, useRef } from "react";
import "./Modal.css";
import { containDialogTab } from "./dialogFocus.js";

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
  const panelRef = useRef(null);
  const id = useId();
  const onCloseRef = useRef(onClose);

  // Simpan callback terbaru tanpa menjalankan ulang efek fokus modal.
  useEffect(() => {
    onCloseRef.current = onClose;
  }, [onClose]);

  // Efek ini hanya berjalan saat modal dibuka atau ditutup.
  useEffect(() => {
    if (!open) return;

    const node = dialogRef.current;
    const previousActiveElement = document.activeElement;
    if (!node.open) node.showModal();
    panelRef.current?.focus({ preventScroll: true });

    return () => {
      if (node.open) node.close();
      if (previousActiveElement?.isConnected) {
        previousActiveElement.focus({ preventScroll: true });
      }
    };
  }, [open]);

  if (!open) return null;

  return (
    <dialog
      ref={dialogRef}
      className="ui-modal"
      aria-modal="true"
      aria-labelledby={title ? `${id}-title` : undefined}
      aria-label={title ? undefined : "Dialog"}
      aria-describedby={description ? `${id}-description` : undefined}
      onCancel={(event) => {
        if (event.target !== event.currentTarget) return;
        event.preventDefault();
        event.stopPropagation();
        onCloseRef.current?.();
      }}
      onKeyDown={containDialogTab}
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) {
          onCloseRef.current?.();
        }
      }}
    >
      <div
        ref={panelRef}
        className={`ui-modal__dialog ui-modal__dialog--${size}`}
        tabIndex={-1}
      >
        <header className="ui-modal__header">
          <div className="ui-modal__heading">
            {title && (
              <h2 id={`${id}-title`} className="ui-modal__title">
                {title}
              </h2>
            )}

            {description && (
              <p id={`${id}-description`} className="ui-modal__description">
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
    </dialog>
  );
}
