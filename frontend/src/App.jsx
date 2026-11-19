import { BrowserRouter, Navigate, Route, Routes } from "react-router-dom";

// ==========================================
// Landing Page
// ==========================================

import FeatureHighlights from "./components/landing/FeatureHighlights";
import Hero from "./components/landing/Hero";
import Navbar from "./components/landing/Navbar";
import AboutSection from "./components/landing/AboutSection";
import RoleOverview from "./components/landing/RoleOverview";
import FAQSection from "./components/landing/FAQSection";
import CTASection from "./components/landing/CTASection";
import Footer from "./components/landing/Footer";

// ==========================================
// Authentication
// ==========================================

import LoginPage from "./pages/auth/LoginPage";
import ActivateAccountPage from "./pages/public/ActivateAccountPage";

import { AuthProvider } from "./context/AuthContext.jsx";

import RequireAuth from "./routes/RequireAuth";
import AccessRoute from "./routes/AccessRoute";

// ==========================================
// Application Shell
// ==========================================

import AppShell from "./app/AppShell";

// ==========================================
// Dashboard
// ==========================================

import DashboardPage from "./pages/dashboard/DashboardPage";
import ProfilePage from "./pages/profile/ProfilePage";

// ==========================================
// Master Data
// ==========================================

import AcademicYearsPage from "./pages/academic-years/AcademicYearsPage";
import StudentsPage from "./pages/students/StudentsPage";
import TeachersPage from "./pages/teachers/TeachersPage";
import UsersPage from "./pages/users/UsersPage";

import HomeroomManagementPage from "./pages/homeroom/HomeroomManagementPage";
import HomeroomPage from "./pages/homeroom/HomeroomPage";

import ClassesPage from "./pages/classes/ClassesPage";
import ClassMembersPage from "./pages/class-members/ClassMembersPage";

import SubjectsPage from "./pages/subjects/SubjectsPage";
import SchedulesPage from "./pages/schedules/SchedulesPage";

import MaterialsPage from "./pages/materials/MaterialsPage";
import AssignmentsPage from "./pages/assignments/AssignmentsPage";
import GradesPage from "./pages/grades/GradesPage";

// ==========================================
// Report Cards
// ==========================================

import ReportCardsPage from "./pages/report-cards/ReportCardsPage";

// ==========================================
// Reports
// ==========================================

import ReportsPage from "./pages/reports/ReportsPage";

// ==========================================
// Attendance
// ==========================================

import AttendancePage from "./pages/attendance/AttendancePage";

// ==========================================
// Public Landing Page
// ==========================================

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
  );
}

// ==========================================
// Main Application Router
// ==========================================

function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <Routes>
          {/* ==================================
              PUBLIC ROUTES
              ================================== */}

          <Route
            path="/"
            element={<LandingPage />}
          />

          <Route
            path="/login"
            element={<LoginPage />}
          />

          {/*
            Aktivasi akun Guru dan Siswa.

            Halaman ini sengaja berada di
            luar RequireAuth karena pengguna
            baru belum memiliki token login.

            Backend tetap memvalidasi kode
            aktivasi satu kali pakai.
          */}

          <Route
            path="/aktivasi-akun"
            element={<ActivateAccountPage />}
          />

          {/* ==================================
              PRIVATE ROUTES
              ================================== */}

          <Route element={<RequireAuth />}>
            <Route
              path="/app"
              element={<AppShell />}
            >
              {/* Default redirect */}

              <Route
                index
                element={
                  <Navigate
                    to="/app/dashboard"
                    replace
                  />
                }
              />

              {/* ==========================
                  Dashboard
                  ========================== */}

              <Route
                path="dashboard"
                element={<DashboardPage />}
              />

              {/* ==========================
                  Profil semua role
                  ========================== */}

              <Route
                path="profil"
                element={<ProfilePage />}
              />

              {/* ==========================
                  Master Data
                  ========================== */}

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

              {/* ==========================
                  Homeroom Management
                  ========================== */}

              <Route
                path="homeroom-assignments"
                element={<HomeroomManagementPage />}
              />

              {/*
                Kelas Saya:
                khusus Guru dengan assignment
                WALI_KELAS.
              */}

              <Route
                element={
                  <AccessRoute
                    role="GURU"
                    assignment="WALI_KELAS"
                  />
                }
              >
                <Route
                  path="homeroom"
                  element={<HomeroomPage />}
                />
              </Route>

              {/* ==========================
                  Classes
                  ========================== */}

              <Route
                path="classes"
                element={<ClassesPage />}
              />

              <Route
                path="class-members"
                element={<ClassMembersPage />}
              />

              {/* ==========================
                  Subjects
                  ========================== */}

              <Route
                path="subjects"
                element={<SubjectsPage />}
              />

              {/* ==========================
                  Schedules
                  ========================== */}

              <Route
                path="schedules"
                element={<SchedulesPage />}
              />

              {/* ==========================
                  Attendance
                  ========================== */}

              <Route
                path="attendance"
                element={<AttendancePage />}
              />

              {/* ==========================
                  Materials and Assignments
                  ========================== */}

              <Route
                path="materials"
                element={<MaterialsPage />}
              />

              <Route
                path="assignments"
                element={<AssignmentsPage />}
              />

              {/* ==========================
                  Grades
                  ========================== */}

              <Route
                path="grades"
                element={<GradesPage />}
              />

              {/* ==========================
                  Report Cards
                  ========================== */}

              <Route
                path="report-cards"
                element={<ReportCardsPage />}
              />

              {/* ==========================
                  Reports
                  ========================== */}

              <Route
                path="reports"
                element={<ReportsPage />}
              />
            </Route>
          </Route>
        </Routes>
      </AuthProvider>
    </BrowserRouter>
  );
}

export default App;
