import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import toast from 'react-hot-toast'
import { FiCalendar, FiDownload, FiExternalLink, FiFileText, FiMapPin, FiTrash2, FiWifiOff } from 'react-icons/fi'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import EmptyState from '../../components/common/EmptyState'
import { useAuth } from '../../contexts/AuthContext'
import { fileSize, listPdfCopies, openPdfCopy, pdfBlobOf, removePdfCopy } from '../../utils/downloadedItineraries'
import { saveBlobAs } from '../../utils/itineraryExport'
import { formatShortDate } from '../../utils/helpers'
import { confirmDialog } from '../../utils/dialogs'

/** "Dec 20 – Dec 22, 2026 · 3 days", from the itinerary's first day and length. */
function tripDates(record) {
  const days = record.numDays ? `${record.numDays} day${record.numDays === 1 ? '' : 's'}` : ''
  if (!record.startDate) return days
  const start = new Date(record.startDate)
  if (Number.isNaN(start.getTime())) return days
  const end = new Date(start)
  end.setDate(end.getDate() + Math.max(0, (record.numDays || 1) - 1))
  const range = record.numDays > 1 ? `${formatShortDate(start)} – ${formatShortDate(end)}` : formatShortDate(start)
  return [range, days].filter(Boolean).join(' · ')
}

/**
 * The tourist's itinerary PDFs downloaded on this device (utils/downloadedItineraries.js). They
 * are read from this browser's storage, so opening one needs no internet; without a connection
 * the same PDFs are listed on the offline page.
 */
export default function DownloadedItineraries() {
  const { user } = useAuth()
  const [records, setRecords] = useState([])
  const [loading, setLoading] = useState(true)
  const [unavailable, setUnavailable] = useState(false)

  const load = useCallback(async () => {
    try {
      setRecords(await listPdfCopies(user.id))
      setUnavailable(false)
    } catch {
      setUnavailable(true)
    } finally {
      setLoading(false)
    }
  }, [user.id])

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load()
  }, [load])

  const open = (record) => {
    if (!openPdfCopy(record)) {
      // The browser refused a new tab (e.g. an in-app browser): hand the file over instead.
      saveBlobAs(pdfBlobOf(record), record.filename)
      toast('Your browser opened the PDF as a download. Find it in your Files app or Downloads.', { duration: 6000 })
    }
  }

  const saveToDevice = (record) => {
    saveBlobAs(pdfBlobOf(record), record.filename)
    toast.success(`Sent to your device as ${record.filename}.`)
  }

  const remove = async (record) => {
    if (!(await confirmDialog({ title: 'Remove this PDF?', message: `"${record.title}" will be removed from My Downloads on this device. The itinerary itself stays in your account.`, confirmLabel: 'Remove', danger: true }))) return
    try {
      await removePdfCopy(user.id, record.itineraryId)
      toast.success('Removed from My Downloads.')
      load()
    } catch {
      toast.error('Could not remove it. Please try again.')
    }
  }

  if (loading) return <LoadingSpinner size="lg" />

  return (
    <div className="bg-gray-50 min-h-screen">
      <div className="max-w-5xl mx-auto px-4 sm:px-6 py-12">
        <div className="mb-8 flex flex-wrap items-end justify-between gap-4">
          <div>
            <p className="text-primary-600 font-medium mb-2">Tourist</p>
            <h1 className="text-3xl sm:text-4xl font-heading font-bold text-gray-900">My Downloads</h1>
            <p className="text-sm text-gray-500 mt-2 max-w-2xl">
              Itinerary PDFs you downloaded on this device. They open without internet, here or on the{' '}
              <a href="/offline.html" className="font-medium text-primary-700 hover:underline">offline page</a> when you have no signal.
            </p>
          </div>
          <Link to="/my-itineraries" className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium text-primary-600 bg-white border border-primary-200 rounded-lg hover:bg-primary-50 transition-colors">
            My Itineraries
          </Link>
        </div>

        {unavailable ? (
          <EmptyState icon={FiWifiOff} title="Downloads can't be read in this browser">
            This browser doesn't allow SOSOGON to keep files (for example in private browsing). PDFs you download are still saved to your device: look in your Files app or your browser's Downloads.
          </EmptyState>
        ) : records.length === 0 ? (
          <EmptyState
            icon={FiDownload}
            title="No downloaded itineraries yet"
            action={<Link to="/my-itineraries" className="btn-primary">Go to My Itineraries</Link>}
          >
            Open one of your itineraries and press Download PDF. It will appear here and open even without internet.
          </EmptyState>
        ) : (
          <ul className="grid grid-cols-1 gap-4 md:grid-cols-2">
            {records.map((record) => (
              <li key={record.key}>
                <article className="flex h-full flex-col rounded-2xl border border-gray-200/70 bg-white p-5 shadow-(--shadow-card)">
                  <div className="flex items-start gap-3">
                    <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-600">
                      <FiFileText size={20} aria-hidden="true" />
                    </span>
                    <div className="min-w-0">
                      <h2 className="font-heading text-lg font-semibold leading-snug text-gray-900 wrap-anywhere">{record.title}</h2>
                      <p className="mt-0.5 text-xs text-gray-500">
                        Downloaded {formatShortDate(record.savedAt)} · {fileSize(record.size)}
                      </p>
                    </div>
                  </div>

                  <dl className="mt-4 mb-5 space-y-1.5 text-sm text-gray-600">
                    {tripDates(record) && (
                      <div className="flex items-start gap-2">
                        <dt className="sr-only">Dates</dt>
                        <FiCalendar size={14} className="mt-0.5 shrink-0 text-gray-400" aria-hidden="true" />
                        <dd>{tripDates(record)}</dd>
                      </div>
                    )}
                    {record.towns?.length > 0 && (
                      <div className="flex items-start gap-2">
                        <dt className="sr-only">Destinations</dt>
                        <FiMapPin size={14} className="mt-0.5 shrink-0 text-gray-400" aria-hidden="true" />
                        <dd>{record.towns.join(', ')}</dd>
                      </div>
                    )}
                  </dl>

                  <div className="mt-auto flex flex-wrap items-center gap-2 border-t border-gray-100 pt-4">
                    <button type="button" onClick={() => open(record)} className="btn-primary min-h-11 flex-1 justify-center sm:flex-none">
                      <FiExternalLink size={15} aria-hidden="true" /> Open PDF
                    </button>
                    <button type="button" onClick={() => saveToDevice(record)} className="inline-flex min-h-11 items-center gap-1.5 rounded-lg border border-primary-200 bg-white px-3 text-sm font-medium text-primary-700 hover:bg-primary-50">
                      <FiDownload size={15} aria-hidden="true" /> Save to device
                    </button>
                    <button type="button" onClick={() => remove(record)} aria-label={`Remove ${record.title}`} className="ml-auto inline-flex min-h-11 items-center gap-1.5 rounded-lg px-3 text-sm font-medium text-gray-500 hover:bg-red-50 hover:text-red-700">
                      <FiTrash2 size={15} aria-hidden="true" /> Remove
                    </button>
                  </div>
                </article>
              </li>
            ))}
          </ul>
        )}

        <p className="mt-6 text-xs text-gray-500">
          Kept in this browser on this device and linked to your account. Logging out or clearing this browser's data removes them; the copies saved to your device's Downloads stay there.
        </p>
      </div>
    </div>
  )
}
