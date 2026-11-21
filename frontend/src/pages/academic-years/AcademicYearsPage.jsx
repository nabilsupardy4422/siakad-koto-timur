import { useEffect, useState } from "react";

import Button from "../../components/ui/Button";
import Card from "../../components/ui/Card";
import EmptyState from "../../components/ui/EmptyState";
import ErrorState from "../../components/ui/ErrorState";
import Input from "../../components/ui/Input";
import LoadingState from "../../components/ui/LoadingState";
import Modal from "../../components/ui/Modal";
import PageHeader from "../../components/ui/PageHeader";
import Select from "../../components/ui/Select";

import { academicYearApi } from "../../services/academicYearApi";

import "./AcademicYearsPage.css";

const initialForm = {
  tahun_mulai: "",
  tahun_selesai: "",
  semester: "",
  is_active: "",
};

const semesterOptions = [
  { value: "GANJIL", label: "Ganjil" },
  { value: "GENAP", label: "Genap" },
];

const statusOptions = [
  { value: "1", label: "Aktif" },
  { value: "0", label: "Tidak aktif" },
];

function normalizeAcademicYear(item) {
  return {
    ...item,
    id: item.id,
    tahun_mulai: String(item.tahun_mulai ?? ""),
    tahun_selesai: String(item.tahun_selesai ?? ""),
    semester: item.semester ?? "",
    is_active:
      item.is_active === true || item.is_active === 1 || item.is_active === "1",
  };
}

function getFieldError(errors, field) {
  const value = errors?.[field];

  if (Array.isArray(value)) {
    return value[0] || "";
  }

  return value || "";
}

function getAcademicYearLabel(item) {
  return `${item.tahun_mulai}/${item.tahun_selesai}`;
}

function getSemesterLabel(semester) {
  if (semester === "GANJIL") return "Ganjil";
  if (semester === "GENAP") return "Genap";
  return semester || "-";
}

export default function AcademicYearsPage() {
  const [academicYears, setAcademicYears] = useState([]);

  const [loading, setLoading] = useState(true);
  const [listError, setListError] = useState(null);

  const [modalOpen, setModalOpen] = useState(false);
  const [modalMode, setModalMode] = useState("create");
  const [editingId, setEditingId] = useState(null);

  const [form, setForm] = useState(initialForm);
  const [fieldErrors, setFieldErrors] = useState({});
  const [submitError, setSubmitError] = useState(null);
  const [saving, setSaving] = useState(false);

  const [successMessage, setSuccessMessage] = useState("");


  const [importModalOpen, setImportModalOpen] = useState(false);
  const [importFile, setImportFile] = useState(null);
  const [importPreview, setImportPreview] = useState(null);
  const [importLoading, setImportLoading] = useState(false);
  const [importError, setImportError] = useState("");
  const [importSuccess, setImportSuccess] = useState("");
  const [templateError, setTemplateError] = useState("");
  const [downloadingTemplate, setDownloadingTemplate] = useState(false);

  const [deleteTarget, setDeleteTarget] = useState(null);
  const [deletingId, setDeletingId] = useState(null);

  async function loadAcademicYears() {
    setLoading(true);
    setListError(null);

    try {
      const response = await academicYearApi.getAll();
      const data = Array.isArray(response?.data) ? response.data : [];

      setAcademicYears(data.map(normalizeAcademicYear));
    } catch (error) {
      setListError(error);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    loadAcademicYears();
  }, []);

  function openCreateModal() {
    setModalMode("create");
    setEditingId(null);
    setForm(initialForm);
    setFieldErrors({});
    setSubmitError(null);
    setSuccessMessage("");
    setModalOpen(true);
  }

  function openEditModal(item) {
    setModalMode("edit");
    setEditingId(item.id);

    setForm({
      tahun_mulai: String(item.tahun_mulai ?? ""),
      tahun_selesai: String(item.tahun_selesai ?? ""),
      semester: item.semester ?? "",
      is_active: item.is_active ? "1" : "0",
    });

    setFieldErrors({});
    setSubmitError(null);
    setSuccessMessage("");
    setModalOpen(true);
  }

  function closeModal() {
    if (saving) return;

    setModalOpen(false);
    setEditingId(null);
    setForm(initialForm);
    setFieldErrors({});
    setSubmitError(null);
  }

  function handleChange(event) {
    const { name, value } = event.target;

    setForm((current) => ({
      ...current,
      [name]: value,
    }));

    setFieldErrors((current) => ({
      ...current,
      [name]: "",
    }));

    setSubmitError(null);
  }

  function validateForm() {
    const errors = {};

    const tahunMulai = String(form.tahun_mulai ?? "").trim();
    const tahunSelesai = String(form.tahun_selesai ?? "").trim();

    if (!tahunMulai) {
      errors.tahun_mulai = "Tahun mulai wajib diisi.";
    } else if (!/^\d{4}$/.test(tahunMulai)) {
      errors.tahun_mulai = "Tahun mulai harus terdiri dari 4 digit.";
    }

    if (!tahunSelesai) {
      errors.tahun_selesai = "Tahun selesai wajib diisi.";
    } else if (!/^\d{4}$/.test(tahunSelesai)) {
      errors.tahun_selesai = "Tahun selesai harus terdiri dari 4 digit.";
    }

    if (
      tahunMulai &&
      tahunSelesai &&
      /^\d{4}$/.test(tahunMulai) &&
      /^\d{4}$/.test(tahunSelesai) &&
      Number(tahunSelesai) < Number(tahunMulai)
    ) {
      errors.tahun_selesai =
        "Tahun selesai tidak boleh lebih kecil dari tahun mulai.";
    }

    if (!form.semester) {
      errors.semester = "Semester wajib dipilih.";
    }

    if (form.is_active !== "1" && form.is_active !== "0") {
      errors.is_active = "Status wajib dipilih.";
    }

    return errors;
  }

  async function handleSubmit(event) {
    event.preventDefault();

    setSuccessMessage("");
    setSubmitError(null);

    const errors = validateForm();

    if (Object.keys(errors).length > 0) {
      setFieldErrors(errors);
      return;
    }

    setSaving(true);

    const payload = {
      tahun_mulai: Number(form.tahun_mulai),
      tahun_selesai: Number(form.tahun_selesai),
      semester: form.semester,
      is_active: form.is_active === "1",
    };

    try {
      if (modalMode === "edit") {
        await academicYearApi.update(editingId, payload);
        setSuccessMessage("Tahun akademik berhasil diperbarui.");
      } else {
        await academicYearApi.create(payload);
        setSuccessMessage("Tahun akademik berhasil ditambahkan.");
      }

      setModalOpen(false);
      setEditingId(null);
      setForm(initialForm);
      setFieldErrors({});
      setSubmitError(null);

      await loadAcademicYears();
    } catch (error) {
      if (error?.status === 422) {
        setFieldErrors(error.validationErrors || {});
      }

      setSubmitError(error);
    } finally {
      setSaving(false);
    }
  }

  function openDeleteModal(item) {
    if (deletingId !== null) return;

    setSuccessMessage("");
    setListError(null);
    setDeleteTarget(item);
  }

  function closeDeleteModal() {
    if (deletingId !== null) return;

    setDeleteTarget(null);
  }

  async function confirmDelete() {
    if (!deleteTarget || deletingId !== null) return;

    const item = deleteTarget;

    setDeletingId(item.id);
    setSuccessMessage("");
    setListError(null);

    try {
      await academicYearApi.delete(item.id);

      setDeleteTarget(null);
      setSuccessMessage(
        `Tahun akademik ${getAcademicYearLabel(item)} berhasil dihapus.`,
      );

      await loadAcademicYears();
    } catch (error) {
      setDeleteTarget(null);
      setListError(error);
    } finally {
      setDeletingId(null);
    }
  }

  function openImportModal() {
  setImportFile(null);
  setImportPreview(null);
  setImportError("");
  setImportSuccess("");
  setImportModalOpen(true);
}

function closeImportModal() {
  if (importLoading) return;

  setImportModalOpen(false);
  setImportFile(null);
  setImportPreview(null);
  setImportError("");
}

async function handleDownloadTemplate() {
  if (downloadingTemplate) return;

  setTemplateError("");
  setDownloadingTemplate(true);

  try {
    const blob = await academicYearApi.downloadTemplate();

    const url = window.URL.createObjectURL(blob);
    const link = document.createElement("a");

    link.href = url;
    link.download = "template_tahun_akademik.xlsx";

    document.body.appendChild(link);
    link.click();
    link.remove();

    window.URL.revokeObjectURL(url);
  } catch (error) {
    setTemplateError(error.message || "Template Excel gagal diunduh.");
  } finally {
    setDownloadingTemplate(false);
  }
}

function handleImportFileChange(event) {
  const file = event.target.files?.[0] || null;

  setImportFile(file);
  setImportPreview(null);
  setImportError("");

  if (!file) return;

  if (!file.name.toLowerCase().endsWith(".xlsx")) {
    setImportFile(null);
    setImportError("File harus menggunakan format .xlsx.");
    event.target.value = "";
    return;
  }

  if (file.size > 2 * 1024 * 1024) {
    setImportFile(null);
    setImportError("Ukuran file maksimal 2 MB.");
    event.target.value = "";
  }
}

function getImportResponseData(response) {
  return response?.data?.data ?? response?.data ?? {};
}

function getImportErrorMessage(error) {
  const errors = error?.validationErrors || error?.errors || {};
  const firstError = Object.values(errors)
    .flat()
    .find(Boolean);

  return (
    firstError ||
    error?.payload?.message ||
    error?.message ||
    "Terjadi kesalahan saat memproses import."
  );
}

async function handlePreviewImport() {
  if (!importFile || importLoading) return;

  setImportLoading(true);
  setImportError("");
  setImportPreview(null);

  try {
    const formData = new FormData();
    formData.append("file", importFile);

    const response = await academicYearApi.previewImport(formData);

    const data = getImportResponseData(response);

    if (!data.summary || !Array.isArray(data.rows)) {
      throw new Error("Format respons preview dari server tidak sesuai.");
    }

    setImportPreview(data);
  } catch (error) {
    setImportError(getImportErrorMessage(error));
  } finally {
    setImportLoading(false);
  }
}

async function handleConfirmImport() {
  if (
    !importFile ||
    !importPreview ||
    importPreview.summary?.invalid_rows > 0 ||
    importLoading
  ) {
    return;
  }

  const confirmed = window.confirm(
    `Import ${importPreview.summary.total_rows} tahun akademik ke sistem?`,
  );

  if (!confirmed) return;

  setImportLoading(true);
  setImportError("");

  try {
    const formData = new FormData();
    formData.append("file", importFile);

    const response = await academicYearApi.importExcel(formData);

    const data = getImportResponseData(response);

    setImportModalOpen(false);
    setImportFile(null);
    setImportPreview(null);

    setSuccessMessage(
      response?.message ||
        `Berhasil mengimpor ${data.imported_rows ?? importPreview.summary.total_rows} tahun akademik.`,
    );

    await loadAcademicYears();
  } catch (error) {
    setImportError(getImportErrorMessage(error));
  } finally {
    setImportLoading(false);
  }
}

  if (loading) {
    return <LoadingState message="Memuat data tahun akademik..." />;
  }

  if (listError && academicYears.length === 0) {
    return (
      <ErrorState
        message={listError.message || "Data tahun akademik tidak dapat dimuat."}
      />
    );
  }

  return (
    <div className="academic-years-page">
      <PageHeader
        eyebrow="Data Akademik"
        title="Tahun Akademik"
        description="Kelola periode tahun akademik sekolah."
        actions={
          <div className="academic-years-page__header-actions">
            <Button
              type="button"
              onClick={handleDownloadTemplate}
              disabled={downloadingTemplate}
            >
              {downloadingTemplate ? "Mengunduh..." : "Download Template"}
            </Button>

            <Button
              type="button"
              onClick={openImportModal}
            >
              Import Excel
            </Button>

            <Button type="button" onClick={openCreateModal}>
              + Tambah Tahun Akademik
            </Button>
          </div>
        }
      />

      {templateError && (
        <div
          className="academic-years-page__alert academic-years-page__alert--error"
          role="alert"
        >
          {templateError}
        </div>
      )}

      {successMessage && (
        <div
          className="academic-years-page__alert academic-years-page__alert--success"
          role="status"
        >
          {successMessage}
        </div>
      )}

      {listError && academicYears.length > 0 && (
        <div
          className="academic-years-page__alert academic-years-page__alert--error"
          role="alert"
        >
          {listError.message ||
            "Terjadi kesalahan saat memproses data tahun akademik."}

          <Button
            type="button"
            size="small"
            variant="secondary"
            onClick={loadAcademicYears}
          >
            Muat Ulang
          </Button>
        </div>
      )}

      <Card className="academic-years-page__list-card">
        <div className="academic-years-page__list-header">
          <div>
            <span className="academic-years-page__eyebrow">Data Akademik</span>

            <h2 className="academic-years-page__title">
              Daftar Tahun Akademik
            </h2>

            <p className="academic-years-page__description">
              Daftar periode akademik yang tersedia dalam sistem.
            </p>
          </div>

          <span className="academic-years-page__count">
            {academicYears.length} periode
          </span>
        </div>

        {academicYears.length === 0 ? (
          <EmptyState
            title="Belum ada tahun akademik"
            message="Belum terdapat periode tahun akademik yang tersimpan."
            action={
              <Button type="button" onClick={openCreateModal}>
                Tambah Tahun Akademik
              </Button>
            }
          />
        ) : (
          <div className="academic-years-page__table-wrapper">
            <table className="academic-years-page__table">
              <thead>
                <tr>
                  <th>Tahun Akademik</th>
                  <th>Semester</th>
                  <th>Status</th>
                  <th>Aksi</th>
                </tr>
              </thead>

              <tbody>
                {academicYears.map((item) => (
                  <tr key={item.id}>
                    <td>
                      <strong>{getAcademicYearLabel(item)}</strong>
                    </td>

                    <td>{getSemesterLabel(item.semester)}</td>

                    <td>
                      <span
                        className={
                          item.is_active
                            ? "academic-years-page__status academic-years-page__status--active"
                            : "academic-years-page__status"
                        }
                      >
                        {item.is_active ? "Aktif" : "Tidak aktif"}
                      </span>
                    </td>

                    <td>
                      <div className="academic-years-page__actions">
                        <Button
                          type="button"
                          size="small"
                          onClick={() => openEditModal(item)}
                          disabled={deletingId === item.id}
                        >
                          Edit
                        </Button>

                        <Button
                          type="button"
                          size="small"
                          variant="secondary"
                          onClick={() => openDeleteModal(item)}
                          disabled={deletingId !== null}
                        >
                          {deletingId === item.id ? "Menghapus..." : "Hapus"}
                        </Button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

            {/* Modal Import Excel */}
      {/* Modal Import Excel */}
<Modal
  open={importModalOpen}
  title="Import Tahun Akademik"
  description="Import beberapa periode tahun akademik menggunakan file Excel."
  onClose={closeImportModal}
  size="large"
  footer={
    <>
      <Button
        type="button"
        variant="secondary"
        disabled={importLoading}
        onClick={closeImportModal}
      >
        Tutup
      </Button>

      {!importPreview && (
        <Button
          type="button"
          loading={importLoading}
          disabled={!importFile || importLoading}
          onClick={handlePreviewImport}
        >
          Preview Data
        </Button>
      )}

      {importPreview && (
        <Button
          type="button"
          loading={importLoading}
          disabled={
            importLoading ||
            importPreview.summary?.invalid_rows > 0 ||
            importPreview.summary?.valid_rows === 0
          }
          onClick={handleConfirmImport}
        >
          Konfirmasi Import
        </Button>
      )}
    </>
  }
>
  <div className="academic-years-page__import-card">
  <div className="academic-years-page__import-heading">
    <h3>Petunjuk Import</h3>
    <p>
      Gunakan template resmi Tahun Akademik. Pastikan data
      tahun mulai, tahun selesai, semester, dan status
      telah diisi sesuai ketentuan.
    </p>
  </div>

  <div className="academic-years-page__import-info">
    <strong>Ketentuan file:</strong>
    <br />
    Format: .xlsx
    <br />
    Pastikan nama kolom sesuai template resmi.
    <br />
    Periksa kembali data sebelum melakukan import.
  </div>

  <div className="academic-years-page__file-control">
    <label
      htmlFor="academic-year-import-file"
      className="academic-years-page__file-label"
    >
      Pilih File Excel
    </label>

    <input
      id="academic-year-import-file"
      className="academic-years-page__file-input"
      type="file"
      accept=".xlsx"
      onChange={handleImportFileChange}
      disabled={importLoading}
    />

    {importFile && (
      <p className="academic-years-page__selected-file">
        File dipilih: <strong>{importFile.name}</strong>
      </p>
    )}
  </div>

  {importError && (
    <div
      className="academic-years-page__import-error"
      role="alert"
    >
      {importError}
    </div>
  )}

  {importPreview && (
    <div className="academic-years-page__preview">
      <div className="academic-years-page__preview-heading">
        <h3>Hasil Preview Import</h3>
        <p>
          Periksa hasil validasi sebelum mengimpor data.
        </p>
      </div>

      <div className="academic-years-page__preview-summary">
        <div className="academic-years-page__preview-stat">
          <span>Total Data</span>
          <strong>
            {importPreview.summary?.total_rows ?? 0}
          </strong>
        </div>

        <div className="academic-years-page__preview-stat academic-years-page__preview-stat--valid">
          <span>Data Valid</span>
          <strong>
            {importPreview.summary?.valid_rows ?? 0}
          </strong>
        </div>

        <div className="academic-years-page__preview-stat academic-years-page__preview-stat--invalid">
          <span>Data Tidak Valid</span>
          <strong>
            {importPreview.summary?.invalid_rows ?? 0}
          </strong>
        </div>
      </div>

      <div className="academic-years-page__preview-table-wrapper">
        <table className="academic-years-page__preview-table">
          <thead>
            <tr>
              <th>Baris</th>
              <th>Tahun Akademik</th>
              <th>Semester</th>
              <th>Status</th>
              <th>Validasi</th>
            </tr>
          </thead>

          <tbody>
            {(importPreview.rows || []).map((row) => (
              <tr key={row.row}>
                <td>{row.row}</td>

                <td>
                  {row.tahun_mulai}/{row.tahun_selesai}
                </td>

                <td>{row.semester}</td>

                <td>
                  {String(row.is_active) === "1"
                    ? "Aktif"
                    : "Tidak aktif"}
                </td>

                <td>
                  <span
                    className={`academic-years-page__preview-status ${
                      row.valid
                        ? "academic-years-page__preview-status--valid"
                        : "academic-years-page__preview-status--invalid"
                    }`}
                  >
                    {row.valid ? "Valid" : "Tidak Valid"}
                  </span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {importPreview.summary?.invalid_rows > 0 && (
        <div className="academic-years-page__preview-note">
          Import belum dapat dilakukan karena masih ada{" "}
          {importPreview.summary.invalid_rows} baris yang
          tidak valid. Perbaiki file Excel, kemudian unggah
          ulang untuk melakukan preview.
        </div>
      )}

      {importPreview.summary?.invalid_rows === 0 &&
        importPreview.summary?.valid_rows > 0 && (
          <div className="academic-years-page__import-info">
            Semua data telah lolos validasi. Kamu dapat
            melanjutkan dengan menekan tombol
            <strong> Konfirmasi Import</strong>.
          </div>
        )}
    </div>
  )}
</div>
</Modal>

      {/* Modal tambah dan edit tahun akademik */}
      {modalOpen && (
        <div
          className="academic-years-page__modal-backdrop"
          role="presentation"
          onMouseDown={(event) => {
            if (event.target === event.currentTarget && !saving) {
              closeModal();
            }
          }}
        >
          <div
            className="academic-years-page__modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="academic-year-modal-title"
          >
            <div className="academic-years-page__modal-header">
              <div>
                <h2
                  id="academic-year-modal-title"
                  className="academic-years-page__modal-title"
                >
                  {modalMode === "edit"
                    ? "Edit Tahun Akademik"
                    : "Tambah Tahun Akademik"}
                </h2>

                <p className="academic-years-page__modal-description">
                  {modalMode === "edit"
                    ? "Perbarui informasi periode akademik yang dipilih."
                    : "Tambahkan periode akademik baru ke dalam sistem."}
                </p>
              </div>

              <button
                type="button"
                className="academic-years-page__modal-close"
                onClick={closeModal}
                disabled={saving}
                aria-label="Tutup modal"
              >
                ×
              </button>
            </div>

            <form className="academic-years-page__form" onSubmit={handleSubmit}>
              {submitError && submitError.status !== 422 && (
                <div
                  className="academic-years-page__alert academic-years-page__alert--error"
                  role="alert"
                >
                  {submitError.message || "Data tidak dapat disimpan."}
                </div>
              )}

              <div className="academic-years-page__fields">
                <Input
                  id="tahun_mulai"
                  name="tahun_mulai"
                  label="Tahun Mulai"
                  type="text"
                  inputMode="numeric"
                  maxLength={4}
                  value={form.tahun_mulai}
                  placeholder="Contoh: 2026"
                  required
                  disabled={saving}
                  error={getFieldError(fieldErrors, "tahun_mulai")}
                  onChange={handleChange}
                />

                <Input
                  id="tahun_selesai"
                  name="tahun_selesai"
                  label="Tahun Selesai"
                  type="text"
                  inputMode="numeric"
                  maxLength={4}
                  value={form.tahun_selesai}
                  placeholder="Contoh: 2027"
                  required
                  disabled={saving}
                  error={getFieldError(fieldErrors, "tahun_selesai")}
                  onChange={handleChange}
                />

                <Select
                  id="semester"
                  name="semester"
                  label="Semester"
                  value={form.semester}
                  options={semesterOptions}
                  placeholder="Pilih semester"
                  required
                  disabled={saving}
                  error={getFieldError(fieldErrors, "semester")}
                  onChange={handleChange}
                />

                <Select
                  id="is_active"
                  name="is_active"
                  label="Status"
                  value={form.is_active}
                  options={statusOptions}
                  placeholder="Pilih status"
                  required
                  disabled={saving}
                  error={getFieldError(fieldErrors, "is_active")}
                  onChange={handleChange}
                />
              </div>

              <div className="academic-years-page__modal-footer">
                <Button
                  type="button"
                  variant="secondary"
                  onClick={closeModal}
                  disabled={saving}
                >
                  Batal
                </Button>

                <Button type="submit" loading={saving}>
                  {modalMode === "edit" ? "Perbarui" : "Simpan"}
                </Button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Modal konfirmasi hapus */}
      <Modal
        open={Boolean(deleteTarget)}
        title="Hapus Tahun Akademik"
        description="Periksa periode yang akan dihapus sebelum melanjutkan."
        onClose={closeDeleteModal}
        size="small"
        footer={
          <>
            <Button
              type="button"
              variant="secondary"
              onClick={closeDeleteModal}
              disabled={deletingId !== null}
            >
              Batal
            </Button>

            <Button
              type="button"
              onClick={confirmDelete}
              disabled={deletingId !== null}
              loading={deletingId !== null}
            >
              {deletingId !== null ? "Menghapus..." : "Ya, Hapus"}
            </Button>
          </>
        }
      >
        {deleteTarget && (
          <div className="academic-years-page__delete-content">
            <p>Apakah kamu yakin ingin menghapus tahun akademik berikut?</p>

            <div className="academic-years-page__delete-summary">
              <strong>{getAcademicYearLabel(deleteTarget)}</strong>

              <span>Semester {getSemesterLabel(deleteTarget.semester)}</span>

              <span>
                Status: {deleteTarget.is_active ? "Aktif" : "Tidak aktif"}
              </span>
            </div>

            <p className="academic-years-page__delete-warning">
              Jika periode ini masih digunakan oleh data akademik lain, sistem
              dapat menolak penghapusan. Data hanya akan dihapus jika diizinkan
              oleh backend.
            </p>
          </div>
        )}
      </Modal>
    </div>
  );
}
