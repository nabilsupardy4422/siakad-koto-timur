export default function FormField({
    label,
    htmlFor,
    required = false,
    hint = '',
    error = '',
    children,
  }) {
    return (
      <div className="ui-form-field">
        <label
          htmlFor={htmlFor}
          className="ui-form-field__label"
        >
          <span>{label}</span>

          {required && (
            <span
              className="ui-form-field__required"
              aria-hidden="true"
            >
              *
            </span>
          )}
        </label>

        {children}

        {hint && !error && (
          <p className="ui-form-field__hint">
            {hint}
          </p>
        )}

        {error && (
          <p
            className="ui-form-field__error"
            role="alert"
          >
            {error}
          </p>
        )}
      </div>
    )
  }