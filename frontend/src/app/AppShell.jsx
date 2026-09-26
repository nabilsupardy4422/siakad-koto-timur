import { useState } from 'react'
import { Outlet } from 'react-router-dom'
import Sidebar from './Sidebar'
import Topbar from './Topbar'

export default function AppShell() {
  const [sidebarOpen, setSidebarOpen] = useState(false)

  function closeSidebar() {
    setSidebarOpen(false)
  }

  return (
    <div className="app-shell">
      <Sidebar
        open={sidebarOpen}
        onClose={closeSidebar}
      />

      <div className="app-shell__content">
        <Topbar
          onMenuClick={() => setSidebarOpen(true)}
        />

        <main className="app-shell__main">
          <Outlet />
        </main>
      </div>
    </div>
  )
}