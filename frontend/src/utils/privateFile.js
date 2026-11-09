// frontend/src/utils/privateFile.js
import toast from 'react-hot-toast'

const PREVIEWABLE = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp']

/**
 * Open a private file (evidence, appeal attachment) that can only be downloaded with the
 * user's login token. The tab is opened during the click so pop-up blockers allow it;
 * PDFs and images preview there, anything else downloads.
 */
export async function openPrivateFile(fetchBlob, filename = 'attachment') {
  const tab = window.open('', '_blank')
  if (tab) tab.opener = null
  try {
    const blob = await fetchBlob()
    const url = URL.createObjectURL(blob)
    if (tab && PREVIEWABLE.includes(blob.type)) {
      tab.location.href = url
    } else {
      tab?.close()
      const link = document.createElement('a')
      link.href = url
      link.download = filename
      link.click()
    }
    setTimeout(() => URL.revokeObjectURL(url), 60_000)
  } catch (error) {
    tab?.close()
    toast.error(error.response?.status === 404 ? 'That file is no longer available.' : 'Could not open the file. Please try again.')
  }
}
