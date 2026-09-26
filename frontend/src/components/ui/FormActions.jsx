export default function FormActions({
    children,
    align = 'end',
  }) {
    return (
      <div className={`ui-form-actions ui-form-actions--${align}`}>
        {children}
      </div>
    )
  }