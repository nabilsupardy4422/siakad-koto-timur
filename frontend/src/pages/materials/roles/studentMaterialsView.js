export function parseMaterialsWorkspace(response) {
  const body = response?.data?.data ?? response?.data;
  if (
    !body ||
    !Array.isArray(body.subjects) ||
    !body.academic_year ||
    !Object.hasOwn(body, "placement_status")
  ) {
    throw new Error("Data bahan ajar tidak sesuai format yang diharapkan.");
  }
  const ids = new Set();
  for (const subject of body.subjects) {
    if (
      !Number.isInteger(subject.id) ||
      ids.has(subject.id) ||
      !Array.isArray(subject.teachers)
    ) {
      throw new Error("Data mata pelajaran tidak konsisten.");
    }
    ids.add(subject.id);
  }
  if (body.materials !== null && body.materials !== undefined) {
    const m = body.materials;
    if (
      !Array.isArray(m.data) ||
      !Number.isInteger(m.page) ||
      !Number.isInteger(m.last_page) ||
      !Number.isInteger(m.total) ||
      m.page < 1 ||
      m.last_page < 1
    ) {
      throw new Error("Data halaman materi tidak valid.");
    }
  }
  return body;
}

export function fileKind(name = "", mime = "") {
  const type = String(mime).toLowerCase();
  const extension = String(name).split(".").pop()?.toLowerCase();
  if (type.includes("pdf") || extension === "pdf") return "PDF";
  if (["doc", "docx"].includes(extension)) return "DOC";
  if (["xls", "xlsx", "csv"].includes(extension)) return "XLS";
  if (["ppt", "pptx"].includes(extension)) return "PPT";
  if (
    type.startsWith("image/") ||
    ["png", "jpg", "jpeg", "webp"].includes(extension)
  )
    return "IMG";
  return "FILE";
}

export function fileSize(size) {
  if (size === null || size === undefined || !Number.isFinite(Number(size)))
    return "";
  return Number(size) >= 1048576
    ? `${(Number(size) / 1048576).toFixed(1)} MB`
    : `${Math.max(1, Math.ceil(Number(size) / 1024))} KB`;
}

export function formatDateWib(value) {
  if (!value) return "Tanggal tidak tersedia";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "Tanggal tidak tersedia";
  return new Intl.DateTimeFormat("id-ID", {
    day: "numeric",
    month: "short",
    year: "numeric",
    timeZone: "Asia/Jakarta",
  }).format(date);
}

export function uniqueTeachers(names) {
  return [...new Set((Array.isArray(names) ? names : []).filter(Boolean))];
}
