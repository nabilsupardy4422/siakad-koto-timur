/** Tambahkan pilihan file tanpa menghapus yang sudah dipilih. Tidak menyentuh FileList DOM. */
export function appendFilesToSelection(current, incoming, {maxFiles = 10, validate = () => ""} = {}) {
  const oldFiles = Array.from(current || []);
  const next = [...oldFiles];
  const seen = new Set(oldFiles.map(fileIdentity));
  for (const file of Array.from(incoming || [])) {
    const error = validate(file);
    if (error) return {files: oldFiles, error: `${file?.name || "File"}: ${error}`};
    const key = fileIdentity(file);
    if (seen.has(key)) continue;
    if (next.length >= maxFiles) return {files: oldFiles, error: `Maksimal ${maxFiles} file baru dapat dipilih.`};
    next.push(file);
    seen.add(key);
  }
  return {files: next, error: ""};
}

function fileIdentity(file) {
  return `${file?.name ?? ""}\u0000${file?.size ?? ""}\u0000${file?.lastModified ?? ""}`;
}
