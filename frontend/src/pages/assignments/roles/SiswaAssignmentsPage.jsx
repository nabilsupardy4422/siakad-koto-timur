import { useCallback, useEffect, useMemo, useState } from "react";
import { assignmentApi } from "../../../services/assignmentApi";
import "../styles/SiswaAssignmentsPage.css";

const MAX_FILE_SIZE = 10 * 1024 * 1024;
const MAX_FILES = 10;

const ALLOWED_EXTENSIONS = [
  ".jpg",
  ".jpeg",
  ".png",
  ".pdf",
  ".doc",
  ".docx",
  ".xls",
  ".xlsx",
  ".ppt",
  ".pptx",
];

function unwrap(response) {
  return response?.data?.data ?? response?.data ?? [];
}

function formatDate(value) {
  if (!value) return "Tidak ditentukan";

  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return "Tanggal tidak valid";
  }

  return new Intl.DateTimeFormat("id-ID", {
    dateStyle: "medium",
    timeStyle: "short",
    timeZone: "Asia/Jakarta",
  }).format(date);
}

function formatFileSize(size) {
  if (size == null) return "";

  const bytes = Number(size);

  if (!Number.isFinite(bytes) || bytes < 0) return "";
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) {
    return `${(bytes / 1024).toFixed(1)} KB`;
  }

  return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
}

function getErrorMessage(error) {
  const response = error?.response?.data;
  const errors = response?.errors;

  if (errors && typeof errors === "object") {
    const firstError = Object.values(errors).flat()[0];

    if (firstError) return String(firstError);
  }

  return (
    response?.message ||
    error?.message ||
    "Terjadi kesalahan. Silakan coba kembali."
  );
}

function getSubjectName(assignment) {
  return (
    assignment?.jadwal_pelajaran?.mapel?.nama || "Mata Pelajaran Belum Tersedia"
  );
}

function isDeadlinePassed(assignment) {
  if (!assignment?.deadline) return false;

  const deadline = new Date(assignment.deadline);

  return !Number.isNaN(deadline.getTime()) && deadline.getTime() < Date.now();
}

function getStatus(assignment, submission) {
  if (submission?._loadError) {
    return {
      label: "Status tidak tersedia",
      type: "unknown",
    };
  }

  if (submission) {
    if (String(submission.status || "").toLowerCase() === "terlambat") {
      return {
        label: "Terlambat dikumpulkan",
        type: "late",
      };
    }

    return {
      label: "Sudah dikumpulkan",
      type: "submitted",
    };
  }

  if (isDeadlinePassed(assignment)) {
    return {
      label: "Tenggat terlewat",
      type: "late",
    };
  }

  return {
    label: "Belum dikumpulkan",
    type: "pending",
  };
}

function validateFile(file) {
  if (!file) return "";

  if (file.size > MAX_FILE_SIZE) {
    return "Ukuran file maksimal 10 MB.";
  }

  const name = file.name.toLowerCase();

  if (!ALLOWED_EXTENSIONS.some((extension) => name.endsWith(extension))) {
    return (
      "Format file tidak didukung. Gunakan JPG, JPEG, PNG, PDF, DOC, " +
      "DOCX, XLS, XLSX, PPT, atau PPTX."
    );
  }

  return "";
}

function getSubmissionFiles(submission) {
  if (Array.isArray(submission?.files) && submission.files.length > 0) {
    return submission.files;
  }

  if (submission?.file?.name) {
    return [{ ...submission.file, id: null }];
  }

  return [];
}

function getAssignmentFiles(assignment) {
  // Format utama dari AssignmentResource Laravel.
  const sourceFiles = [
    assignment?.files,
    assignment?.lampiran,
    assignment?.attachments,
  ].find((items) => Array.isArray(items) && items.length > 0);

  if (sourceFiles) {
    return sourceFiles
      .filter(Boolean)
      .map((file) => ({
        ...file,
        id: file.id ?? file.attachment_id ?? null,
        name:
          file.name ||
          file.file_name ||
          file.original_name ||
          "File lampiran",
        mime_type: file.mime_type || file.file_mime_type || "",
        size: file.size ?? file.file_size ?? null,
      }));
  }

  // Kompatibilitas dengan data tugas versi lama yang hanya memiliki "file".
  const legacyFile = assignment?.file;

  if (legacyFile && (legacyFile.name || legacyFile.file_name)) {
    return [
      {
        ...legacyFile,
        id: legacyFile.id ?? null,
        name: legacyFile.name || legacyFile.file_name || "File lampiran",
        mime_type: legacyFile.mime_type || legacyFile.file_mime_type || "",
        size: legacyFile.size ?? legacyFile.file_size ?? null,
      },
    ];
  }

  // Kompatibilitas jika API mengirim metadata langsung pada objek tugas.
  if (assignment?.file_name) {
    return [
      {
        id: null,
        name: assignment.file_name,
        mime_type: assignment.file_mime_type || "",
        size: assignment.file_size ?? null,
      },
    ];
  }

  return [];
}

function getAttachmentKind(file) {
  const mime = String(file?.mime_type || "").toLowerCase();
  const name = String(file?.name || "").toLowerCase();

  if (mime === "application/pdf" || name.endsWith(".pdf")) {
    return "PDF";
  }

  if (mime.startsWith("image/")) return "Gambar";
  if (/\.(doc|docx)$/.test(name)) return "Dokumen Word";
  if (/\.(xls|xlsx)$/.test(name)) return "Spreadsheet";
  if (/\.(ppt|pptx)$/.test(name)) return "Presentasi";

  return "Lampiran";
}

export default function SiswaAssignmentsPage() {
  const [assignments, setAssignments] = useState([]);
  const [submissions, setSubmissions] = useState({});
  const [loading, setLoading] = useState(true);
  const [pageError, setPageError] = useState("");
  const [notice, setNotice] = useState("");

  const [search, setSearch] = useState("");
  const [filter, setFilter] = useState("semua");
  const [selectedSubject, setSelectedSubject] = useState("semua");

  const [collapsedSubjects, setCollapsedSubjects] = useState({});
  const [expandedDetails, setExpandedDetails] = useState({});

  const [activeAssignment, setActiveAssignment] = useState(null);
  const [answer, setAnswer] = useState("");
  const [selectedFiles, setSelectedFiles] = useState([]);
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState("");

  const loadAssignments = useCallback(async () => {
    setLoading(true);
    setPageError("");

    try {
      const response = await assignmentApi.getAll({
        per_page: 100,
      });

      const result = unwrap(response);
      const rows = Array.isArray(result) ? result : [];

      setAssignments(rows);

      const entries = await Promise.all(
        rows.map(async (assignment) => {
          try {
            const submissionResponse = await assignmentApi.getSubmissions(
              assignment.id
            );

            const data = unwrap(submissionResponse);
            const items = Array.isArray(data) ? data : [];

            return [assignment.id, items[0] || null];
          } catch (error) {
            return [
              assignment.id,
              {
                _loadError: true,
                _error: getErrorMessage(error),
              },
            ];
          }
        })
      );

      setSubmissions(Object.fromEntries(entries));
    } catch (error) {
      setPageError(getErrorMessage(error));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadAssignments();
  }, [loadAssignments]);

  const resetFilters = useCallback(() => {
    setSearch("");
    setSelectedSubject("semua");
    setFilter("semua");
  }, []);

  function toggleSubject(subject) {
    setCollapsedSubjects((current) => ({
      ...current,
      [subject]: !current[subject],
    }));
  }

  function toggleAssignmentDetails(assignmentId) {
    setExpandedDetails((current) => ({
      ...current,
      [assignmentId]: !current[assignmentId],
    }));
  }

  const subjectOptions = useMemo(() => {
    const subjects = assignments.map(getSubjectName);

    return [...new Set(subjects)].sort((a, b) => a.localeCompare(b, "id"));
  }, [assignments]);

  const visibleAssignments = useMemo(() => {
    const keyword = search.trim().toLowerCase();

    return assignments.filter((assignment) => {
      const title = String(assignment.judul || "").toLowerCase();
      const description = String(assignment.deskripsi || "").toLowerCase();
      const subjectName = getSubjectName(assignment);
      const subject = subjectName.toLowerCase();

      if (
        keyword &&
        !title.includes(keyword) &&
        !description.includes(keyword) &&
        !subject.includes(keyword)
      ) {
        return false;
      }

      if (selectedSubject !== "semua" && subjectName !== selectedSubject) {
        return false;
      }

      const submission = submissions[assignment.id];

      if (submission?._loadError) {
        return filter === "semua";
      }

      const status = getStatus(assignment, submission);

      if (filter === "belum") return status.type === "pending";
      if (filter === "dikumpulkan") {
        return status.type === "submitted";
      }
      if (filter === "terlambat") return status.type === "late";

      return true;
    });
  }, [assignments, submissions, search, filter, selectedSubject]);

  const assignmentSummary = useMemo(() => {
    const summary = {
      total: assignments.length,
      pending: 0,
      submitted: 0,
      late: 0,
    };

    assignments.forEach((assignment) => {
      const submission = submissions[assignment.id];

      if (submission?._loadError) return;

      const status = getStatus(assignment, submission);

      if (status.type === "pending") summary.pending += 1;
      if (status.type === "submitted") summary.submitted += 1;
      if (status.type === "late") summary.late += 1;
    });

    return summary;
  }, [assignments, submissions]);

  const groupedAssignments = useMemo(() => {
    const groups = new Map();

    visibleAssignments.forEach((assignment) => {
      const subject = getSubjectName(assignment);

      if (!groups.has(subject)) {
        groups.set(subject, []);
      }

      groups.get(subject).push(assignment);
    });

    return Array.from(groups.entries())
      .sort(([a], [b]) => a.localeCompare(b, "id"))
      .map(([subject, items]) => ({
        subject,
        assignments: items,
      }));
  }, [visibleAssignments]);

  function openSubmissionForm(assignment) {
    const submission = submissions[assignment.id];

    if (submission?._loadError) {
      setPageError(
        "Status pengumpulan belum berhasil dimuat. " +
          "Muat ulang halaman terlebih dahulu."
      );
      return;
    }

    if (submission && isDeadlinePassed(assignment)) {
      setFormError(
        "Batas waktu revisi telah berakhir. Jawaban tidak dapat diubah lagi."
      );
      return;
    }

    setActiveAssignment(assignment);
    setAnswer(submission?.jawaban || "");
    setSelectedFiles([]);
    setFormError("");
    setNotice("");
  }

  function closeSubmissionForm() {
    if (saving) return;

    setActiveAssignment(null);
    setAnswer("");
    setSelectedFiles([]);
    setFormError("");
  }

  function handleFileSelection(event) {
    const files = Array.from(event.target.files || []);

    setFormError("");

    if (files.length > MAX_FILES) {
      setSelectedFiles([]);
      setFormError(`Maksimal ${MAX_FILES} file baru dapat dipilih.`);
      event.target.value = "";
      return;
    }

    for (const file of files) {
      const error = validateFile(file);

      if (error) {
        setSelectedFiles([]);
        setFormError(`${file.name}: ${error}`);
        event.target.value = "";
        return;
      }
    }

    setSelectedFiles(files);
  }

  function removeSelectedFile(index) {
    setSelectedFiles((current) =>
      current.filter((_, fileIndex) => fileIndex !== index)
    );
  }

  async function handleViewSubmissionAttachment(file) {
    if (!file?.id) {
      setPageError(
        "ID lampiran tidak tersedia. Muat ulang data submission terlebih dahulu."
      );
      return;
    }

    const openedWindow = window.open("about:blank", "_blank");

    if (!openedWindow) {
      setPageError(
        "Browser memblokir tab baru. Izinkan pop-up, lalu coba kembali."
      );
      return;
    }

    let objectUrl = null;

    try {
      const result = await assignmentApi.viewSubmissionAttachment(file.id);

      objectUrl = URL.createObjectURL(result.blob);
      openedWindow.location.href = objectUrl;

      window.setTimeout(() => {
        if (objectUrl) URL.revokeObjectURL(objectUrl);
      }, 60000);
    } catch (error) {
      openedWindow.close();

      if (objectUrl) URL.revokeObjectURL(objectUrl);

      setPageError(getErrorMessage(error));
    }
  }

  async function handleDownloadSubmissionAttachment(file) {
    if (!file?.id) {
      setPageError(
        "ID lampiran tidak tersedia. Muat ulang data submission terlebih dahulu."
      );
      return;
    }

    try {
      await assignmentApi.downloadSubmissionAttachment(
        file.id,
        file.name || "lampiran-jawaban"
      );
    } catch (error) {
      setPageError(getErrorMessage(error));
    }
  }

  async function handleDownloadAssignment(assignment, file = null) {
    try {
      // Lampiran baru menggunakan ID dari tabel assignment_lampiran.
      if (file?.id && typeof assignmentApi.downloadAssignmentAttachment === "function") {
        await assignmentApi.downloadAssignmentAttachment(
          file.id,
          file.name || "lampiran-tugas"
        );
        return;
      }

      // Fallback untuk lampiran legacy yang masih berada di kolom assignment.file.
      if (!file?.id) {
        await assignmentApi.download(
          assignment.id,
          file?.name || assignment.file?.name || "lampiran-tugas"
        );
        return;
      }

      throw new Error(
        "Fungsi unduh lampiran tugas belum tersedia di assignmentApi.js."
      );
    } catch (error) {
      setPageError(getErrorMessage(error));
    }
  }

  async function handleSubmit(event) {
    event.preventDefault();

    if (!activeAssignment) return;

    const existing = submissions[activeAssignment.id];
    const isRevision = Boolean(existing && !existing._loadError);
    const trimmedAnswer = answer.trim();
    const existingFiles = getSubmissionFiles(existing);

    if (isRevision && isDeadlinePassed(activeAssignment)) {
      setFormError(
        "Batas waktu revisi telah berakhir. Jawaban tidak dapat diubah lagi."
      );
      return;
    }

    if (
      !trimmedAnswer &&
      selectedFiles.length === 0 &&
      existingFiles.length === 0
    ) {
      setFormError("Isi jawaban atau pilih minimal satu file.");
      return;
    }

    if (selectedFiles.length > MAX_FILES) {
      setFormError(`Maksimal ${MAX_FILES} file baru dapat dikirim.`);
      return;
    }

    for (const file of selectedFiles) {
      const error = validateFile(file);

      if (error) {
        setFormError(`${file.name}: ${error}`);
        return;
      }
    }

    const formData = new FormData();

    if (trimmedAnswer) {
      formData.append("jawaban", trimmedAnswer);
    } else if (isRevision) {
      formData.append("jawaban", "");
    }

    selectedFiles.forEach((file) => {
      formData.append("files[]", file);
    });

    setSaving(true);
    setFormError("");
    setNotice("");
    setPageError("");

    try {
      if (isRevision) {
        await assignmentApi.updateSubmissionWithFile(existing.id, formData);
      } else {
        await assignmentApi.submitWithFile(activeAssignment.id, formData);
      }

      setNotice(
        isRevision
          ? "Revisi jawaban dan lampiran berhasil dikirim."
          : "Tugas berhasil dikumpulkan."
      );

      setActiveAssignment(null);
      setAnswer("");
      setSelectedFiles([]);

      await loadAssignments();
    } catch (error) {
      setFormError(getErrorMessage(error));
    } finally {
      setSaving(false);
    }
  }

  function renderSubmissionFiles(files, keyPrefix) {
    if (files.length === 0) {
      return <p>Belum ada lampiran jawaban.</p>;
    }

    return (
      <ul className="siswa-assignment-files">
        {files.map((file, index) => (
          <li
            className="siswa-assignment-files__item"
            key={file.id ?? `${keyPrefix}-${file.name}-${index}`}
          >
            <div className="siswa-assignment-files__info">
              <strong>{file.name || "File lampiran"}</strong>
              <small>
                {getAttachmentKind(file)}
                {file.size != null ? ` · ${formatFileSize(file.size)}` : ""}
              </small>
            </div>

            {file.id ? (
              <div className="siswa-assignment-files__actions">
                <button
                  type="button"
                  onClick={() => handleViewSubmissionAttachment(file)}
                >
                  Lihat
                </button>
                <button
                  type="button"
                  onClick={() => handleDownloadSubmissionAttachment(file)}
                >
                  Unduh
                </button>
              </div>
            ) : (
              <small>ID lampiran tidak tersedia.</small>
            )}
          </li>
        ))}
      </ul>
    );
  }

  return (
    <main className="siswa-assignments">
      <header className="siswa-assignments__header">
        <div>
          <p className="siswa-assignments__eyebrow">AKADEMIK SISWA</p>
          <h1>Tugas Saya</h1>
          <p className="siswa-assignments__subtitle">
            Pantau tugas setiap mata pelajaran, tenggat waktu, dan status
            pengumpulan.
          </p>
        </div>

        <button
          className="siswa-assignments__refresh"
          type="button"
          onClick={loadAssignments}
          disabled={loading || saving}
        >
          {loading ? "Memuat..." : "Muat ulang"}
        </button>
      </header>

      {notice && (
        <div className="siswa-assignments__alert siswa-assignments__alert--success">
          {notice}
        </div>
      )}

      {pageError && (
        <div className="siswa-assignments__alert siswa-assignments__alert--error">
          <span>{pageError}</span>
          <button type="button" onClick={loadAssignments}>
            Coba lagi
          </button>
        </div>
      )}

      <section className="siswa-assignment-summary">
        <article className="siswa-assignment-summary__card">
          <span>Total Tugas</span>
          <strong>{assignmentSummary.total}</strong>
          <small>Semua tugas yang tersedia</small>
        </article>

        <article className="siswa-assignment-summary__card siswa-assignment-summary__card--pending">
          <span>Belum Dikumpulkan</span>
          <strong>{assignmentSummary.pending}</strong>
          <small>Perlu diselesaikan</small>
        </article>

        <article className="siswa-assignment-summary__card siswa-assignment-summary__card--submitted">
          <span>Sudah Dikumpulkan</span>
          <strong>{assignmentSummary.submitted}</strong>
          <small>Jawaban telah dikirim</small>
        </article>

        <article className="siswa-assignment-summary__card siswa-assignment-summary__card--late">
          <span>Terlambat</span>
          <strong>{assignmentSummary.late}</strong>
          <small>Tenggat terlewati atau terlambat dikirim</small>
        </article>
      </section>

      <section className="siswa-assignments__toolbar">
        <label className="siswa-assignments__search">
          <span>Cari tugas atau mata pelajaran</span>
          <input
            type="search"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="Contoh: Bahasa Indonesia..."
          />
        </label>

        <label className="siswa-assignments__subject-filter">
          <span>Mata pelajaran</span>
          <select
            value={selectedSubject}
            onChange={(event) => setSelectedSubject(event.target.value)}
          >
            <option value="semua">Semua mata pelajaran</option>
            {subjectOptions.map((subject) => (
              <option key={subject} value={subject}>
                {subject}
              </option>
            ))}
          </select>
        </label>

        <label className="siswa-assignments__filter">
          <span>Filter status</span>
          <select
            value={filter}
            onChange={(event) => setFilter(event.target.value)}
          >
            <option value="semua">Semua tugas</option>
            <option value="belum">Belum dikumpulkan</option>
            <option value="dikumpulkan">Sudah dikumpulkan</option>
            <option value="terlambat">Terlambat</option>
          </select>
        </label>

        <div className="siswa-assignments__toolbar-actions">
          <button
            type="button"
            className="siswa-assignments__reset"
            onClick={resetFilters}
            disabled={
              !search.trim() &&
              selectedSubject === "semua" &&
              filter === "semua"
            }
          >
            Reset Filter
          </button>
        </div>
      </section>

      {loading ? (
        <section className="siswa-assignments__empty">
          <h2>Memuat tugas...</h2>
          <p>Daftar tugas dan status pengumpulan sedang diperiksa.</p>
        </section>
      ) : groupedAssignments.length === 0 ? (
        <section className="siswa-assignments__empty">
          <h2>Tidak ada tugas ditemukan</h2>
          <p>Coba ubah kata pencarian atau filter yang dipilih.</p>
          <button
            type="button"
            className="siswa-assignments__reset"
            onClick={resetFilters}
          >
            Reset Filter
          </button>
        </section>
      ) : (
        <div className="siswa-assignment-groups">
          {groupedAssignments.map((group) => {
            const isCollapsed = Boolean(collapsedSubjects[group.subject]);

            return (
              <section className="siswa-assignment-group" key={group.subject}>
                <button
                  type="button"
                  className="siswa-assignment-group__header siswa-assignment-group__toggle"
                  onClick={() => toggleSubject(group.subject)}
                  aria-expanded={!isCollapsed}
                >
                  <div
                    className="siswa-assignment-group__icon"
                    aria-hidden="true"
                  >
                    {group.subject === "Mata Pelajaran Belum Tersedia"
                      ? "?"
                      : group.subject.charAt(0).toUpperCase()}
                  </div>

                  <div className="siswa-assignment-group__title">
                    <p className="siswa-assignment-group__eyebrow">
                      MATA PELAJARAN
                    </p>
                    <h2>{group.subject}</h2>
                  </div>

                  <span className="siswa-assignment-group__count">
                    {group.assignments.length} tugas
                  </span>

                  <span
                    className="siswa-assignment-group__chevron"
                    aria-hidden="true"
                  >
                    {isCollapsed ? "+" : "−"}
                  </span>
                </button>

                {!isCollapsed && (
                  <div className="siswa-assignments__list">
                    {group.assignments.map((assignment) => {
                      const submission = submissions[assignment.id];
                      const status = getStatus(assignment, submission);
                      const submissionFiles = getSubmissionFiles(submission);
                      const assignmentFiles = getAssignmentFiles(assignment);

                      const detailsExpanded = Boolean(
                        expandedDetails[assignment.id]
                      );

                      const revisionLocked =
                        Boolean(submission && !submission._loadError) &&
                        isDeadlinePassed(assignment);

                      return (
                        <article
                          className="siswa-assignment-card"
                          key={assignment.id}
                        >
                          <div className="siswa-assignment-card__top">
                            <div>
                              <h3>{assignment.judul || "Tugas tanpa judul"}</h3>
                              <p className="siswa-assignment-card__deadline">
                                Tenggat: {formatDate(assignment.deadline)}
                              </p>
                            </div>

                            <span
                              className={`siswa-assignment-card__status siswa-assignment-card__status--${status.type}`}
                            >
                              {status.label}
                            </span>
                          </div>

                          <button
                            type="button"
                            className="siswa-assignment-card__details-toggle"
                            onClick={() =>
                              toggleAssignmentDetails(assignment.id)
                            }
                            aria-expanded={detailsExpanded}
                          >
                            <span>
                              {detailsExpanded
                                ? "Sembunyikan detail tugas"
                                : "Lihat detail tugas"}
                            </span>
                            <span aria-hidden="true">
                              {detailsExpanded ? "−" : "+"}
                            </span>
                          </button>

                          {detailsExpanded && (
                            <div className="siswa-assignment-card__details">
                              <div className="siswa-assignment-card__description">
                                {assignment.deskripsi ||
                                  "Tidak ada deskripsi tugas."}
                              </div>

                              <section className="siswa-assignment-card__attachment">
                                <strong>Lampiran tugas dari guru</strong>

                                {assignmentFiles.length > 0 ? (
                                  <ul className="siswa-assignment-files">
                                    {assignmentFiles.map((file, index) => (
                                      <li
                                        className="siswa-assignment-files__item"
                                        key={
                                          file.id ??
                                          `assignment-${assignment.id}-${file.name}-${index}`
                                        }
                                      >
                                        <div className="siswa-assignment-files__info">
                                          <strong>{file.name || "File lampiran"}</strong>
                                          <small>
                                            {getAttachmentKind(file)}
                                            {file.size != null
                                              ? ` · ${formatFileSize(file.size)}`
                                              : ""}
                                          </small>
                                        </div>

                                        <div className="siswa-assignment-files__actions">
                                          <button
                                            type="button"
                                            onClick={() =>
                                              handleDownloadAssignment(assignment, file)
                                            }
                                          >
                                            Unduh
                                          </button>
                                        </div>
                                      </li>
                                    ))}
                                  </ul>
                                ) : (
                                  <p className="siswa-assignment-card__no-attachment">
                                    Guru belum menambahkan lampiran untuk tugas ini.
                                  </p>
                                )}
                              </section>

                              {submission?._loadError && (
                                <p className="siswa-assignment-card__warning">
                                  Status pengumpulan tidak berhasil dimuat. Muat
                                  ulang halaman sebelum mencoba mengirim
                                  kembali.
                                </p>
                              )}

                              {submission && !submission._loadError && (
                                <div className="siswa-assignment-card__submission">
                                  <strong>Jawaban terakhir</strong>
                                  <p>
                                    {submission.jawaban ||
                                      "Tidak ada jawaban teks."}
                                  </p>

                                  <strong>
                                    Lampiran jawaban yang sudah dikumpulkan
                                  </strong>

                                  {renderSubmissionFiles(
                                    submissionFiles,
                                    assignment.id
                                  )}

                                  <span>
                                    Dikumpulkan:{" "}
                                    {formatDate(submission.submitted_at)}
                                  </span>

                                  {submission.catatan && (
                                    <p className="siswa-assignment-card__feedback">
                                      Catatan Guru: {submission.catatan}
                                    </p>
                                  )}
                                </div>
                              )}
                            </div>
                          )}

                          <div className="siswa-assignment-card__actions">
                            <button
                              type="button"
                              className="siswa-assignment-card__primary"
                              disabled={
                                Boolean(submission?._loadError) ||
                                revisionLocked
                              }
                              onClick={() => openSubmissionForm(assignment)}
                            >
                              {revisionLocked
                                ? "Revisi ditutup"
                                : submission && !submission._loadError
                                ? "Revisi jawaban"
                                : "Kumpulkan tugas"}
                            </button>
                          </div>
                        </article>
                      );
                    })}
                  </div>
                )}
              </section>
            );
          })}
        </div>
      )}

      {activeAssignment && (
        <div
          className="siswa-assignment-modal"
          role="presentation"
          onMouseDown={(event) => {
            if (event.target === event.currentTarget) {
              closeSubmissionForm();
            }
          }}
        >
          <section
            className="siswa-assignment-modal__panel"
            role="dialog"
            aria-modal="true"
            aria-labelledby="siswa-submission-title"
          >
            <div className="siswa-assignment-modal__header">
              <div>
                <p>FORM PENGUMPULAN</p>
                <h2 id="siswa-submission-title">
                  {submissions[activeAssignment.id] &&
                  !submissions[activeAssignment.id]._loadError
                    ? "Revisi Jawaban"
                    : "Kumpulkan Tugas"}
                </h2>
              </div>

              <button
                type="button"
                onClick={closeSubmissionForm}
                disabled={saving}
                aria-label="Tutup formulir"
              >
                ×
              </button>
            </div>

            <p className="siswa-assignment-modal__task">
              {activeAssignment.judul}
            </p>

            <form onSubmit={handleSubmit}>
              <label className="siswa-assignment-modal__field">
                <span>Jawaban teks</span>
                <textarea
                  rows={6}
                  value={answer}
                  onChange={(event) => setAnswer(event.target.value)}
                  placeholder="Tuliskan jawaban tugas di sini..."
                  disabled={saving}
                />
              </label>

              {getSubmissionFiles(submissions[activeAssignment.id]).length >
                0 && (
                <div className="siswa-assignment-modal__existing-file">
                  <strong>Lampiran yang sudah dikumpulkan</strong>

                  {renderSubmissionFiles(
                    getSubmissionFiles(submissions[activeAssignment.id]),
                    `modal-${activeAssignment.id}`
                  )}

                  <small>
                    Lampiran lama tetap tersimpan saat kamu menambahkan file
                    baru.
                  </small>
                </div>
              )}

              <label className="siswa-assignment-modal__field">
                <span>File jawaban (opsional, maksimal 10 file baru)</span>
                <input
                  type="file"
                  multiple
                  accept={ALLOWED_EXTENSIONS.join(",")}
                  onChange={handleFileSelection}
                  disabled={saving}
                />
                <small>
                  Maksimal 10 MB per file. Format: JPG, JPEG, PNG, PDF, DOC,
                  DOCX, XLS, XLSX, PPT, dan PPTX.
                </small>
              </label>

              {selectedFiles.length > 0 && (
                <div className="siswa-assignment-modal__selected-file">
                  <strong>File baru yang akan dikirim</strong>
                  <ul>
                    {selectedFiles.map((file, index) => (
                      <li key={`${file.name}-${file.lastModified}-${index}`}>
                        <span>
                          {file.name} ({formatFileSize(file.size)})
                        </span>
                        <button
                          type="button"
                          onClick={() => removeSelectedFile(index)}
                          disabled={saving}
                        >
                          Hapus pilihan
                        </button>
                      </li>
                    ))}
                  </ul>
                </div>
              )}

              {formError && (
                <div className="siswa-assignments__alert siswa-assignments__alert--error">
                  {formError}
                </div>
              )}

              {isDeadlinePassed(activeAssignment) &&
                submissions[activeAssignment.id] &&
                !submissions[activeAssignment.id]._loadError && (
                  <p className="siswa-assignment-card__warning">
                    Tenggat sudah berakhir. Revisi jawaban tidak dapat
                    dilakukan.
                  </p>
                )}

              <div className="siswa-assignment-modal__actions">
                <button
                  type="button"
                  onClick={closeSubmissionForm}
                  disabled={saving}
                >
                  Batal
                </button>
                <button
                  type="submit"
                  disabled={
                    saving ||
                    Boolean(
                      submissions[activeAssignment.id] &&
                        !submissions[activeAssignment.id]._loadError &&
                        isDeadlinePassed(activeAssignment)
                    )
                  }
                >
                  {saving ? "Mengirim..." : "Kirim jawaban"}
                </button>
              </div>
            </form>
          </section>
        </div>
      )}
    </main>
  );
}
