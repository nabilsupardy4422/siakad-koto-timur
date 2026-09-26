export default function Button({
    children,
    type = 'button',
    variant = 'primary',
    size = 'medium',
    loading = false,
    disabled = false,
    fullWidth = false,
    onClick,
    className = '',
  }) {
    const classes = [
      'ui-button',
      `ui-button--${variant}`,
      `ui-button--${size}`,
      fullWidth ? 'ui-button--full' : '',
      loading ? 'is-loading' : '',
      className,
    ]
      .filter(Boolean)
      .join(' ')

    return (
      <button
        type={type}
        className={classes}
        onClick={onClick}
        disabled={disabled || loading}
      >
        {loading && (
          <span
            className="ui-button__spinner"
            aria-hidden="true"
          />
        )}

        <span>{children}</span>
      </button>
    )
  }