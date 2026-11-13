import { useCallback, useEffect, useMemo, useRef, useState } from "react";

import PageHeader from "../../components/ui/PageHeader";
import Card from "../../components/ui/Card";
import Select from "../../components/ui/Select";
import Badge from "../../components/ui/Badge";
import EmptyState from "../../components/ui/EmptyState";
import ErrorState from "../../components/ui/ErrorState";
import LoadingState from "../../components/ui/LoadingState";

import { homeroomApi } from "../../services/homeroomApi";
import { classMemberApi } from "../../services/classMemberApi";
import { attendanceApi } from "../../services/attendanceApi";
import { gradeApi } from "../../services/gradeApi";

import "./HomeroomPage.css";

const semesterOptions = [
  { value: "", label: "Semua Semester" },
  { value: "GANJIL", label: "Ganjil" },
  { value: "GENAP", label: "Genap" },
];

const attendanceMetricDefinitions = [
  { key: "total", label: "Total" },
  { key: "hadir", label: "Hadir" },
  { key: "izin", label: "Izin" },
  { key: "sakit", label: "Sakit" },
  { key: "alpa", label: "Alpa" },
];

function getErrorMessage(error, fallback) {
  return error?.message || fallback;
}

function getAcademicYearLabel(year) {
  if (!year) return "Tahun akademik tidak tersedia";

  const start = year.tahun_mulai ?? "";
  const end = year.tahun_selesai ?? "";
  const semester = year.semester ?? "";

  return `${start}/${end}${semester ? ` - ${semester}` : ""}`;
}

function getStudentName(student) {
  return (
    student?.nama_lengkap ||
    student?.nama ||
    student?.siswa?.nama_lengkap ||
    student?.siswa?.nama ||
    student?.user?.name ||
    "-"
  );
}

function getStudentNis(student) {
  return student?.nis || student?.siswa?.nis || student?.nomor_induk || "-";
}

function getStudentId(student) {
  return student?.siswa?.id || student?.student_id || student?.id || "-";
}

/**
 * Mendukung dua bentuk respons yang umum dipakai service:
 * 1. Body API langsung: { data: ... }
 * 2. Axios response: { data: { data: ... } }
 */
function getPayload(response) {
  const outer = response?.data ?? response;

  if (
    outer &&
    typeof outer === "object" &&
    !Array.isArray(outer) &&
    outer.data !== undefined
  ) {
    return outer.data;
  }

  return outer;
}

function getCollection(response) {
  const payload = getPayload(response);

  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.data)) return payload.data;
  if (Array.isArray(payload?.items)) return payload.items;

  return [];
}

function getCollectionTotal(response, fallback = 0) {
  const candidates = [
    response?.meta,
    response?.data?.meta,
    response?.data?.data?.meta,
  ];

  const meta = candidates.find(
    (candidate) => candidate && typeof candidate === "object",
  );

  const total = Number(meta?.total);
  return Number.isFinite(total) ? total : fallback;
}

function getPayloadObject(response) {
  const payload = getPayload(response);

  if (!payload || typeof payload !== "object" || Array.isArray(payload)) {
    return {};
  }

  return payload;
}

function getAttendanceMetrics(response) {
  const data = getPayloadObject(response);

  return attendanceMetricDefinitions.map(({ key, label }) => ({
    key,
    label,
    value: data[key] ?? 0,
  }));
}

function formatMetricLabel(value) {
  return String(value)
    .replaceAll("_", " ")
    .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

/**
 * Struktur rekap nilai belum tersedia/terverifikasi.
 * Fungsi ini hanya menampilkan properti primitif dari objek rekap,
 * tanpa mengasumsikan rumus atau arti indikator nilai.
 */
function getGradeMetrics(response) {
  const object = getPayloadObject(response);
  const candidates = [
    object.summary,
    object.recap,
    object.rekap,
    object.statistics,
    object.stats,
    object,
  ];

  const source = candidates.find(
    (candidate) =>
      candidate &&
      typeof candidate === "object" &&
      !Array.isArray(candidate),
  );

  if (!source) return [];

  const ignoredKeys = new Set([
    "data",
    "meta",
    "links",
    "pagination",
    "students",
    "items",
    "details",
    "records",
  ]);

  return Object.entries(source)
    .filter(([key, value]) => {
      if (ignoredKeys.has(key)) return false;
      if (value === null || value === undefined) return false;
      if (typeof value === "object") return false;

      return (
        typeof value === "number" ||
        (typeof value === "string" && value.trim() !== "")
      );
    })
    .map(([key, value]) => ({
      key,
      label: formatMetricLabel(key),
      value,
    }));
}

function getTodayDateString() {
  const today = new Date();
  const year = today.getFullYear();
  const month = String(today.getMonth() + 1).padStart(2, "0");
  const day = String(today.getDate()).padStart(2, "0");

  return `${year}-${month}-${day}`;
}

function getRecapTotal(response) {
  const total = Number(getPayloadObject(response).total ?? 0);
  return Number.isFinite(total) ? total : 0;
}

function isAssignmentActive(assignment) {
  const today = getTodayDateString();
  const startDate = assignment?.tanggal_mulai?.slice(0, 10);
  const endDate = assignment?.tanggal_selesai?.slice(0, 10);

  if (!startDate || startDate > today) return false;
  if (endDate && endDate < today) return false;

  return true;
}

function getAssignmentClassName(assignment) {
  return (
    assignment?.kelas?.nama ||
    (assignment?.kelas_id
      ? `Kelas #${assignment.kelas_id}`
      : "Kelas tidak tersedia")
  );
}

function getAssignmentTeacherName(assignment, user) {
  return (
    assignment?.guru?.nama_lengkap ||
    user?.guru?.nama_lengkap ||
    user?.name ||
    "Guru"
  );
}

function getAssignmentYear(assignment) {
  return getAcademicYearLabel(assignment?.tahun_akademik);
}

function getAssignmentOptionLabel(assignment) {
  return `${getAssignmentClassName(assignment)} — ${getAssignmentYear(assignment)}`;
}

function MetricList({ items, emptyMessage }) {
  if (!items.length) {
    return <p className="homeroom-page__muted">{emptyMessage}</p>;
  }

  return (
    <div className="homeroom-page__metrics">
      {items.map((item) => (
        <div className="homeroom-page__metric" key={item.key}>
          <span>{item.label}</span>
          <strong>{item.value}</strong>
        </div>
      ))}
    </div>
  );
}

export default function HomeroomPage() {
  const [assignments, setAssignments] = useState([]);
  const [selectedAssignmentId, setSelectedAssignmentId] = useState("");

  const [students, setStudents] = useState([]);
  const [studentTotal, setStudentTotal] = useState(0);
  const [attendanceRecap, setAttendanceRecap] = useState(null);
  const [gradeRecap, setGradeRecap] = useState(null);

  const [semester, setSemester] = useState("");

  const [assignmentLoading, setAssignmentLoading] = useState(true);
  const [contentLoading, setContentLoading] = useState(false);

  const [assignmentError, setAssignmentError] = useState(null);
  const [studentError, setStudentError] = useState(null);
  const [attendanceError, setAttendanceError] = useState(null);
  const [gradeError, setGradeError] = useState(null);

  // Menandai request terbaru agar respons lama tidak menimpa pilihan terbaru.
  const contentRequestId = useRef(0);

  const activeAssignments = useMemo(
    () => assignments.filter(isAssignmentActive),
    [assignments],
  );

  const selectedAssignment = useMemo(
    () =>
      activeAssignments.find(
        (item) => String(item.id) === String(selectedAssignmentId),
      ) || null,
    [activeAssignments, selectedAssignmentId],
  );

  const assignmentOptions = useMemo(
    () =>
      activeAssignments.map((assignment) => ({
        value: String(assignment.id),
        label: getAssignmentOptionLabel(assignment),
      })),
    [activeAssignments],
  );

  const loadAssignments = useCallback(async () => {
    setAssignmentLoading(true);
    setAssignmentError(null);

    try {
      const response = await homeroomApi.getAll({
        page: 1,
        per_page: 100,
      });

      const records = getCollection(response);
      const activeRecords = records.filter(isAssignmentActive);

      setAssignments(records);

      setSelectedAssignmentId((current) => {
        const stillExists = activeRecords.some(
          (item) => String(item.id) === String(current),
        );

        if (stillExists) return current;

        return activeRecords.length === 1 ? String(activeRecords[0].id) : "";
      });
    } catch (error) {
      setAssignments([]);
      setSelectedAssignmentId("");
      setAssignmentError(error);
    } finally {
      setAssignmentLoading(false);
    }
  }, []);

  const clearClassData = useCallback(() => {
    setStudents([]);
    setStudentTotal(0);
    setAttendanceRecap(null);
    setGradeRecap(null);
    setStudentError(null);
    setAttendanceError(null);
    setGradeError(null);
  }, []);

  const loadClassData = useCallback(async () => {
    const requestId = ++contentRequestId.current;

    if (!selectedAssignment) {
      clearClassData();
      setContentLoading(false);
      return;
    }

    const classId = selectedAssignment.kelas_id;
    const academicYearId = selectedAssignment.tahun_akademik_id;

    if (!classId || !academicYearId) {
      clearClassData();
      setContentLoading(false);
      setStudentError(
        new Error(
          "Data kelas atau tahun akademik pada penugasan belum tersedia.",
        ),
      );
      return;
    }

    setContentLoading(true);
    clearClassData();

    const params = {
      class_id: classId,
      academic_year_id: academicYearId,
      ...(semester ? { semester } : {}),
    };

    try {
      const results = await Promise.allSettled([
        classMemberApi.getAll(classId, {
          page: 1,
          per_page: 100,
        }),
        attendanceApi.getRecap(params),
        gradeApi.getRecap(params),
      ]);

      // Abaikan hasil request yang sudah tidak relevan.
      if (requestId !== contentRequestId.current) return;

      const [studentResult, attendanceResult, gradeResult] = results;

      if (studentResult.status === "fulfilled") {
        const records = getCollection(studentResult.value);
        setStudents(records);
        setStudentTotal(getCollectionTotal(studentResult.value, records.length));
      } else {
        setStudentError(studentResult.reason);
      }

      if (attendanceResult.status === "fulfilled") {
        setAttendanceRecap(attendanceResult.value);
      } else {
        setAttendanceError(attendanceResult.reason);
      }

      if (gradeResult.status === "fulfilled") {
        setGradeRecap(gradeResult.value);
      } else {
        setGradeError(gradeResult.reason);
      }
    } catch (error) {
      if (requestId === contentRequestId.current) {
        setStudentError(error);
        setAttendanceError(error);
        setGradeError(error);
      }
    } finally {
      if (requestId === contentRequestId.current) {
        setContentLoading(false);
      }
    }
  }, [selectedAssignment, semester, clearClassData]);

  useEffect(() => {
    loadAssignments();
  }, [loadAssignments]);

  useEffect(() => {
    loadClassData();

    return () => {
      // Invalidasi request ketika kelas/semester berubah atau halaman dilepas.
      contentRequestId.current += 1;
    };
  }, [loadClassData]);

  const attendanceMetrics = useMemo(
    () => (attendanceRecap ? getAttendanceMetrics(attendanceRecap) : []),
    [attendanceRecap],
  );

  const gradeMetrics = useMemo(
    () => (gradeRecap ? getGradeMetrics(gradeRecap) : []),
    [gradeRecap],
  );

  function handleAssignmentChange(event) {
    setSelectedAssignmentId(event.target.value);
  }

  function handleSemesterChange(event) {
    setSemester(event.target.value);
  }

  const hasContentError =
    Boolean(studentError) ||
    Boolean(attendanceError) ||
    Boolean(gradeError);

  return (
    <section className="homeroom-page">
      <PageHeader
        title="Konteks Wali Kelas"
        description="Pantau data siswa, kehadiran, dan hasil penilaian kelas yang menjadi tanggung jawab Anda."
      />

      {assignmentLoading && (
        <LoadingState message="Memuat penugasan Wali Kelas..." />
      )}

      {!assignmentLoading && assignmentError && (
        <ErrorState
          message={getErrorMessage(
            assignmentError,
            "Penugasan Wali Kelas gagal dimuat.",
          )}
        />
      )}

      {!assignmentLoading &&
        !assignmentError &&
        activeAssignments.length === 0 && (
          <EmptyState
            title="Belum ada penugasan Wali Kelas"
            description="Akun Guru ini belum memiliki penugasan Wali Kelas yang aktif. Hubungi Tata Usaha jika data penugasan belum sesuai."
          />
        )}

      {!assignmentLoading &&
        !assignmentError &&
        activeAssignments.length > 0 && (
          <>
            <Card>
              <div className="homeroom-page__context">
                <div className="homeroom-page__context-heading">
                  <div>
                    <span className="homeroom-page__eyebrow">
                      Konteks penugasan
                    </span>
                    <h2>Penugasan Wali Kelas</h2>
                    <p>
                      Pilih kelas yang ingin dipantau. Data yang ditampilkan
                      mengikuti penugasan dan tahun akademik yang dipilih.
                    </p>
                  </div>

                  <Badge variant="success">Guru</Badge>
                </div>

                {activeAssignments.length > 1 ? (
                  <Select
                    id="homeroom-assignment"
                    name="assignment_id"
                    label="Pilih Kelas"
                    value={selectedAssignmentId}
                    options={assignmentOptions}
                    placeholder="Pilih penugasan"
                    onChange={handleAssignmentChange}
                  />
                ) : (
                  <div className="homeroom-page__single-assignment">
                    <strong>
                      {getAssignmentClassName(activeAssignments[0])}
                    </strong>
                    <span>{getAssignmentYear(activeAssignments[0])}</span>
                  </div>
                )}

                {selectedAssignment && (
                  <div className="homeroom-page__context-details">
                    <div>
                      <span>Guru Wali</span>
                      <strong>
                        {getAssignmentTeacherName(selectedAssignment)}
                      </strong>
                    </div>

                    <div>
                      <span>Kelas</span>
                      <strong>
                        {getAssignmentClassName(selectedAssignment)}
                      </strong>
                    </div>

                    <div>
                      <span>Tahun Akademik</span>
                      <strong>{getAssignmentYear(selectedAssignment)}</strong>
                    </div>

                    <div>
                      <span>Status Penugasan</span>
                      <Badge variant="success">Aktif</Badge>
                    </div>
                  </div>
                )}
              </div>
            </Card>

            {selectedAssignment && (
              <>
                <div className="homeroom-page__section-heading">
                  <div>
                    <h2>Monitoring Kelas</h2>
                    <p>
                      Ringkasan informasi akademik kelas yang sedang dipilih.
                    </p>
                  </div>

                  <div className="homeroom-page__filters">
                    <Select
                      id="homeroom-semester"
                      name="semester"
                      label="Semester"
                      value={semester}
                      options={semesterOptions}
                      onChange={handleSemesterChange}
                    />
                  </div>
                </div>

                {contentLoading ? (
                  <LoadingState message="Memuat data monitoring kelas..." />
                ) : (
                  <>
                    {hasContentError && (
                      <div className="homeroom-page__retry-row">
                        <p>
                          Sebagian data monitoring gagal dimuat. Periksa pesan
                          pada bagian terkait atau coba muat ulang.
                        </p>
                        <button
                          type="button"
                          className="homeroom-page__retry"
                          onClick={loadClassData}
                        >
                          Coba Muat Ulang
                        </button>
                      </div>
                    )}

                    <div className="homeroom-page__summary-grid">
                      <Card>
                        <div className="homeroom-page__summary">
                          <span>Total Siswa</span>
                          <strong>
                            {studentError ? "—" : studentTotal}
                          </strong>
                          <small>
                            {studentError
                              ? "Data siswa gagal dimuat"
                              : "Jumlah anggota kelas"}
                          </small>
                        </div>
                      </Card>

                      <Card>
                        <div className="homeroom-page__summary">
                          <span>Total Absensi</span>
                          <strong>
                            {attendanceError
                              ? "—"
                              : getRecapTotal(attendanceRecap)}
                          </strong>
                          <small>
                            {attendanceError
                              ? "Rekap absensi gagal dimuat"
                              : "Total catatan absensi pada filter terpilih"}
                          </small>
                        </div>
                      </Card>

                      <Card>
                        <div className="homeroom-page__summary">
                          <span>Status Rekap Nilai</span>
                          <strong>
                            {gradeError
                              ? "Gagal"
                              : gradeRecap
                                ? "Tersedia"
                                : "—"}
                          </strong>
                          <small>
                            {gradeError
                              ? "Rekap nilai gagal dimuat"
                              : "Status respons endpoint rekap nilai"}
                          </small>
                        </div>
                      </Card>
                    </div>

                    <Card>
                      <div className="homeroom-page__card-heading">
                        <div>
                          <h3>Daftar Siswa</h3>
                          <p>
                            Daftar siswa berdasarkan anggota kelas yang
                            ditugaskan.
                          </p>
                        </div>
                        {!studentError && (
                          <Badge variant="neutral">
                            {studentTotal} Siswa
                          </Badge>
                        )}
                      </div>

                      {studentError ? (
                        <ErrorState
                          message={getErrorMessage(
                            studentError,
                            "Daftar siswa gagal dimuat.",
                          )}
                        />
                      ) : students.length === 0 ? (
                        <EmptyState
                          title="Belum ada data siswa"
                          description="Belum terdapat anggota kelas pada kelas dan tahun akademik yang dipilih."
                        />
                      ) : (
                        <div className="homeroom-page__table-wrapper">
                          <table className="homeroom-page__table">
                            <thead>
                              <tr>
                                <th>No.</th>
                                <th>NIS</th>
                                <th>Nama Siswa</th>
                                <th>ID Siswa</th>
                              </tr>
                            </thead>

                            <tbody>
                              {students.map((student, index) => (
                                <tr
                                  key={
                                    student?.id ||
                                    student?.siswa?.id ||
                                    `${getStudentNis(student)}-${index}`
                                  }
                                >
                                  <td>{index + 1}</td>
                                  <td>{getStudentNis(student)}</td>
                                  <td>
                                    <strong>{getStudentName(student)}</strong>
                                  </td>
                                  <td>{getStudentId(student)}</td>
                                </tr>
                              ))}
                            </tbody>
                          </table>
                        </div>
                      )}
                    </Card>

                    <div className="homeroom-page__recap-grid">
                      <Card>
                        <div className="homeroom-page__card-heading">
                          <div>
                            <h3>Rekap Kehadiran</h3>
                            <p>
                              Ringkasan presensi kelas berdasarkan filter
                              akademik.
                            </p>
                          </div>
                        </div>

                        {attendanceError ? (
                          <ErrorState
                            message={getErrorMessage(
                              attendanceError,
                              "Rekap kehadiran gagal dimuat.",
                            )}
                          />
                        ) : attendanceRecap ? (
                          <MetricList
                            items={attendanceMetrics}
                            emptyMessage="API belum mengembalikan indikator rekap yang dapat ditampilkan."
                          />
                        ) : (
                          <EmptyState
                            title="Rekap belum tersedia"
                            description="Belum ada respons rekap kehadiran untuk filter ini."
                          />
                        )}
                      </Card>

                      <Card>
                        <div className="homeroom-page__card-heading">
                          <div>
                            <h3>Rekap Nilai</h3>
                            <p>
                              Ringkasan penilaian siswa pada kelas dan tahun
                              akademik terpilih.
                            </p>
                          </div>
                        </div>

                        {gradeError ? (
                          <ErrorState
                            message={getErrorMessage(
                              gradeError,
                              "Rekap nilai gagal dimuat.",
                            )}
                          />
                        ) : gradeRecap ? (
                          <MetricList
                            items={gradeMetrics}
                            emptyMessage="API belum mengembalikan indikator rekap yang dapat ditampilkan."
                          />
                        ) : (
                          <EmptyState
                            title="Rekap belum tersedia"
                            description="Belum ada respons rekap nilai untuk filter ini."
                          />
                        )}
                      </Card>
                    </div>
                  </>
                )}
              </>
            )}

            {!selectedAssignment && activeAssignments.length > 1 && (
              <Card>
                <EmptyState
                  title="Pilih penugasan terlebih dahulu"
                  description="Pilih salah satu kelas aktif untuk melihat data siswa, absensi, dan nilai."
                />
              </Card>
            )}
          </>
        )}
    </section>
  );
}
