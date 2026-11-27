import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { assignmentApi } from "../../../services/assignmentApi";
import { parseWorkspace, assignmentState, summarizeAssignments } from "./studentAssignmentsView";
import { appendFilesToSelection } from "./studentAssignmentFiles";
import "../styles/SiswaAssignmentsPage.css";
import {apiClient} from '../../../services/apiClient';
import {subjectKey,assignmentPageCheck,submissionReadback} from '../../../utils/studentWorkspace.js';
import TeacherListPager,{useTeacherPage} from '../../../components/ui/TeacherListPager.jsx';
import PreparedStudentFile,{usePreparedStudentFile} from '../../../components/ui/PreparedStudentFile.jsx';
import {useTeacherDraftWarning} from '../../../utils/useTeacherDraftWarning.js';

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
  if (submission?._loadError) return {label: "Status tidak tersedia", type: "unknown"};
  return assignmentState(assignment, submission);
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
  const fileInputRef = useRef(null);
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState("");

  const requestGeneration = useRef(0);
  const writeLock=useRef(false);
  const [uncertain,setUncertain]=useState(false);
  const [checking,setChecking]=useState(false);
  const dirty=Boolean(activeAssignment)&&(answer!==(submissions[activeAssignment.id]?.jawaban||'')||selectedFiles.length>0);
  useTeacherDraftWarning(dirty||saving||uncertain);
  const fileState=usePreparedStudentFile(`${activeAssignment?.id??''}:${search}:${filter}:${selectedSubject}`);
  const [context, setContext] = useState(null);
  const loadAssignments = useCallback(async () => {
    const generation = ++requestGeneration.current;
    setLoading(true);
    setPageError("");
    try {
      let page = 1;
      let lastPage = 1;
      let expectedTotal = null;
      let snapshot=null;
      let expectedYear = null;
      let expectedClass = null;
      let resolvedContext = null;
      const allAssignments = [];
      const allSubmissions = {};
      do {
        // Workspace returns assignments and only this student's submissions together.
        const parsed = parseWorkspace(await assignmentApi.getStudentWorkspace({page, per_page: 100}));
        if (generation !== requestGeneration.current) return;
        const {data, meta} = parsed;
        snapshot=assignmentPageCheck(data,meta,page,snapshot);
        if(page===1)resolvedContext={yearId:data.academic_year_id,className:data.class?.name??null};
        if (expectedTotal === null) {
          expectedTotal = meta.total;
          expectedYear = data.academic_year_id;
          expectedClass = data.class?.id ?? null;
        } else if (meta.total !== expectedTotal || data.academic_year_id !== expectedYear ||
                   (data.class?.id ?? null) !== expectedClass || meta.current_page !== page) {
          throw new Error("Konteks tugas berubah. Perbarui halaman untuk data terbaru.");
        }
        allAssignments.push(...data.assignments);
        Object.assign(allSubmissions, data.submissions);
        lastPage = meta.last_page;
        page++;
        if (page > 501) throw new Error("Terlalu banyak halaman tugas. Hubungi administrator.");
        if (page > lastPage && allAssignments.length !== expectedTotal) {
          throw new Error("Jumlah tugas tidak sesuai metadata dari server.");
        }

      } while (page <= lastPage);
      if (generation !== requestGeneration.current) return;
      if (new Set(allAssignments.map(a => a.id)).size !== allAssignments.length) {
        throw new Error("Tugas duplikat ditemukan pada halaman API berbeda.");
      }
      setAssignments(allAssignments);
      setSubmissions(allSubmissions);
      setContext(resolvedContext);
    } catch (error) {
      if (generation === requestGeneration.current) {
        setPageError(getErrorMessage(error));
        setAssignments([]);
        setSubmissions({});
        setContext(null);
      }
    } finally {
      if (generation === requestGeneration.current) setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadAssignments();
    return () => { requestGeneration.current += 1; };
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
    return [...new Map(assignments.map(a=>[subjectKey(a),{key:subjectKey(a),name:getSubjectName(a)}])).values()].sort((a,b)=>a.name.localeCompare(b.name,"id"));
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

      if (selectedSubject !== "semua" && subjectKey(assignment) !== selectedSubject) {
        return false;
      }

      const submission = submissions[assignment.id];

      if (submission?._loadError) {
        return filter === "semua";
      }

      const status = getStatus(assignment, submission);

      if (filter === "belum") return status.type === "pending" || status.type === "overdue";
      if (filter === "dikumpulkan") {
        return status.type === "submitted" || status.type === "late-submitted";
      }
      if (filter === "terlambat") return status.type === "late-submitted";
      if (filter === "overdue") return status.type === "overdue";

      return true;
    });
  }, [assignments, submissions, search, filter, selectedSubject]);

  const assignmentSummary = useMemo(
    () => summarizeAssignments(assignments, submissions),
    [assignments, submissions]
  );

  const paging=useTeacherPage(visibleAssignments,`${search}:${filter}:${selectedSubject}`,12);
  const overdue=assignments.filter(a=>getStatus(a,submissions[a.id]).type==='overdue').length;
  const lateSubmitted=assignments.filter(a=>getStatus(a,submissions[a.id]).type==='late-submitted').length;
  const groupedAssignments = useMemo(() => {
    const groups = new Map();

    paging.rows.forEach((assignment) => {
      const subject = subjectKey(assignment);

      if (!groups.has(subject)) {
        groups.set(subject, []);
      }

      groups.get(subject).push(assignment);
    });

    return Array.from(groups.entries())
      .sort(([a], [b]) => a.localeCompare(b, "id"))
      .map(([subject, items]) => ({
        subject:getSubjectName(items[0]), key:subject,
        assignments: items,
      }));
  }, [paging.rows]);

  function openSubmissionForm(assignment) {
    if(loading||pageError)return;
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

    fileState.clear();setUncertain(false);setActiveAssignment(assignment);
    setAnswer(submission?.jawaban || "");
    setSelectedFiles([]);
    setFormError("");
    setNotice("");
  }

  function closeSubmissionForm() {
    if (saving||checking) return;
    if ((dirty||uncertain)&&!window.confirm("Jawaban belum tersimpan atau belum terverifikasi. Tutup dan buang draft?")) return;
    fileState.clear();if(uncertain)loadAssignments();setUncertain(false);setActiveAssignment(null);
    setAnswer("");
    setSelectedFiles([]);
    setFormError("");
  }

  function handleFileSelection(event) {
    const incoming = Array.from(event.target.files || []);
    // Reset native input agar file yang sama dapat dipilih lagi setelah dihapus.
    event.target.value = "";
    if (!incoming.length) return;

    setFormError("");
    const merged = appendFilesToSelection(selectedFiles, incoming, {
      maxFiles: MAX_FILES,
      validate: validateFile,
    });
    if (merged.error) {
      // Jangan hapus file yang sudah dipilih apabila tambahan gagal validasi.
      setFormError(merged.error);
      return;
    }
    setSelectedFiles(merged.files);
  }

  function removeSelectedFile(index) {
    setSelectedFiles((current) =>
      current.filter((_, fileIndex) => fileIndex !== index)
    );
  }

  async function handleViewSubmissionAttachment(file) {
    if(!file?.id)return setPageError("ID lampiran tidak tersedia.");
    await fileState.prepare(()=>apiClient.download(`/submission-attachments/${file.id}/download`),file.name||'lampiran-jawaban');
  }
  async function handleDownloadSubmissionAttachment(file) {return handleViewSubmissionAttachment(file);}
  async function handleDownloadAssignment(assignment,file=null) {
    const path=file?.id?`/assignment-attachments/${file.id}/download`:`/assignments/${assignment.id}/download`;
    await fileState.prepare(()=>apiClient.download(path),file?.name||assignment.file?.name||'lampiran-tugas');
  }
  async function checkSubmission(){
    if(!activeAssignment||checking)return;
    setChecking(true);
    try{
      const latest=submissionReadback(await assignmentApi.getSubmissions(activeAssignment.id),activeAssignment.id);
      if(latest){setSubmissions(old=>({...old,[activeAssignment.id]:latest}));setFormError('Server memiliki jawaban tercatat. Bandingkan jawaban dan lampiran terakhir. Tutup formulir dan muat ulang sebelum revisi; draft tidak dikirim ulang otomatis.');}
      else if(!submissions[activeAssignment.id]) {setUncertain(false);setFormError('Server belum mencatat pengumpulan. Periksa kembali sebelum mencoba kirim.');} else {setFormError('Catatan revisi sebelumnya tidak ditemukan. Hubungi sekolah sebelum mengirim ulang.');}
    }catch(e){setFormError(e.message||'Verifikasi belum berhasil. Jangan kirim ulang.');}
    finally{setChecking(false)}
  }

  async function handleSubmit(event) {
    event.preventDefault();

    if (!activeAssignment || writeLock.current || uncertain || checking) return;

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

    writeLock.current=true;
    setSaving(true);
    setFormError("");
    setNotice("");
    setPageError("");

    try {
      const response = isRevision ? await assignmentApi.updateSubmissionWithFile(existing.id, formData) : await assignmentApi.submitWithFile(activeAssignment.id, formData);
      if(!response?.data?.id || Number(response.data.assignment_id)!==Number(activeAssignment.id) || (isRevision && Number(response.data.id)!==Number(existing.id))) throw new Error('Status respons belum pasti.');

      setNotice(
        isRevision
          ? "Revisi jawaban dan lampiran berhasil dikirim."
          : "Tugas berhasil dikumpulkan."
      );

      fileState.clear();setUncertain(false);setActiveAssignment(null);
      setAnswer("");
      setSelectedFiles([]);

      await loadAssignments();
    } catch (error) {
      if(!error.status||error.status>=500||[408,409,429].includes(error.status)){setUncertain(true);setFormError("Status pengiriman belum pasti. Verifikasi catatan server sebelum mengirim ulang.");}
      else setFormError(getErrorMessage(error));
    } finally {
      writeLock.current=false;setSaving(false);
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
          <p className="siswa-assignments__eyebrow">PEMBELAJARAN / TUGAS SISWA</p>
          <h1>Tugas siswa</h1>
          <p className="siswa-assignments__subtitle">
            Lihat tugas berdasarkan mata pelajaran, lalu kumpulkan jawabanmu.
          </p>
        </div>

        <button
          className="siswa-assignments__refresh"
          type="button"
          onClick={()=>{fileState.clear();loadAssignments()}}
          disabled={loading || saving}
        >
          {loading ? "Memuat..." : "Muat ulang"}
        </button>
      </header>

      {loading && assignments.length > 0 && <p className="siswa-assignments__loading" role="status">Memperbarui data tugas…</p>}
      {notice && (
        <div className="siswa-assignment-toast" role="status" aria-live="polite">
          <span className="siswa-assignment-toast__icon" aria-hidden="true">✓</span>
          <div className="siswa-assignment-toast__copy">
            <strong>{notice.includes("Revisi") ? "Perubahan berhasil disimpan" : "Tugas berhasil dikumpulkan"}</strong>
            <span>{notice}</span>
          </div>
          <button type="button" onClick={() => setNotice("")} aria-label="Tutup notifikasi">×</button>
        </div>
      )}

      {pageError && (
        <div className="siswa-assignments__alert siswa-assignments__alert--error">
          <span>{pageError}</span>
          <button type="button" onClick={()=>{fileState.clear();loadAssignments()}}>
            Coba lagi
          </button>
        </div>
      )}

      {context && <p className="siswa-assignments__context">
        Periode akademik aktif · {context.className ? `Kelas ${context.className}` : 'Belum ada penempatan kelas aktif'}
      </p>}
      {!pageError && <section className="siswa-assignment-summary">
        <article className="siswa-assignment-summary__card">
          <span>Total Tugas</span>
          <strong>{assignmentSummary.total}</strong>
          <small>Semua tugas yang tersedia</small>
        </article>

        <article className="siswa-assignment-summary__card siswa-assignment-summary__card--pending">
          <span>Belum Dikumpulkan</span>
          <strong>{assignmentSummary.pending+overdue}</strong>
          <small>Perlu diselesaikan</small>
        </article>

        <article className="siswa-assignment-summary__card siswa-assignment-summary__card--submitted">
          <span>Sudah Dikumpulkan</span>
          <strong>{assignmentSummary.submitted}</strong>
          <small>Jawaban telah dikirim</small>
        </article>

        <article className="siswa-assignment-summary__card siswa-assignment-summary__card--late">
          <span>Tenggat terlewat</span>
          <strong>{overdue}</strong>
          <small>{lateSubmitted} tugas dikumpulkan terlambat (termasuk sudah dikumpulkan)</small>
        </article>
      </section>}

      {!activeAssignment&&<PreparedStudentFile state={fileState}/>}
      {!pageError && <section className="siswa-assignments__toolbar">
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
              <option key={subject.key} value={subject.key}>
                {subject.name}
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
            <option value="terlambat">Dikumpulkan terlambat</option><option value="overdue">Tenggat terlewat — belum dikumpulkan</option>
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
      </section>}

      {!pageError && !loading && assignments.length > 0 && (
        <div className="siswa-assignments__section-head">
          <div><h2>Mata pelajaran</h2><p>Pilih mata pelajaran untuk melihat dan mengumpulkan tugas.</p></div>
          <span>{subjectOptions.length} mata pelajaran</span>
        </div>
      )}

      {pageError ? null : loading && assignments.length === 0 ? (
        <section className="siswa-assignments__empty">
          <h2>Memuat tugas...</h2>
          <p>Daftar tugas dan status pengumpulan sedang diperiksa.</p>
        </section>
      ) : groupedAssignments.length === 0 ? (
        <section className="siswa-assignments__empty">
          <h2>{assignments.length === 0 ? "Belum ada tugas untuk kelas ini" : "Tidak ada tugas sesuai filter"}</h2>
          <p>{assignments.length === 0 ? "Tugas dari guru akan muncul di sini setelah tersedia." : "Coba ubah kata pencarian atau filter yang dipilih."}</p>
          {assignments.length > 0 && <button type="button" className="siswa-assignments__reset" onClick={resetFilters}>Reset Filter</button>}
        </section>
      ) : (
        <div className="siswa-assignment-groups" aria-label="Daftar mata pelajaran">
          {groupedAssignments.map((group) => {
            const isCollapsed = !Boolean(collapsedSubjects[group.key]);

            return (
              <section className={`siswa-assignment-group${isCollapsed ? "" : " is-open"}`} key={group.key}>
                <button
                  type="button"
                  className="siswa-assignment-group__header siswa-assignment-group__toggle"
                  onClick={() => toggleSubject(group.key)}
                  aria-expanded={!isCollapsed}
                >
                  <div
                    className="siswa-assignment-group__icon"
                    aria-hidden="true"
                  >
                    {group.subject === "Mata Pelajaran Belum Tersedia"
                      ? "?"
                      : "▤"}
                  </div>

                  <div className="siswa-assignment-group__title">
                    <p className="siswa-assignment-group__eyebrow">
                      MATA PELAJARAN
                    </p>
                    <h2>{group.subject}</h2>
                  </div>

                  <span className="siswa-assignment-group__count">
                    {group.assignments.length} tugas pada halaman ini · {group.assignments.filter(a => Boolean(submissions[a.id])).length} dikumpulkan
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
                                {assignment.guru_pengampu ? ` · ${assignment.guru_pengampu}` : ""}
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
                                loading || Boolean(submission?._loadError) ||
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


      {!loading&&!pageError&&<TeacherListPager data={{...paging,setPage:n=>{fileState.clear();paging.setPage(n)}}} label="tugas"/>}
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

            <div className="siswa-assignment-modal__task">
              <span>TUGAS YANG DIKERJAKAN</span>
              <strong>{activeAssignment.judul}</strong>
              <small>{getSubjectName(activeAssignment)} · Tenggat {formatDate(activeAssignment.deadline)}</small>
            </div>

            <form onSubmit={handleSubmit}>
              <label className="siswa-assignment-modal__field">
                <span>Jawaban teks <small>(opsional jika mengunggah file)</small></span>
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

              <div className="siswa-assignment-upload">
                <div className="siswa-assignment-upload__heading">
                  <div>
                    <strong>Lampiran jawaban</strong>
                    <p>Tambah file satu per satu, termasuk dari HP. File sebelumnya tetap tersimpan.</p>
                  </div>
                  <span>{selectedFiles.length} / {MAX_FILES} file baru</span>
                </div>
                <input
                  ref={fileInputRef}
                  id="student-assignment-upload"
                  className="siswa-assignment-upload__input"
                  type="file"
                  multiple
                  accept={ALLOWED_EXTENSIONS.join(",")}
                  onChange={handleFileSelection}
                  disabled={saving || selectedFiles.length >= MAX_FILES}
                  aria-describedby="student-assignment-upload-hint"
                />
                <button
                  type="button"
                  className="siswa-assignment-upload__pick"
                  onClick={() => fileInputRef.current?.click()}
                  disabled={saving || selectedFiles.length >= MAX_FILES}
                >
                  <span className="siswa-assignment-upload__plus" aria-hidden="true">+</span>
                  <span>{selectedFiles.length ? "Tambah file lainnya" : "Pilih file jawaban"}</span>
                </button>
                <small id="student-assignment-upload-hint">JPG, PNG, PDF, Word, Excel, PowerPoint · maks. 10 MB per file.</small>
                {selectedFiles.length > 0 && (
                  <div className="siswa-assignment-upload__queue">
                    <div className="siswa-assignment-upload__queue-header">
                      <strong>Siap dikirim</strong>
                      <span>{selectedFiles.length} file</span>
                    </div>
                    <ul>
                      {selectedFiles.map((file, index) => (
                        <li key={`${file.name}-${file.size}-${file.lastModified}-${index}`}>
                          <span className="siswa-assignment-upload__file-icon" aria-hidden="true">↥</span>
                          <span className="siswa-assignment-upload__filename">
                            <strong>{file.name}</strong>
                            <small>{formatFileSize(file.size)}</small>
                          </span>
                          <button
                            type="button"
                            onClick={() => removeSelectedFile(index)}
                            disabled={saving}
                            aria-label={`Hapus ${file.name} dari pilihan`}
                          >Hapus</button>
                        </li>
                      ))}
                    </ul>
                  </div>
                )}
              </div>

              <PreparedStudentFile state={fileState}/>
              {uncertain&&<button type="button" disabled={checking||saving} onClick={checkSubmission}>{checking?"Memeriksa…":"Verifikasi catatan pengumpulan"}</button>}
              {formError && (
                <div className="siswa-assignment-modal__error" role="alert">
                  <span aria-hidden="true">!</span> {formError}
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
                    saving || uncertain || checking ||
                    Boolean(
                      submissions[activeAssignment.id] &&
                        !submissions[activeAssignment.id]._loadError &&
                        isDeadlinePassed(activeAssignment)
                    )
                  }
                >
                  {saving ? "Mengirim jawaban…" : (submissions[activeAssignment.id] ? "Simpan revisi" : "Kumpulkan tugas")}
                </button>
              </div>
            </form>
          </section>
        </div>
      )}
    </main>
  );
}
