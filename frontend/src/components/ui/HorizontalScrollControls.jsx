import { useCallback, useEffect, useState } from 'react'
import { horizontalScrollEdges, nextHorizontalPosition } from './horizontalScrollLogic.js'
import './HorizontalScrollControls.css'

/** Directly controls the scrollRef viewport; never scrolls the page or a parent card. */
export default function HorizontalScrollControls({ scrollRef, label, stepSelector, endLabel }) {
  const [edges, setEdges] = useState({ canLeft: false, canRight: false })

  const refresh = useCallback(() => {
    const region = scrollRef.current
    if (!region) return
    setEdges(horizontalScrollEdges(region.scrollLeft, region.scrollWidth, region.clientWidth))
  }, [scrollRef])

  useEffect(() => {
    const region = scrollRef.current
    if (!region) return undefined
    let frame
    const refreshSoon = () => {
      if (typeof window !== 'undefined' && window.requestAnimationFrame) {
        window.cancelAnimationFrame?.(frame)
        frame = window.requestAnimationFrame(refresh)
      } else refresh()
    }
    region.addEventListener('scroll', refreshSoon, { passive: true })
    window.addEventListener('resize', refreshSoon)
    const observer = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(refreshSoon) : null
    observer?.observe(region)
    if (region.firstElementChild) observer?.observe(region.firstElementChild)
    refreshSoon()
    return () => {
      region.removeEventListener('scroll', refreshSoon)
      window.removeEventListener('resize', refreshSoon)
      observer?.disconnect()
      if (frame && window.cancelAnimationFrame) window.cancelAnimationFrame(frame)
    }
  }, [refresh, scrollRef])

  const move = (direction) => {
    const region = scrollRef.current
    if (!region) return
    const cell = stepSelector ? region.querySelector(stepSelector) : null
    const cellWidth = cell?.getBoundingClientRect().width || 0
    region.scrollLeft = nextHorizontalPosition(
      region.scrollLeft,
      region.scrollWidth,
      region.clientWidth,
      direction,
      cellWidth,
    )
    refresh()
  }

  const jumpToEnd = () => {
    const region = scrollRef.current
    if (!region) return
    region.scrollLeft = Math.max(0, region.scrollWidth - region.clientWidth)
    refresh()
  }

  if (endLabel && !edges.canLeft && !edges.canRight) return null

  return (
    <div className={`siakad-hscroll-controls${endLabel ? ' siakad-hscroll-controls--directory' : ''}`} role="group" aria-label={label}>
      <span className="siakad-hscroll-hint">{label}. Geser tabel atau gunakan tombol panah.</span>
      <div className="siakad-hscroll-actions">
        <button type="button" aria-label={`Geser ke kiri: ${label}`} onClick={() => move(-1)} disabled={!edges.canLeft}>‹</button>
        <button type="button" aria-label={`Geser ke kanan: ${label}`} onClick={() => move(1)} disabled={!edges.canRight}>›</button>
        {endLabel && <button type="button" className="siakad-hscroll-end" aria-label={`Ke kolom aksi: ${label}`} onClick={jumpToEnd} disabled={!edges.canRight}>{endLabel}</button>}
      </div>
    </div>
  )
}
