/** Client-only delivery for authorized reporting Blobs.
 * A real <a download> tap or native share is required after fetching because
 * synthetic anchor.click() after await is not reliable on mobile browsers.
 */
export const REPORT_MIME = Object.freeze({
  pdf: 'application/pdf',
  excel: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
})

export function makePreparedDownload(blob, filename, format, urlApi = URL) {
  if (!Object.hasOwn(REPORT_MIME, format)) throw new Error('Format dokumen tidak valid.')
  if (!(blob instanceof Blob) || blob.size <= 0 || /(?:json|html|text\/plain)/i.test(blob.type)) {
    throw new Error('Server tidak mengembalikan dokumen yang valid.')
  }
  if (typeof filename !== 'string' || !/^laporan-[a-z0-9-]+\.(pdf|xlsx)$/.test(filename)) {
    throw new Error('Nama file laporan tidak valid.')
  }
  const url = urlApi.createObjectURL(blob)
  return { url, filename, blob, format }
}

export function makeSharedFile(prepared) {
  return new File([prepared.blob], prepared.filename, { type: REPORT_MIME[prepared.format] })
}

export function isNativeFileShareAvailable(prepared, device = globalThis.navigator) {
  if (!prepared || typeof File === 'undefined' || typeof device?.canShare !== 'function' || typeof device?.share !== 'function') return false
  try {
    return device.canShare({ files: [makeSharedFile(prepared)] }) === true
  } catch { return false }
}
