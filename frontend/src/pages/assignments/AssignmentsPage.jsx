
import { useAuth } from "../../context/useAuth";
import GuruAssignmentsPage from "./roles/GuruAssignmentsPage";
import SiswaAssignmentsPage from "./roles/SiswaAssignmentsPage";

function getRoleCode(role) {
  if (typeof role === "string") {
    return role.trim().toUpperCase();
  }

  const code = role?.code || role?.name || null;

  return typeof code === "string" ? code.trim().toUpperCase() : null;
}

export default function AssignmentsPage() {
  const { role, status } = useAuth();
  const roleCode = getRoleCode(role);

  if (status === "loading") {
    return (
      <main className="assignments-page">
        <section className="assignments-panel assignments-empty">
          <h1>Memuat Halaman Tugas</h1>
          <p>Data akun sedang diperiksa. Silakan tunggu.</p>
        </section>
      </main>
    );
  }

  if (roleCode === "GURU") {
    return <GuruAssignmentsPage />;
  }

  if (roleCode === "SISWA") {
    return <SiswaAssignmentsPage />;
  }

  return (
    <main className="assignments-page">
      <section className="assignments-panel assignments-empty">
        <h1>Halaman Tugas</h1>
        <p>
          Halaman ini tersedia untuk Guru dan Siswa. Akun dengan role lain
          tidak memiliki akses ke fitur ini.
        </p>
        <p>
          Role terdeteksi: <strong>{roleCode || "Belum terdeteksi"}</strong>
        </p>
      </section>
    </main>
  );
}
