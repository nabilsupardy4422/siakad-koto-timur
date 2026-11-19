import { useEffect, useRef, useState } from 'react'
import { apiClient } from '../../services/apiClient'
import ProfilePhotoImage, {
  PROFILE_PHOTO_EVENT,
} from '../../components/profile/ProfilePhotoImage'
import './ProfilePhotoCard.css'

const MAX_FILE_SIZE = 2 * 1024 * 1024
const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp']
const CROP_SIZE = 512

function limitOffset(image, zoom, next) {
  if (!image) return { x: 0, y: 0 }

  const coverScale = Math.max(
    CROP_SIZE / image.naturalWidth,
    CROP_SIZE / image.naturalHeight
  )

  const width = image.naturalWidth * coverScale * zoom
  const height = image.naturalHeight * coverScale * zoom
  const limitX = Math.max(0, (width - CROP_SIZE) / 2)
  const limitY = Math.max(0, (height - CROP_SIZE) / 2)

  return {
    x: Math.max(-limitX, Math.min(limitX, next.x)),
    y: Math.max(-limitY, Math.min(limitY, next.y)),
  }
}

function renderCrop(canvas, image, zoom, offset) {
  if (!canvas || !image) return
  const ctx = canvas.getContext('2d')
  if (!ctx) return

  const coverScale = Math.max(
    CROP_SIZE / image.naturalWidth,
    CROP_SIZE / image.naturalHeight
  )
  const width = image.naturalWidth * coverScale * zoom
  const height = image.naturalHeight * coverScale * zoom

  ctx.clearRect(0, 0, CROP_SIZE, CROP_SIZE)
  ctx.fillStyle = '#ffffff'
  ctx.fillRect(0, 0, CROP_SIZE, CROP_SIZE)
  ctx.imageSmoothingEnabled = true
  ctx.imageSmoothingQuality = 'high'
  ctx.drawImage(
    image,
    (CROP_SIZE - width) / 2 + offset.x,
    (CROP_SIZE - height) / 2 + offset.y,
    width,
    height
  )
}

// Dialog untuk melihat foto lengkap (bukan tampilan lingkaran).
function PhotoViewer({ imageUrl, loading, error, onClose }) {
  const closeRef = useRef(null)

  useEffect(() => {
    closeRef.current?.focus()
    const onKey = (event) => {
      if (event.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [onClose])

  return (
    <div
      className="siakad-photo-dialog-backdrop"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) onClose()
      }}
    >
      <div
        className="siakad-photo-viewer"
        role="dialog"
        aria-modal="true"
        aria-labelledby="siakad-photo-viewer-title"
      >
        <div className="siakad-photo-dialog-header">
          <h3 id="siakad-photo-viewer-title">Lihat Foto Profil</h3>
          <button
            ref={closeRef}
            type="button"
            className="siakad-photo-icon-button"
            aria-label="Tutup tampilan foto"
            onClick={onClose}
          >
            ×
          </button>
        </div>
        <div className="siakad-photo-viewer__body">
          {loading ? (
            <p role="status">Memuat foto...</p>
          ) : error ? (
            <p role="alert">{error}</p>
          ) : imageUrl ? (
            <img src={imageUrl} alt="Foto profil ukuran besar" />
          ) : (
            <p>Belum ada foto profil.</p>
          )}
        </div>
        <div className="siakad-photo-dialog-footer">
          <button type="button" onClick={onClose}>
            Tutup
          </button>
        </div>
      </div>
    </div>
  )
}

// Editor: tampilkan foto dalam bingkai bulat; geser dan zoom sebelum upload.
function PhotoCropEditor({ file, busy, serverError, onSave, onClose }) {
  const canvasRef = useRef(null)
  const loadedImageRef = useRef(null)
  const dragRef = useRef(null)
  const closeRef = useRef(null)

  const [ready, setReady] = useState(false)
  const [loadError, setLoadError] = useState('')
  const [zoom, setZoom] = useState(1)
  const [offset, setOffset] = useState({ x: 0, y: 0 })
  const [fitZoom, setFitZoom] = useState(0.25)

  useEffect(() => {
    let active = true
    const url = URL.createObjectURL(file)
    const image = new Image()

    image.onload = () => {
      if (!active) return
      if (!image.naturalWidth || !image.naturalHeight) {
        setLoadError('Gambar tidak dapat dibaca.')
        return
      }

      loadedImageRef.current = image
      const containScale = Math.min(
        CROP_SIZE / image.naturalWidth,
        CROP_SIZE / image.naturalHeight
      )
      const coverScale = Math.max(
        CROP_SIZE / image.naturalWidth,
        CROP_SIZE / image.naturalHeight
      )

      setFitZoom(containScale / coverScale)
      setZoom(1)
      setOffset({ x: 0, y: 0 })
      setReady(true)
    }

    image.onerror = () => {
      if (active) setLoadError('Foto tidak dapat dibuka. Pilih foto lain.')
    }

    image.src = url
    closeRef.current?.focus()

    return () => {
      active = false
      image.onload = null
      image.onerror = null
      loadedImageRef.current = null
      URL.revokeObjectURL(url)
    }
  }, [file])

  useEffect(() => {
    if (ready) {
      renderCrop(canvasRef.current, loadedImageRef.current, zoom, offset)
    }
  }, [ready, zoom, offset])

  useEffect(() => {
    const onKey = (event) => {
      if (event.key === 'Escape' && !busy) onClose()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [busy, onClose])

  function changeZoom(nextZoom) {
    const value = Math.max(fitZoom, Math.min(3, nextZoom))
    setZoom(value)
    setOffset((previous) =>
      limitOffset(loadedImageRef.current, value, previous)
    )
  }

  function onPointerDown(event) {
    if (!ready || busy || event.button !== 0) return
    event.preventDefault()
    dragRef.current = {
      id: event.pointerId,
      x: event.clientX,
      y: event.clientY,
      start: offset,
    }
    event.currentTarget.setPointerCapture(event.pointerId)
  }

  function onPointerMove(event) {
    const drag = dragRef.current
    if (!drag || drag.id !== event.pointerId) return
    const rect = event.currentTarget.getBoundingClientRect()
    const factor = CROP_SIZE / rect.width
    setOffset(
      limitOffset(loadedImageRef.current, zoom, {
        x: drag.start.x + (event.clientX - drag.x) * factor,
        y: drag.start.y + (event.clientY - drag.y) * factor,
      })
    )
  }

  function onPointerEnd(event) {
    if (dragRef.current?.id === event.pointerId) dragRef.current = null
    if (event.currentTarget.hasPointerCapture?.(event.pointerId)) {
      event.currentTarget.releasePointerCapture(event.pointerId)
    }
  }

  async function handleSave() {
    const canvas = canvasRef.current
    if (!canvas || !ready || busy) return

    const blob = await new Promise((resolve) => {
      canvas.toBlob(resolve, 'image/jpeg', 0.9)
    })
    if (!blob) {
      setLoadError('Foto gagal diproses. Coba pilih ulang fotonya.')
      return
    }

    await onSave(
      new File([blob], 'foto-profil.jpg', { type: 'image/jpeg' })
    )
  }

  return (
    <div
      className="siakad-photo-dialog-backdrop"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget && !busy) onClose()
      }}
    >
      <div
        className="siakad-photo-editor"
        role="dialog"
        aria-modal="true"
        aria-labelledby="siakad-photo-editor-title"
      >
        <div className="siakad-photo-dialog-header">
          <div>
            <h3 id="siakad-photo-editor-title">Atur Foto Profil</h3>
            <p>Geser foto dan atur zoom agar bagian penting masuk ke lingkaran.</p>
          </div>
          <button
            ref={closeRef}
            type="button"
            className="siakad-photo-icon-button"
            aria-label="Tutup pengaturan foto"
            onClick={onClose}
            disabled={busy}
          >
            ×
          </button>
        </div>

        <div className="siakad-photo-editor__body">
          <div
            className="siakad-photo-editor__crop"
            onPointerDown={onPointerDown}
            onPointerMove={onPointerMove}
            onPointerUp={onPointerEnd}
            onPointerCancel={onPointerEnd}
            aria-label="Area pratinjau foto, geser untuk mengubah posisi"
          >
            <canvas
              ref={canvasRef}
              width={CROP_SIZE}
              height={CROP_SIZE}
            />
            {!ready && (
              <span className="siakad-photo-editor__loading">
                {loadError || 'Memuat foto...'}
              </span>
            )}
          </div>

          <div className="siakad-photo-editor__tools">
            <label htmlFor="siakad-photo-zoom">Perbesar / Perkecil</label>
            <div className="siakad-photo-editor__slider-row">
              <span aria-hidden="true">−</span>
              <input
                id="siakad-photo-zoom"
                type="range"
                min={fitZoom}
                max="3"
                step="0.01"
                value={zoom}
                onChange={(event) => changeZoom(Number(event.target.value))}
                disabled={!ready || busy}
              />
              <span aria-hidden="true">+</span>
            </div>
            <div className="siakad-photo-editor__presets">
              <button type="button" onClick={() => changeZoom(fitZoom)} disabled={!ready || busy}>
                Foto utuh
              </button>
              <button type="button" onClick={() => changeZoom(1)} disabled={!ready || busy}>
                Penuhi bingkai
              </button>
              <button
                type="button"
                onClick={() => {
                  changeZoom(1)
                  setOffset({ x: 0, y: 0 })
                }}
                disabled={!ready || busy}
              >
                Reset
              </button>
            </div>
            <p className="siakad-photo-editor__hint">
              Bagian di dalam lingkaran akan menjadi avatar. Area putih bisa muncul jika memilih Foto utuh.
            </p>
            {(loadError || serverError) && (
              <p className="siakad-photo-card__error" role="alert">
                {loadError || serverError}
              </p>
            )}
          </div>
        </div>

        <div className="siakad-photo-dialog-footer">
          <button type="button" onClick={onClose} disabled={busy}>Batal</button>
          <button
            type="button"
            className="is-primary"
            onClick={handleSave}
            disabled={!ready || busy}
          >
            {busy ? 'Menyimpan...' : 'Simpan Foto'}
          </button>
        </div>
      </div>
    </div>
  )
}

export default function ProfilePhotoCard({ userId, name }) {
  const fileInputRef = useRef(null)
  const viewerUrlRef = useRef(null)
  const viewerRequestRef = useRef(0)

  const [file, setFile] = useState(null)
  const [busy, setBusy] = useState(false)
  const [confirmDelete, setConfirmDelete] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [viewer, setViewer] = useState(null)

  const initial = String(name || 'Pengguna').charAt(0).toUpperCase()

  useEffect(() => {
    return () => {
      viewerRequestRef.current += 1
      if (viewerUrlRef.current) URL.revokeObjectURL(viewerUrlRef.current)
    }
  }, [])

  function chooseFile(event) {
    const selected = event.target.files?.[0]
    event.target.value = ''
    setError('')
    setNotice('')
    setConfirmDelete(false)

    if (!selected) return
    if (!IMAGE_TYPES.includes(selected.type)) {
      setError('Gunakan gambar JPG, PNG, atau WebP.')
      return
    }
    if (selected.size > MAX_FILE_SIZE) {
      setError('Ukuran foto maksimal 2 MB.')
      return
    }
    setFile(selected)
  }

  async function savePhoto(croppedFile) {
    if (busy) return
    setBusy(true)
    setError('')
    setNotice('')
    try {
      const formData = new FormData()
      formData.append('photo', croppedFile)
      await apiClient.postForm('/profile/photo', formData)
      setFile(null)
      setNotice('Foto profil berhasil disimpan.')
      window.dispatchEvent(new Event(PROFILE_PHOTO_EVENT))
    } catch (failure) {
      setError(failure?.message || 'Foto gagal disimpan.')
    } finally {
      setBusy(false)
    }
  }

  async function deletePhoto() {
    if (busy) return
    setBusy(true)
    setError('')
    setNotice('')
    try {
      await apiClient.delete('/profile/photo')
      setFile(null)
      setConfirmDelete(false)
      setNotice('Foto profil berhasil dihapus.')
      window.dispatchEvent(new Event(PROFILE_PHOTO_EVENT))
    } catch (failure) {
      setError(failure?.message || 'Foto gagal dihapus.')
    } finally {
      setBusy(false)
    }
  }

  async function editCurrentPhoto() {
    if (!userId || busy) return
    setBusy(true)
    setError('')
    setNotice('')
    setConfirmDelete(false)

    try {
      const blob = await apiClient.getBlob('/profile/photo')
      if (!blob?.size || !IMAGE_TYPES.includes(blob.type)) {
        setError('Belum ada foto yang dapat diatur. Pilih foto terlebih dahulu.')
        return
      }
      const extension = blob.type === 'image/png'
        ? 'png'
        : blob.type === 'image/webp'
          ? 'webp'
          : 'jpg'
      setFile(new File([blob], `foto-sekarang.${extension}`, {
        type: blob.type,
      }))
    } catch (failure) {
      setError(failure?.message || 'Gagal memuat foto untuk diedit.')
    } finally {
      setBusy(false)
    }
  }

  async function openViewer() {
    if (!userId || busy) return
    viewerRequestRef.current += 1
    const currentRequest = viewerRequestRef.current
    setViewer({ loading: true, imageUrl: null, error: '' })
    setError('')

    try {
      const blob = await apiClient.getBlob('/profile/photo')
      if (viewerRequestRef.current !== currentRequest) return

      if (!blob?.size || !blob.type.startsWith('image/')) {
        setViewer({ loading: false, imageUrl: null, error: '' })
        return
      }
      if (viewerUrlRef.current) URL.revokeObjectURL(viewerUrlRef.current)
      const imageUrl = URL.createObjectURL(blob)
      viewerUrlRef.current = imageUrl
      setViewer({ loading: false, imageUrl, error: '' })
    } catch (failure) {
      if (viewerRequestRef.current !== currentRequest) return
      setViewer({
        loading: false,
        imageUrl: null,
        error: failure?.message || 'Gagal menampilkan foto.',
      })
    }
  }

  function closeViewer() {
    viewerRequestRef.current += 1
    setViewer(null)
    if (viewerUrlRef.current) {
      URL.revokeObjectURL(viewerUrlRef.current)
      viewerUrlRef.current = null
    }
  }

  return (
    <section className="siakad-photo-card" aria-label="Pengaturan foto profil">
      <button
        type="button"
        className="siakad-photo-card__avatar"
        onClick={openViewer}
        disabled={!userId || busy}
        title="Klik untuk melihat foto ukuran besar"
        aria-label="Lihat foto profil ukuran besar"
      >
        <ProfilePhotoImage
          userId={userId}
          fallback={initial}
          alt="Foto profil pengguna"
        />
      </button>

      <div className="siakad-photo-card__content">
        <h2>Foto Profil</h2>
        <p>
          Klik foto untuk melihat lebih besar. Gunakan <strong>Pilih / Ganti Foto</strong>
          {' '}untuk mengatur posisi, zoom, dan bagian foto yang terlihat.
          Format JPG, PNG, atau WebP; maksimal 2 MB.
        </p>

        <input
          ref={fileInputRef}
          className="siakad-photo-card__input"
          type="file"
          accept="image/jpeg,image/png,image/webp"
          onChange={chooseFile}
          disabled={!userId || busy}
          aria-label="Pilih foto profil"
        />

        {notice && <p className="siakad-photo-card__success" role="status">{notice}</p>}
        {error && !file && <p className="siakad-photo-card__error" role="alert">{error}</p>}

        {confirmDelete ? (
          <div className="siakad-photo-card__confirm">
            <strong>Hapus foto profil?</strong>
            <p>Foto akan dihapus dan avatar kembali menampilkan inisial nama.</p>
            <div className="siakad-photo-card__buttons">
              <button type="button" onClick={() => setConfirmDelete(false)} disabled={busy}>
                Batal
              </button>
              <button type="button" className="is-danger" onClick={deletePhoto} disabled={busy}>
                {busy ? 'Menghapus...' : 'Ya, Hapus Foto'}
              </button>
            </div>
          </div>
        ) : (
          <div className="siakad-photo-card__buttons">
            <button
              type="button"
              className="is-primary"
              disabled={!userId || busy}
              onClick={() => fileInputRef.current?.click()}
            >
              Pilih / Ganti Foto
            </button>
            <button
              type="button"
              disabled={!userId || busy}
              onClick={editCurrentPhoto}
            >
              Atur Posisi
            </button>
            <button
              type="button"
              disabled={!userId || busy}
              onClick={openViewer}
            >
              Lihat Foto
            </button>
            <button
              type="button"
              className="is-danger-outline"
              disabled={!userId || busy}
              onClick={() => {
                setConfirmDelete(true)
                setError('')
                setNotice('')
              }}
            >
              Hapus Foto
            </button>
          </div>
        )}
      </div>

      {file && (
        <PhotoCropEditor
          file={file}
          busy={busy}
          serverError={error}
          onSave={savePhoto}
          onClose={() => {
            if (!busy) {
              setFile(null)
              setError('')
            }
          }}
        />
      )}

      {viewer && (
        <PhotoViewer
          loading={viewer.loading}
          imageUrl={viewer.imageUrl}
          error={viewer.error}
          onClose={closeViewer}
        />
      )}
    </section>
  )
}
