import { useRef, useState } from 'react'
import { candidatePage } from './homeroomCandidatePickerLogic.js'
import './HomeroomCandidatePicker.css'

// Inline, paginated picker: no popover, auto-focus, blur dismissal or nested scroll.
// Parent owns the selected profile ID and validates the current server snapshot.
export default function HomeroomCandidatePicker({ id, label, value, options = [], onChange, placeholder = 'Pilih guru', disabled = false, required = false }) {
  const [expanded, setExpanded] = useState(false)
  const [query, setQuery] = useState('')
  const [page, setPage] = useState(1)
  const trigger = useRef(null)
  const selected = options.find(option => String(option.value) === String(value))
  const result = candidatePage(options, query, page)
  const visible = expanded && !disabled
  function toggle() {
    setExpanded(open => !open)
    setQuery('')
    setPage(1)
  }
  function choose(option) {
    if (disabled || !options.some(candidate => String(candidate.value) === String(option.value))) return
    onChange(String(option.value))
    setExpanded(false)
    setQuery('')
    setPage(1)
    trigger.current?.focus({ preventScroll: true })
  }
  return <div className="hm-candidate-picker">
    <label className="ui-field__label" htmlFor={`${id}-trigger`} id={`${id}-label`}>{label}{required && <span className="ui-field__required" aria-hidden="true"> *</span>}</label>
    <button type="button" ref={trigger} id={`${id}-trigger`} className="hm-candidate-trigger" disabled={disabled} aria-expanded={visible} aria-controls={visible ? `${id}-panel` : undefined} aria-labelledby={`${id}-label ${id}-selection ${id}-action`} onClick={toggle}>
      <span className="hm-candidate-avatar" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round"><circle cx="12" cy="8" r="3.5"/><path d="M5 20v-2a7 7 0 0 1 14 0v2"/></svg></span>
      <span className="hm-candidate-copy" id={`${id}-selection`}><strong>{selected?.label || placeholder}</strong>{selected?.description && <small>{selected.description}</small>}</span>
      <span className="hm-candidate-action" id={`${id}-action`}>{visible ? 'Tutup' : selected ? 'Ubah' : 'Pilih'}</span>
    </button>
    {visible && <section className="hm-candidate-panel" id={`${id}-panel`} aria-label={`Daftar ${label}`}>
      <label className="ui-field__label" htmlFor={`${id}-search`}>Cari nama atau NIP guru</label>
      <input className="hm-candidate-search" id={`${id}-search`} type="search" autoComplete="off" enterKeyHint="search" value={query} placeholder="Nama atau NIP..." onChange={event => { setQuery(event.target.value); setPage(1) }} onKeyDown={event => { if (event.key === 'Enter') event.preventDefault() }}/>
      <p className="hm-candidate-count" role="status">{result.total} kandidat{result.total ? ` · Halaman ${result.page}/${result.last}` : ''}</p>
      <div className="hm-candidate-list">
        {result.rows.map(option => <button type="button" className="hm-candidate-option" key={option.value} aria-pressed={String(option.value) === String(value)} onClick={() => choose(option)}>
          <span className="hm-candidate-copy"><strong>{option.label}</strong>{option.description && <small>{option.description}</small>}</span>
          <span className="hm-candidate-action">{String(option.value) === String(value) ? 'Terpilih' : 'Pilih'}</span>
        </button>)}
        {!result.rows.length && <p className="hm-candidate-empty">Tidak ada kandidat yang cocok. Coba nama atau NIP lain.</p>}
      </div>
      {result.last > 1 && <div className="hm-candidate-pagination"><button type="button" disabled={result.page <= 1} onClick={() => setPage(result.page - 1)}>Sebelumnya</button><span>{result.page} / {result.last}</span><button type="button" disabled={result.page >= result.last} onClick={() => setPage(result.page + 1)}>Berikutnya</button></div>}
      <p className="hm-candidate-help">Ketuk baris guru untuk memilih. Daftar tidak tertutup saat keyboard atau fokus berpindah.</p>
    </section>}
  </div>
}
