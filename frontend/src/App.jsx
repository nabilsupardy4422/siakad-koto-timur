import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'

// Landing page
import FeatureHighlights from './components/landing/FeatureHighlights'
import Hero from './components/landing/Hero'
import Navbar from './components/landing/Navbar'
import AboutSection from './components/landing/AboutSection'
import RoleOverview from './components/landing/RoleOverview'
import FAQSection from './components/landing/FAQSection'
import CTASection from './components/landing/CTASection'
import Footer from './components/landing/Footer'

// Authentication
import LoginPage from './pages/auth/LoginPage'
import { AuthProvider } from './context/AuthContext.jsx'
import RequireAuth from './routes/RequireAuth'

// Application shell
import AppShell from './app/AppShell'

// Dashboard
import DashboardPage from './pages/dashboard/DashboardPage'

// Master data
import AcademicYearsPage from './pages/academic-years/AcademicYearsPage'
import StudentsPage from './pages/students/StudentsPage'
import TeachersPage from './pages/teachers/TeachersPage'
import UsersPage from './pages/users/UsersPage'
import HomeroomManagementPage from './pages/homeroom/HomeroomManagementPage'
import ClassesPage from './pages/classes/ClassesPage'
import ClassMembersPage from './pages/class-members/ClassMembersPage'
import SubjectsPage from './pages/subjects/SubjectsPage'
import SchedulesPage from './pages/schedules/SchedulesPage'
import MaterialsPage from './pages/materials/MaterialsPage'
import AssignmentsPage from './pages/assignments/AssignmentsPage'
import GradesPage from './pages/grades/GradesPage'

// Attendance
// Entry point akan memilih halaman berdasarkan role pengguna.
import AttendancePage from './pages/attendance/AttendancePage'

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
          {/* Halaman publik */}
          <Route path="/" element={<LandingPage />} />
          <Route path="/login" element={<LoginPage />} />

          {/* Semua halaman aplikasi memerlukan autentikasi */}
          <Route element={<RequireAuth />}>
            <Route path="/app" element={<AppShell />}>
              <Route
                index
                element={<Navigate to="/app/dashboard" replace />}
              />

              {/* Dashboard */}
              <Route
                path="dashboard"
                element={<DashboardPage />}
              />

              {/* Master data */}
              <Route
                path="academic-years"
                element={<AcademicYearsPage />}
              />

              <Route
                path="students"
                element={<StudentsPage />}
              />

              <Route
                path="teachers"
                element={<TeachersPage />}
              />

              <Route
                path="users"
                element={<UsersPage />}
              />

              <Route
                path="homeroom-assignments"
                element={<HomeroomManagementPage />}
              />

              <Route
                path="classes"
                element={<ClassesPage />}
              />

              <Route
                path="class-members"
                element={<ClassMembersPage />}
              />

              <Route
                path="subjects"
                element={<SubjectsPage />}
              />

              {/* Jadwal */}
              <Route
                path="schedules"
                element={<SchedulesPage />}
              />

              {/* Presensi: entry point untuk halaman sesuai role */}
              <Route
                path="attendance"
                element={<AttendancePage />}
              />

              <Route path="materials" element={<MaterialsPage />} />

              <Route path="assignments" element={<AssignmentsPage />} />

              {/* Penilaian: entry point, halaman menyesuaikan role */}
              <Route path="grades" element={<GradesPage />} />
            </Route>
          </Route>
        </Routes>
      </AuthProvider>
    </BrowserRouter>
  )
}

export default App