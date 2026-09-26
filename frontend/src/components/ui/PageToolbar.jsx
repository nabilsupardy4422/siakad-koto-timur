export default function PageToolbar({
  search = null,
  filters = null,
  actions = null,
  className = "",
}) {
  const classes = ["ui-page-toolbar", className].filter(Boolean).join(" ");

  return (
    <div className={classes}>
      <div className="ui-page-toolbar__filters">
        {search}

        {filters}
      </div>

      {actions && <div className="ui-page-toolbar__actions">{actions}</div>}
    </div>
  );
}
