import { useEffect, useId, useRef, useState } from 'react'
import './SearchableSelect.css'

// Pilihan berbasis tombol + pencarian, dapat digunakan ulang tanpa library.
export default function SearchableSelect({ label, value, options = [], onChange, placeholder = 'Pilih opsi', disabled = false, required = false, searchPlaceholder = 'Cari kelas, tingkat, atau pelajaran...' }) {
  const id = useId()
  const root = useRef(null)
  const trigger = useRef(null)
  const search = useRef(null)
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const selected = options.find(option => String(option.value) === String(value))
  const filtered = options.filter(option => `${option.label} ${option.description || ''} ${option.searchText || ''}`.toLocaleLowerCase('id').includes(query.trim().toLocaleLowerCase('id')))
  useEffect(() => {
    if (!open) return
    search.current?.focus()
    const outside = event => { if (!root.current?.contains(event.target)) setOpen(false) }
    document.addEventListener('pointerdown', outside)
    return () => document.removeEventListener('pointerdown', outside)
  }, [open])
  useEffect(() => { if (disabled) setOpen(false) }, [disabled])
  function close() { setOpen(false); trigger.current?.focus() }
  function onKeyDown(event) {
    if (event.key === 'Escape' && open) { event.preventDefault(); event.stopPropagation(); close() }
    if (event.key === 'ArrowDown' && open && event.target === search.current) {
      event.preventDefault(); root.current?.querySelector('.ss-option')?.focus()
    }
  }
  return <div className="siakad-search-select" ref={root} onKeyDown={onKeyDown} onBlur={event => { if (!event.currentTarget.contains(event.relatedTarget)) setOpen(false) }}>
    <label id={`${id}-label`} htmlFor={`${id}-trigger`}>{label}{required && <span aria-hidden="true"> *</span>}</label>
    <button id={`${id}-trigger`} ref={trigger} className="ss-trigger" type="button" disabled={disabled} aria-expanded={open} aria-controls={open ? `${id}-panel` : undefined} aria-labelledby={`${id}-label ${id}-value`} onClick={() => { setQuery(''); setOpen(!open) }}>
      <span className="ss-icon" aria-hidden="true"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M7 3v4m10-4v4M3 10h18m-13 5h3m2 0h3"/></svg></span>
      <span className="ss-copy" id={`${id}-value`}><strong>{selected?.label || placeholder}</strong>{selected?.description && <small>{selected.description}</small>}</span>
      <span className="ss-chevron" aria-hidden="true">⌄</span>
    </button>
    {open && !disabled && <div className="ss-popover" id={`${id}-panel`} role="group" aria-label={`Pilihan ${label}`}>
      <div className="ss-search"><input ref={search} type="search" aria-label={`Cari ${label}`} value={query} onChange={event => setQuery(event.target.value)} placeholder={searchPlaceholder} /></div>
      <div className="ss-option-label">{label}</div>
      <div className="ss-options">
        {filtered.length ? filtered.map(option => <button key={option.value} type="button" className="ss-option" aria-pressed={String(option.value) === String(value)} onClick={() => { onChange(String(option.value)); close() }}><span><strong>{option.label}</strong>{option.description && <small>{option.description}</small>}</span>{String(option.value) === String(value) && <span className="ss-check" aria-hidden="true">✓</span>}</button>) : <p className="ss-empty" role="status">Tidak ada pilihan yang cocok.</p>}
      </div>
    </div>}
  </div>
}
