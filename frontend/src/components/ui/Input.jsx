export default function Input({
    id,
    name,
    label,
    type = 'text',
    value = '',
    placeholder = '',
    required = false,
    disabled = false,
    readOnly = false,
    error = '',
    hint = '',
    autoComplete,
    onChange,
    onBlur,
  }) {
    const describedBy = [
      hint && `${id}-hint`,
      error && `${id}-error`,
    ]
      .filter(Boolean)
      .join(' ') || undefined

    return (
      <div className="ui-field">
        {label && (
          <label
            htmlFor={id}
            className="ui-field__label"
          >
            {label}

            {required && (
              <span
                className="ui-field__required"
                aria-hidden="true"
              >
                *
              </span>
            )}
          </label>
        )}

        <input
          id={id}
          name={name}
          type={type}
          value={value}
          placeholder={placeholder}
          required={required}
          disabled={disabled}
          readOnly={readOnly}
          autoComplete={autoComplete}
          aria-invalid={Boolean(error)}
          aria-describedby={describedBy}
          className={`ui-input ${
            error ? 'ui-input--error' : ''
          }`}
          onChange={onChange}
          onBlur={onBlur}
        />

        {hint && !error && (
          <p
            id={`${id}-hint`}
            className="ui-field__hint"
          >
            {hint}
          </p>
        )}

        {error && (
          <p
            id={`${id}-error`}
            className="ui-field__error"
            role="alert"
          >
            {error}
          </p>
        )}
      </div>
    )
  }