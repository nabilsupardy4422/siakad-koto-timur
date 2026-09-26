export default function Select({
    id,
    name,
    label,
    value = '',
    options = [],
    placeholder = 'Pilih data',
    required = false,
    disabled = false,
    error = '',
    hint = '',
    onChange,
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

        <div className="ui-select">
          <select
            id={id}
            name={name}
            value={value}
            required={required}
            disabled={disabled}
            aria-invalid={Boolean(error)}
            aria-describedby={describedBy}
            className={
              error
                ? 'ui-select__control ui-select__control--error'
                : 'ui-select__control'
            }
            onChange={onChange}
          >
            <option value="">
              {placeholder}
            </option>

            {options.map((option) => (
              <option
                key={option.value}
                value={option.value}
                disabled={option.disabled}
              >
                {option.label}
              </option>
            ))}
          </select>

          <span
            className="ui-select__arrow"
            aria-hidden="true"
          >
            <svg
              width="14"
              height="14"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="1.8"
              strokeLinecap="round"
              strokeLinejoin="round"
            >
              <path d="m7 10 5 5 5-5" />
            </svg>
          </span>
        </div>

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