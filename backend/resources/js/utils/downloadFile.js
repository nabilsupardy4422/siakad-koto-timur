
/**
 * Download Blob sebagai file.
 */
export function downloadFile(
    blob,
    filename = 'download.xlsx'
  ) {
    if (!(blob instanceof Blob)) {
      throw new TypeError(
        'Data download harus berupa Blob.'
      )
    }

    const url = URL.createObjectURL(blob)

    const link = document.createElement('a')

    link.href = url
    link.download = filename

    document.body.appendChild(link)

    link.click()

    link.remove()

    // Beri waktu browser memulai download
    // sebelum URL object dibersihkan.
    setTimeout(() => {
      URL.revokeObjectURL(url)
    }, 1000)
  }
