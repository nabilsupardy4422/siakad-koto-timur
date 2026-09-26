const variants = {
    default: 'default',
    success: 'success',
    warning: 'warning',
    danger: 'danger',
    info: 'info',
    neutral: 'neutral',
  }

  export default function Badge({
    children,
    variant = 'default',
    dot = false,
  }) {
    const resolvedVariant =
      variants[variant] || variants.default

    return (
      <span
        className={`ui-badge ui-badge--${resolvedVariant}`}
      >
        {dot && (
          <span
            className="ui-badge__dot"
            aria-hidden="true"
          />
        )}

        {children}
      </span>
    )
  }