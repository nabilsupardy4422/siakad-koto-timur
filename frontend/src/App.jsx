import { lazy } from "react";
import RouteContentBoundary from "./routes/RouteContentBoundary.jsx";
import { BrowserRouter, Navigate, Route, Routes } from "react-router-dom";
import { AuthProvider } from "./context/AuthContext.jsx";
import RequireAuth from "./routes/RequireAuth";
import AccessRoute from "./routes/AccessRoute";
import AppShell from "./app/AppShell";

// Route modules load only on demand; guards and shell remain eager.
const LandingPage = lazy(() => import("./pages/public/LandingPage.jsx"));
const LoginPage = lazy(() => import("./pages/auth/LoginPage.jsx"));
const ActivateAccountPage = lazy(() => import("./pages/public/ActivateAccountPage.jsx"));
const DashboardPage = lazy(() => import("./pages/dashboard/DashboardPage.jsx"));
const ProfilePage = lazy(() => import("./pages/profile/ProfilePage.jsx"));
const AcademicYearsPage = lazy(() => import("./pages/academic-years/AcademicYearsPage.jsx"));
const StudentsPage = lazy(() => import("./pages/students/StudentsPage.jsx"));
const TeachersPage = lazy(() => import("./pages/teachers/TeachersPage.jsx"));
const UsersPage = lazy(() => import("./pages/users/UsersPage.jsx"));
const HomeroomManagementPage = lazy(() => import("./pages/homeroom/HomeroomManagementPage.jsx"));
const HomeroomPage = lazy(() => import("./pages/homeroom/HomeroomPage.jsx"));
const ClassesPage = lazy(() => import("./pages/classes/ClassesPage.jsx"));
const ClassMembersPage = lazy(() => import("./pages/class-members/ClassMembersPage.jsx"));
const SubjectsPage = lazy(() => import("./pages/subjects/SubjectsPage.jsx"));
const SchedulesPage = lazy(() => import("./pages/schedules/SchedulesPage.jsx"));
const MaterialsPage = lazy(() => import("./pages/materials/MaterialsPage.jsx"));
const AssignmentsPage = lazy(() => import("./pages/assignments/AssignmentsPage.jsx"));
const GradesPage = lazy(() => import("./pages/grades/GradesPage.jsx"));
const ReportCardsPage = lazy(() => import("./pages/report-cards/ReportCardsPage.jsx"));
const ReportsPage = lazy(() => import("./pages/reports/ReportsPage.jsx"));
const AttendancePage = lazy(() => import("./pages/attendance/AttendancePage.jsx"));

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
            element={<RouteContentBoundary publicPage><LandingPage /></RouteContentBoundary>}
          />

          <Route
            path="/login"
            element={<RouteContentBoundary publicPage><LoginPage /></RouteContentBoundary>}
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
            element={<RouteContentBoundary publicPage><ActivateAccountPage /></RouteContentBoundary>}
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
