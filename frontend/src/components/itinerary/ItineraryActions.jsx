import { useEffect, useRef, useState } from 'react'
import { FiBookmark, FiCheck, FiDownload, FiLoader, FiPrinter, FiShare2 } from 'react-icons/fi'
import toast from 'react-hot-toast'
import { downloadItineraryPdf, getItineraryDocument, saveItinerary } from '../../api/itineraries'
import { hasOfflineCopy, keepOfflineCopy } from '../../utils/offlineItineraries'
import { openPrintWindow, pdfFilename, printDocument, saveBlobAs } from '../../utils/itineraryExport'
import ShareItineraryModal from './ShareItineraryModal'

const BUTTON =
  'inline-flex items-center justify-center gap-1.5 px-3 sm:px-4 py-2.5 min-h-11 text-sm font-medium rounded-lg transition-colors disabled:opacity-60 disabled:cursor-not-allowed'
const OUTLINE = `${BUTTON} text-primary-600 bg-white border border-primary-200 hover:bg-primary-50`

const failure = (error, fallback) =>
  error.response ? error.response.data?.message || fallback : 'No connection right now. Try again when you have signal.'

/** Save, Download PDF, Print and Share for one of the tourist's itineraries. */
export default function ItineraryActions({ itinerary, onSaved }) {
  // Which action is running. The ref stops a second click before the buttons have disabled.
  const [busy, setBusy] = useState(null)
  const running = useRef(false)
  const [offlineReady, setOfflineReady] = useState(false)
  const [shareOpen, setShareOpen] = useState(false)

  useEffect(() => {
    let current = true
    hasOfflineCopy(itinerary.id).then((has) => current && setOfflineReady(has))
    return () => {
      current = false
    }
  }, [itinerary.id])

  const once = async (name, action) => {
    if (running.current) return
    running.current = true
    setBusy(name)
    try {
      await action()
    } finally {
      running.current = false
      setBusy(null)
    }
  }

  const handleSave = () =>
    once('save', async () => {
      let savedAt
      try {
        savedAt = (await saveItinerary(itinerary.id)).saved_at
      } catch (error) {
        toast.error(failure(error, 'Could not save this itinerary.'))
        return
      }
      onSaved(savedAt)
      try {
        await keepOfflineCopy(itinerary)
        setOfflineReady(true)
        toast.success('Saved to your account and to this device for offline use.')
      } catch {
        toast.error('Saved to your account, but no offline copy could be kept. Download the PDF to have it offline.')
      }
    })

  const handleDownload = () =>
    once('pdf', async () => {
      try {
        saveBlobAs(await downloadItineraryPdf(itinerary.id), pdfFilename(itinerary.title))
        toast.success('PDF downloaded. It opens without internet.')
      } catch (error) {
        toast.error(failure(error, 'Could not create the PDF.'))
      }
    })

  const handlePrint = () => {
    if (running.current) return
    const win = openPrintWindow()
    if (!win) {
      toast.error('Your browser blocked the print window. Allow pop-ups, or use Download PDF.')
      return
    }
    once('print', async () => {
      try {
        const { html } = await getItineraryDocument(itinerary.id)
        await printDocument(win, html)
      } catch (error) {
        win.close()
        toast.error(failure(error, 'Could not prepare the itinerary for printing.'))
      }
    })
  }

  const icon = (name, Icon) => (busy === name ? <FiLoader size={15} className="animate-spin" aria-hidden="true" /> : <Icon size={15} aria-hidden="true" />)
  const saved = Boolean(itinerary.saved_at)
  const saveLabel = busy === 'save' ? 'Saving…' : saved && offlineReady ? 'Saved' : saved ? 'Save for Offline' : 'Save Itinerary'

  return (
    <div className="mt-6 pt-6 border-t border-gray-100">
      <div className="grid grid-cols-2 sm:flex sm:flex-wrap gap-2">
        <button
          type="button"
          onClick={handleSave}
          disabled={busy !== null}
          title={saved && offlineReady ? 'Saved. Press to update the copy on this device.' : undefined}
          className={saved && offlineReady ? OUTLINE : `${BUTTON} text-white bg-primary-600 hover:bg-primary-700`}
        >
          {saved && offlineReady && busy !== 'save' ? <FiCheck size={15} aria-hidden="true" /> : icon('save', FiBookmark)} {saveLabel}
        </button>
        <button type="button" onClick={handleDownload} disabled={busy !== null} className={OUTLINE}>
          {icon('pdf', FiDownload)} {busy === 'pdf' ? 'Preparing…' : 'Download PDF'}
        </button>
        <button type="button" onClick={handlePrint} disabled={busy !== null} className={OUTLINE}>
          {icon('print', FiPrinter)} {busy === 'print' ? 'Preparing…' : 'Print'}
        </button>
        <button type="button" onClick={() => setShareOpen(true)} disabled={busy !== null} className={OUTLINE}>
          <FiShare2 size={15} aria-hidden="true" /> Share
        </button>
      </div>
      <p className="mt-2.5 text-xs text-gray-500">
        Going somewhere with weak signal? Download the PDF or save it for offline. A share link needs internet to open.
      </p>

      {shareOpen && <ShareItineraryModal itinerary={itinerary} onClose={() => setShareOpen(false)} />}
    </div>
  )
}
