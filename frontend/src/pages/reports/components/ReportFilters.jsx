import { useEffect, useState } from "react";

import { academicYearApi } from "../../../services/academicYearApi";
import { classApi } from "../../../services/classApi";
import { teacherApi } from "../../../services/teacherApi";
import { studentApi } from "../../../services/studentApi";
import { subjectApi } from "../../../services/subjectApi";

const REPORT_FILTERS = {
  students: ["academic_year_id", "class_id", "search"],
  teachers: ["search", "status"],
  classes: ["academic_year_id"],
  schedules: [
    "academic_year_id",
    "semester",
    "class_id",
    "subject_id",
    "teacher_id",
  ],
  attendance: [
    "academic_year_id",
    "semester",
    "class_id",
    "student_id",
    "date_from",
    "date_to",
  ],
  grades: [
    "academic_year_id",
    "semester",
    "class_id",
    "subject_id",
    "student_id",
  ],
  "semester-recap": ["academic_year_id", "semester", "class_id", "student_id"],
};

const FILTER_LABELS = {
  academic_year_id: "Tahun Akademik",
  class_id: "Kelas",
  search: "Pencarian",
  status: "Status",
  semester: "Semester",
  subject_id: "Mata Pelajaran",
  teacher_id: "Guru",
  student_id: "Siswa",
  date_from: "Tanggal Mulai",
  date_to: "Tanggal Selesai",
};

function extractItems(response) {
  if (Array.isArray(response)) {
    return response;
  }

  if (Array.isArray(response?.data)) {
    return response.data;
  }

  if (Array.isArray(response?.data?.data)) {
    return response.data.data;
  }

  return [];
}

function formatAcademicYear(item) {
  return `${item.tahun_mulai}/${item.tahun_selesai} - ${item.semester}`;
}

export default function ReportFilters({ reportType, onGenerate, loading }) {
  const [filters, setFilters] = useState({});
  const [format, setFormat] = useState("pdf");

  const [academicYears, setAcademicYears] = useState([]);
  const [classes, setClasses] = useState([]);
  const [teachers, setTeachers] = useState([]);
  const [students, setStudents] = useState([]);
  const [subjects, setSubjects] = useState([]);

  const [loadingOptions, setLoadingOptions] = useState({
    academicYears: false,
    classes: false,
    teachers: false,
    students: false,
    subjects: false,
  });

  const [optionError, setOptionError] = useState("");

  const fields = REPORT_FILTERS[reportType] || [];

  const needsAcademicYear = fields.includes("academic_year_id");
  const needsClass = fields.includes("class_id");
  const needsTeacher = fields.includes("teacher_id");
  const needsStudent = fields.includes("student_id");
  const needsSubject = fields.includes("subject_id");

  // Ambil data tahun akademik, guru, dan mata pelajaran.
  useEffect(() => {
    let active = true;

    async function loadInitialOptions() {
      const tasks = [];

      if (needsAcademicYear) {
        tasks.push({
          key: "academicYears",
          request: academicYearApi.getAll(),
          setter: setAcademicYears,
        });
      }

      if (needsTeacher) {
        tasks.push({
          key: "teachers",
          request: teacherApi.getAll({ page_size: 100 }),
          setter: setTeachers,
        });
      }

      if (needsSubject) {
        tasks.push({
          key: "subjects",
          request: subjectApi.getAll({ per_page: 100 }),
          setter: setSubjects,
        });
      }

      if (!tasks.length) return;

      setOptionError("");

      setLoadingOptions((current) => ({
        ...current,
        ...Object.fromEntries(tasks.map((task) => [task.key, true])),
      }));

      const results = await Promise.allSettled(
        tasks.map((task) => task.request),
      );

      if (!active) return;

      const failed = [];

      results.forEach((result, index) => {
        const task = tasks[index];

        if (result.status === "fulfilled") {
          task.setter(extractItems(result.value));
        } else {
          failed.push(FILTER_LABELS[task.key] || task.key);
        }
      });

      if (failed.length) {
        setOptionError("Sebagian data filter gagal dimuat. Silakan coba lagi.");
      }

      setLoadingOptions((current) => ({
        ...current,
        ...Object.fromEntries(tasks.map((task) => [task.key, false])),
      }));
    }

    loadInitialOptions();

    return () => {
      active = false;
    };
  }, [reportType, needsAcademicYear, needsTeacher, needsSubject]);

  // Ambil kelas sesuai tahun akademik yang dipilih.
  useEffect(() => {
    if (!needsClass) {
      setClasses([]);
      return;
    }

    let active = true;

    async function loadClasses() {
      setLoadingOptions((current) => ({
        ...current,
        classes: true,
      }));

      try {
        const response = await classApi.getAll({
          academic_year_id: filters.academic_year_id || undefined,
          per_page: 100,
        });

        if (active) {
          setClasses(extractItems(response));
        }
      } catch {
        if (active) {
          setClasses([]);
          setOptionError("Gagal memuat daftar kelas.");
        }
      } finally {
        if (active) {
          setLoadingOptions((current) => ({
            ...current,
            classes: false,
          }));
        }
      }
    }

    loadClasses();

    return () => {
      active = false;
    };
  }, [needsClass, filters.academic_year_id]);

  // Ambil siswa sesuai kelas dan tahun akademik.
  useEffect(() => {
    if (!needsStudent) {
      setStudents([]);
      return;
    }

    let active = true;

    async function loadStudents() {
      setLoadingOptions((current) => ({
        ...current,
        students: true,
      }));

      try {
        const response = await studentApi.getAll({
          academic_year_id: filters.academic_year_id || undefined,
          class_id: filters.class_id || undefined,
          page_size: 100,
        });

        if (active) {
          setStudents(extractItems(response));
        }
      } catch {
        if (active) {
          setStudents([]);
          setOptionError("Gagal memuat daftar siswa.");
        }
      } finally {
        if (active) {
          setLoadingOptions((current) => ({
            ...current,
            students: false,
          }));
        }
      }
    }

    loadStudents();

    return () => {
      active = false;
    };
  }, [needsStudent, filters.academic_year_id, filters.class_id]);

  function handleChange(key, value) {
    setFilters((current) => {
      const next = {
        ...current,
        [key]: value,
      };

      if (key === "academic_year_id") {
        next.class_id = "";
        next.student_id = "";
      }

      if (key === "class_id") {
        next.student_id = "";
      }

      return next;
    });
  }

  function handleSubmit(event) {
    event.preventDefault();

    const params = {
      ...filters,
      format,
    };

    onGenerate(params);
  }

  function renderSelect(field, options, getLabel, placeholder) {
    return (
      <select
        id={field}
        value={filters[field] || ""}
        onChange={(event) => handleChange(field, event.target.value)}
        disabled={
          loadingOptions[
            field === "academic_year_id"
              ? "academicYears"
              : field === "class_id"
                ? "classes"
                : field === "teacher_id"
                  ? "teachers"
                  : field === "student_id"
                    ? "students"
                    : "subjects"
          ]
        }
      >
        <option value="">{placeholder}</option>

        {options.map((item) => (
          <option key={item.id} value={item.id}>
            {getLabel(item)}
          </option>
        ))}
      </select>
    );
  }

  function renderField(field) {
    const isDate = field === "date_from" || field === "date_to";

    if (field === "academic_year_id") {
      return renderSelect(
        field,
        academicYears,
        formatAcademicYear,
        loadingOptions.academicYears
          ? "Memuat tahun akademik..."
          : "Pilih tahun akademik",
      );
    }

    if (field === "class_id") {
      return renderSelect(
        field,
        classes,
        (item) => `${item.nama} - Tingkat ${item.tingkat}`,
        loadingOptions.classes ? "Memuat kelas..." : "Pilih kelas",
      );
    }

    if (field === "teacher_id") {
      return renderSelect(
        field,
        teachers,
        (item) => `${item.nama_lengkap} (${item.nip || "Tanpa NIP"})`,
        loadingOptions.teachers ? "Memuat guru..." : "Pilih guru",
      );
    }

    if (field === "student_id") {
      return renderSelect(
        field,
        students,
        (item) => `${item.nama_lengkap} (${item.nis || "Tanpa NIS"})`,
        loadingOptions.students ? "Memuat siswa..." : "Pilih siswa",
      );
    }

    if (field === "subject_id") {
      return renderSelect(
        field,
        subjects,
        (item) => `${item.kode} - ${item.nama}`,
        loadingOptions.subjects
          ? "Memuat mata pelajaran..."
          : "Pilih mata pelajaran",
      );
    }

    if (field === "semester") {
      return (
        <select
          id={field}
          value={filters[field] || ""}
          onChange={(event) => handleChange(field, event.target.value)}
        >
          <option value="">Semua Semester</option>
          <option value="Ganjil">Ganjil</option>
          <option value="Genap">Genap</option>
        </select>
      );
    }

    return (
      <input
        id={field}
        type={isDate ? "date" : "text"}
        value={filters[field] || ""}
        onChange={(event) => handleChange(field, event.target.value)}
        placeholder={field === "search" ? "Masukkan kata kunci..." : ""}
      />
    );
  }

  const optionsLoading = Object.values(loadingOptions).some(Boolean);

  return (
    <section className="reports-section">
      <h2>Filter Laporan</h2>

      {optionError && (
        <div className="report-message report-message-error" role="alert">
          {optionError}
        </div>
      )}

      <form onSubmit={handleSubmit}>
        <div className="reports-filter-grid">
          {fields.map((field) => (
            <div key={field}>
              <label htmlFor={field}>{FILTER_LABELS[field]}</label>

              {renderField(field)}
            </div>
          ))}
        </div>

        <div className="report-format-field">
          <label htmlFor="report-format">Format Laporan</label>

          <select
            id="report-format"
            value={format}
            onChange={(event) => setFormat(event.target.value)}
          >
            <option value="pdf">PDF</option>
            <option value="excel">Excel</option>
          </select>
        </div>

        <button type="submit" disabled={loading || optionsLoading}>
          {loading
            ? "Memproses laporan..."
            : optionsLoading
              ? "Memuat data filter..."
              : "Generate Laporan"}
        </button>
      </form>
    </section>
  );
}
