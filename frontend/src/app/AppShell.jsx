import { useEffect, useRef, useState } from 'react'
import { Outlet } from 'react-router-dom'
import RouteContentBoundary from '../routes/RouteContentBoundary.jsx'
import Sidebar from './Sidebar'
import Topbar from './Topbar'
import './AppShell.css'

function getSavedSidebarPreference() {
  try {
    return window.localStorage.getItem('siakad.sidebar.collapsed') === 'true'
  } catch {
    return false
  }
}

export default function AppShell() {
  const [sidebarOpen, setSidebarOpen] = useState(false)
  const [sidebarCollapsed, setSidebarCollapsed] = useState(getSavedSidebarPreference)
  const contentRef = useRef(null)

  useEffect(() => {
    try {
      window.localStorage.setItem('siakad.sidebar.collapsed', String(sidebarCollapsed))
    } catch {
      // Private mode / storage-disabled browsers still support in-session collapse.
    }
  }, [sidebarCollapsed])

  useEffect(() => {
    // Focus is only trapped for the mobile drawer. The desktop sidebar stays a normal landmark.
    if (!sidebarOpen) return undefined
    if (window.matchMedia('(min-width: 1024px)').matches) {
      setSidebarOpen(false)
      return undefined
    }

    const drawer = document.getElementById('siakad-workspace-sidebar')
    const previousFocus = document.activeElement
    const previousOverflow = document.body.style.overflow
    const mainContent = contentRef.current
    const previousInert = mainContent?.inert ?? false
    document.body.style.overflow = 'hidden'
    if (mainContent) mainContent.inert = true

    const getFocusable = () => Array.from(drawer?.querySelectorAll(
      'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
    ) || []).filter((element) => element.getClientRects().length > 0)

    // Wait for the drawer class to be applied before moving focus inside it.
    const focusFrame = window.requestAnimationFrame(() => {
      drawer?.querySelector('.app-sidebar__close')?.focus({ preventScroll: true })
    })

    const handleKeydown = (event) => {
      if (event.key === 'Escape') {
        event.preventDefault()
        setSidebarOpen(false)
        return
      }
      if (event.key !== 'Tab') return
      const focusable = getFocusable()
      if (!focusable.length) {
        event.preventDefault()
        return
      }
      const first = focusable[0]
      const last = focusable[focusable.length - 1]
      const current = document.activeElement
      if (event.shiftKey && (current === first || !drawer?.contains(current))) {
        event.preventDefault()
        last.focus()
      } else if (!event.shiftKey && (current === last || !drawer?.contains(current))) {
        event.preventDefault()
        first.focus()
      }
    }

    const closeWhenDesktop = () => {
      if (window.matchMedia('(min-width: 1024px)').matches) setSidebarOpen(false)
    }
    document.addEventListener('keydown', handleKeydown)
    window.addEventListener('resize', closeWhenDesktop)

    return () => {
      window.cancelAnimationFrame(focusFrame)
      document.body.style.overflow = previousOverflow
      if (mainContent) mainContent.inert = previousInert
      document.removeEventListener('keydown', handleKeydown)
      window.removeEventListener('resize', closeWhenDesktop)
      if (previousFocus?.isConnected) {
        previousFocus.focus({ preventScroll: true })
      }
    }
  }, [sidebarOpen])

  return (
    <div className={`app-shell${sidebarCollapsed ? ' is-sidebar-collapsed' : ''}`}>
      <a className="app-shell__skip" href="#main-content">Lewati navigasi, ke konten</a>
      <Sidebar
        open={sidebarOpen}
        collapsed={sidebarCollapsed}
        onClose={() => setSidebarOpen(false)}
        onToggleCollapse={() => setSidebarCollapsed((current) => !current)}
      />
      <div className="app-shell__content" ref={contentRef}>
        <Topbar open={sidebarOpen} onMenuClick={() => setSidebarOpen(true)} />
        <main className="app-shell__main" id="main-content" tabIndex={-1}>
          <RouteContentBoundary><Outlet /></RouteContentBoundary>
        </main>
      </div>
    </div>
  )
}
