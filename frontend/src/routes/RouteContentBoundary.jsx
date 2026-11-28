import { Component, Suspense } from 'react'
import { useLocation } from 'react-router-dom'
import './RouteContentBoundary.css'

// Route content errors must not remove the shared sidebar or the auth guard.
class RouteErrorBoundary extends Component {
  state = { failed: false }
  static getDerivedStateFromError() { return { failed: true } }
  render() {
    if (!this.state.failed) return this.props.children
    return <section className="route-state" role="alert">
      <h1>Halaman belum dapat ditampilkan</h1>
      <p>Periksa koneksi atau muat ulang halaman. Jika ada perubahan yang belum disimpan, perubahan tersebut dapat hilang.</p>
      <button type="button" onClick={() => window.location.reload()}>Muat ulang halaman</button>
    </section>
  }
}

export default function RouteContentBoundary({ children, publicPage = false }) {
  const { pathname } = useLocation()
  // Reset an error on navigation. Do not key by search params: filters must retain state.
  return <RouteErrorBoundary key={pathname}>
    <Suspense fallback={<div className={`route-state${publicPage ? ' route-state--public' : ''}`} role="status" aria-live="polite"><span className="route-state__indicator" aria-hidden="true"/><span>Memuat halaman SIAKAD…</span></div>}>
      {children}
    </Suspense>
  </RouteErrorBoundary>
}
