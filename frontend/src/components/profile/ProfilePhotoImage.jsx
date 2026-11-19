
import { useEffect, useRef, useState } from 'react'
import { apiClient } from '../../services/apiClient'

// Event untuk memperbarui foto di seluruh aplikasi
// setelah upload, ganti, atau hapus foto.
export const PROFILE_PHOTO_EVENT =
  'siakad:profile-photo-changed'

export default function ProfilePhotoImage({
  userId,
  fallback = 'U',
  alt = 'Foto profil',
}) {
  const [image, setImage] = useState({
    userId: null,
    url: null,
  })

  const objectUrlRef = useRef(null)

  useEffect(() => {
    let active = true
    let requestSequence = 0

    // ==========================================
    // MEMBERSIHKAN URL FOTO SEBELUMNYA
    // ==========================================

    function clearObjectUrl() {
      if (objectUrlRef.current) {
        URL.revokeObjectURL(objectUrlRef.current)
        objectUrlRef.current = null
      }
    }

    // ==========================================
    // MEMUAT FOTO DARI LARAVEL
    // ==========================================

    async function loadPhoto() {
      const currentRequest = ++requestSequence

      if (!userId) {
        clearObjectUrl()

        setImage({
          userId,
          url: null,
        })

        return
      }

      try {
        // Endpoint sudah dilindungi Sanctum.
        const blob = await apiClient.getBlob(
          '/profile/photo'
        )

        // Abaikan respons yang sudah tidak relevan.
        if (
          !active ||
          currentRequest !== requestSequence
        ) {
          return
        }

        // Respons 204 berarti belum ada foto.
        if (
          blob.size === 0 ||
          !blob.type.startsWith('image/')
        ) {
          clearObjectUrl()

          setImage({
            userId,
            url: null,
          })

          return
        }

        // Buat URL lokal untuk menampilkan foto
        // tanpa membuka akses storage publik.
        const newUrl = URL.createObjectURL(blob)

        clearObjectUrl()

        objectUrlRef.current = newUrl

        setImage({
          userId,
          url: newUrl,
        })
      } catch {
        if (
          !active ||
          currentRequest !== requestSequence
        ) {
          return
        }

        clearObjectUrl()

        setImage({
          userId,
          url: null,
        })
      }
    }

    // Muat foto pertama kali.
    loadPhoto()

    // Perbarui foto setelah ada perubahan.
    window.addEventListener(
      PROFILE_PHOTO_EVENT,
      loadPhoto
    )

    // ==========================================
    // CLEANUP
    // ==========================================

    return () => {
      active = false
      requestSequence += 1

      window.removeEventListener(
        PROFILE_PHOTO_EVENT,
        loadPhoto
      )

      clearObjectUrl()
    }
  }, [userId])

  // Jangan menampilkan foto milik akun sebelumnya
  // ketika identitas pengguna berubah.
  const currentUrl =
    image.userId === userId
      ? image.url
      : null

  return currentUrl ? (
    <img
      src={currentUrl}
      alt={alt}
      draggable="false"
    />
  ) : (
    <span aria-hidden="true">
      {fallback}
    </span>
  )
}
