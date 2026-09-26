export default function Card({
    children,
    title = '',
    description = '',
    actions = null,
    padding = 'medium',
    className = '',
  }) {
    const classes = [
      'ui-card',
      `ui-card--padding-${padding}`,
      className,
    ]
      .filter(Boolean)
      .join(' ')

    return (
      <section className={classes}>
        {(title || description || actions) && (
          <header className="ui-card__header">
            <div className="ui-card__heading">
              {title && (
                <h2 className="ui-card__title">
                  {title}
                </h2>
              )}

              {description && (
                <p className="ui-card__description">
                  {description}
                </p>
              )}
            </div>

            {actions && (
              <div className="ui-card__actions">
                {actions}
              </div>
            )}
          </header>
        )}

        <div className="ui-card__body">
          {children}
        </div>
      </section>
    )
  }