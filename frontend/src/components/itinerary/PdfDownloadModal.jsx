import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import toast from 'react-hot-toast'
import { FiAlertTriangle, FiCheckCircle, FiCopy, FiDownload, FiExternalLink, FiLoader } from 'react-icons/fi'
import Modal from '../common/Modal'
import { getItineraryPdfStatus, preparedPdfUrl } from '../../api/itineraries'
import { openPdfCopy } from '../../utils/downloadedItineraries'
import { chromeIntentUrl, copyText, isAndroid } from '../../utils/itineraryExport'

// How often the page asks whether the browser has fetched the file, and how long it waits before
// suggesting another try.
const POLL_MS = 1500
const WAIT_MS = 15000

const LINK = 'inline-flex min-h-11 items-center justify-center gap-1.5 rounded-lg px-4 text-sm font-medium'
const PRIMARY = `${LINK} bg-primary-600 text-white hover:bg-primary-700`
const OUTLINE = `${LINK} border border-primary-200 bg-white text-primary-700 hover:bg-primary-50`

function Status({ tone, icon: Icon, title, children }) {
  const tones = {
    success: 'border-green-200 bg-green-50 text-green-800',
    waiting: 'border-gray-200 bg-gray-50 text-gray-700',
    warning: 'border-amber-200 bg-amber-50 text-amber-900',
  }
  return (
    <div className={`rounded-xl border p-4 ${tones[tone]}`} role="status">
      <p className="flex items-center gap-2 font-semibold">
        <Icon size={18} className={`shrink-0 ${Icon === FiLoader ? 'animate-spin' : ''}`} aria-hidden="true" />
        {title}
      </p>
      <div className="mt-1.5 space-y-2 text-sm leading-relaxed">{children}</div>
    </div>
  )
}

/**
 * After Download PDF: follows the real download to the device and offers Open PDF, which only
 * shows the file. "Download started" appears once the browser has fetched the file (the server
 * reports it), never before. Built-in app browsers such as Messenger's often can't save files, so
 * there the download is handed to Chrome, or the link can be copied into another browser.
 *
 * `download` is { ticket, filename, expires_at, inApp } from Download PDF; `copy` is the copy kept
 * in My Downloads: { state: 'saving' | 'saved' | 'failed', record }.
 */
export default function PdfDownloadModal({ itineraryId, download, copy, onClose }) {
  const { ticket, filename, inApp } = download
  const downloadUrl = preparedPdfUrl(ticket, 'download')
  const viewUrl = preparedPdfUrl(ticket, 'view')
  const android = isAndroid()
  const navigate = useNavigate()

  // Each attempt (the automatic one, or a tap) restarts the wait. In an app's browser nothing
  // starts until the tourist picks a way.
  const [attempt, setAttempt] = useState(inApp ? 0 : 1)
  const [started, setStarted] = useState(false)
  const [stalled, setStalled] = useState(false)
  const [expired, setExpired] = useState(false)

  useEffect(() => {
    const timer = setTimeout(() => setExpired(true), Math.max(0, new Date(download.expires_at) - Date.now()))
    return () => clearTimeout(timer)
  }, [download.expires_at])

  useEffect(() => {
    if (attempt === 0 || started || expired) return
    let active = true
    const deadline = Date.now() + WAIT_MS
    const timer = setInterval(async () => {
      try {
        const status = await getItineraryPdfStatus(itineraryId, ticket)
        if (active && status.started) setStarted(true)
      } catch {
        // Asked again on the next tick.
      }
      // Still watched afterwards: the browser may be asking whether to allow the download.
      if (active && Date.now() > deadline) setStalled(true)
    }, POLL_MS)
    return () => {
      active = false
      clearInterval(timer)
    }
  }, [attempt, started, expired, itineraryId, ticket])

  // The links below start the download themselves; this only restarts the wait.
  const watchAgain = () => {
    setStalled(false)
    setAttempt((n) => n + 1)
  }

  const copyLink = async () => {
    if (await copyText(downloadUrl)) {
      toast.success('Download link copied. Paste it in Chrome or Safari within 10 minutes.')
      watchAgain()
    } else {
      toast.error('Could not copy the link.')
    }
  }

  // Open PDF shows the file in the browser's or phone's PDF viewer; it is not the download.
  const openPdf = () => {
    const win = expired ? null : window.open(viewUrl, '_blank')
    if (win) {
      win.opener = null
      return
    }
    if (copy.record && openPdfCopy(copy.record)) return
    toast.error(expired ? 'This link has expired. Close this and press Download PDF again.' : 'Your browser blocked the new tab. Allow pop-ups, or open the file from your Downloads.')
  }

  const chromeButton = android && inApp && (
    <a href={chromeIntentUrl(downloadUrl)} onClick={watchAgain} className={PRIMARY}>
      <FiExternalLink size={15} aria-hidden="true" /> Open in Chrome
    </a>
  )

  let status
  if (started) {
    status = (
      <Status tone="success" icon={FiCheckCircle} title="Download started">
        {inApp ? (
          <p>
            The file was sent. If Chrome opened, it is saving <strong className="wrap-anywhere">{filename}</strong> to your phone's Downloads. If you are still in {inApp}'s browser and the file isn't in <strong>Files › Downloads</strong>, use Open in Chrome.
          </p>
        ) : (
          <>
            <p>
              Your browser received <strong className="wrap-anywhere">{filename}</strong> and is saving it to your device. If it asks, choose Download. Then find it in <strong>Files (My Files) › Downloads</strong> on your phone, or in your browser's downloads list on a computer.
            </p>
            <p>It's an ordinary PDF file: it opens in any PDF reader, without internet and without SOSOGON.</p>
          </>
        )}
      </Status>
    )
  } else if (expired) {
    status = (
      <Status tone="warning" icon={FiAlertTriangle} title="This download link has expired">
        <p>For your privacy, download links work for 10 minutes. Close this and press Download PDF again.</p>
      </Status>
    )
  } else if (attempt === 0) {
    status = (
      <Status tone="warning" icon={FiAlertTriangle} title={`${inApp}'s browser can't save files`}>
        {android ? (
          <p>Open the download in Chrome: Chrome saves the PDF to your phone's Downloads, and you don't need to sign in again. The link works for 10 minutes.</p>
        ) : (
          <p>Tap the <strong>•••</strong> or share button and choose <strong>Open in browser</strong> (Safari), then press Download PDF again. Or copy the download link and paste it into Safari within 10 minutes.</p>
        )}
      </Status>
    )
  } else if (stalled) {
    status = (
      <Status tone="warning" icon={FiAlertTriangle} title="The download hasn't started yet">
        <p>Your browser may have blocked a download the page started by itself, or be asking you to allow it. Tap Download again.</p>
      </Status>
    )
  } else {
    status = (
      <Status tone="waiting" icon={FiLoader} title="Starting the download…">
        <p className="wrap-anywhere">{filename}</p>
      </Status>
    )
  }

  const retry = !started && !expired && (attempt === 0 || stalled)

  return (
    <Modal
      title="Download PDF"
      subtitle="Saved to your device, so it opens without internet."
      onClose={onClose}
      footer={
        <>
          <button type="button" onClick={openPdf} className={`${OUTLINE} flex-1 sm:flex-none`}>
            <FiExternalLink size={15} aria-hidden="true" /> Open PDF
          </button>
          <button type="button" onClick={onClose} className={`${PRIMARY} flex-1 sm:flex-none`}>
            Done
          </button>
        </>
      }
    >
      <div className="space-y-4">
        {status}

        {retry && (
          <div className="flex flex-wrap gap-2 [&>*]:flex-1 sm:[&>*]:flex-none">
            {chromeButton}
            <a href={downloadUrl} onClick={watchAgain} className={inApp && android ? OUTLINE : PRIMARY}>
              <FiDownload size={15} aria-hidden="true" /> {attempt === 0 ? 'Try downloading here' : 'Download again'}
            </a>
            {inApp && (
              <button type="button" onClick={copyLink} className={OUTLINE}>
                <FiCopy size={15} aria-hidden="true" /> Copy download link
              </button>
            )}
          </div>
        )}

        {/* The server only knows the browser received the file, not that it was saved. */}
        {started && !expired && (
          <p className="text-sm text-gray-600">
            No file in your Downloads?{' '}
            <a href={downloadUrl} className="font-medium text-primary-700 hover:underline">Download again</a>
            {inApp && android && (
              <>
                {' '}or{' '}
                <a href={chromeIntentUrl(downloadUrl)} className="font-medium text-primary-700 hover:underline">open it in Chrome</a>
              </>
            )}
            .
          </p>
        )}

        <p className="text-xs leading-relaxed text-gray-500">
          {copy.state === 'saving' && 'Also keeping a copy in My Downloads on this browser…'}
          {copy.state === 'saved' && (
            <>
              A copy is also kept in SOSOGON's{' '}
              <button
                type="button"
                className="font-medium text-primary-700 hover:underline"
                onClick={() => {
                  onClose()
                  navigate('/my-downloads')
                }}
              >
                My Downloads
              </button>{' '}
              on this browser.
            </>
          )}
          {copy.state === 'failed' && "This browser couldn't keep a copy in My Downloads (private browsing or no free space). The file saved to your device isn't affected."}
        </p>
      </div>
    </Modal>
  )
}
