import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'

import FeatureHighlights from './components/landing/FeatureHighlights'
import Hero from './components/landing/Hero'
import Navbar from './components/landing/Navbar'
import AboutSection from './components/landing/AboutSection'
import RoleOverview from './components/landing/RoleOverview'
import FAQSection from './components/landing/FAQSection'
import CTASection from './components/landing/CTASection'
import Footer from './components/landing/Footer'

import LoginPage from './pages/auth/LoginPage'
import DashboardPage from './pages/dashboard/DashboardPage'

import { AuthProvider } from './context/AuthContext.jsx'
import RequireAuth from './routes/RequireAuth'

import AppShell from './app/AppShell'

import './App.css'

function LandingPage() {
  return (
    <div className="app">
      <Navbar />

      <main>
        <Hero />
        <FeatureHighlights />
        <AboutSection />
        <RoleOverview />
        <FAQSection />
        <CTASection />
      </main>

      <Footer />
    </div>
  )
}

function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <Routes>
          <Route path="/" element={<LandingPage />} />

          <Route path="/login" element={<LoginPage />} />

          <Route element={<RequireAuth />}>
            <Route path="/app" element={<AppShell />}>
              <Route
                index
                element={
                  <Navigate
                    to="/app/dashboard"
                    replace
                  />
                }
              />

              <Route
                path="dashboard"
                element={<DashboardPage />}
              />
            </Route>
          </Route>

          <Route
            path="*"
            element={<Navigate to="/" replace />}
          />
        </Routes>
      </AuthProvider>
    </BrowserRouter>
  )
}

export default App