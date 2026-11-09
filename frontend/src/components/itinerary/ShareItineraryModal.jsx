import { useEffect, useRef, useState } from 'react'
import { FiCheck, FiCopy, FiFileText, FiLink, FiLoader, FiShare2, FiX } from 'react-icons/fi'
import toast from 'react-hot-toast'
import { createShareLink, downloadItineraryPdf, getShareLink, revokeShareLink } from '../../api/itineraries'
import { canShareFile, canShareNatively, copyText, pdfFilename } from '../../utils/itineraryExport'

const canShareFiles = () => canShareNatively() && typeof navigator.canShare === 'function'

const shareUrl = (token) => `${window.location.origin}/shared/itineraries/${token}`

const BUTTON =
  'inline-flex items-center justify-center gap-1.5 px-4 py-2.5 min-h-11 text-sm font-medium rounded-lg transition-colors disabled:opacity-60 disabled:cursor-not-allowed'
const PRIMARY = `${BUTTON} text-white bg-primary-600 hover:bg-primary-700`
const OUTLINE = `${BUTTON} text-primary-600 bg-white border border-primary-200 hover:bg-primary-50`

/**
 * Sharing an itinerary. Nothing is public until the owner creates the link here, and they can
 * stop it here. The online link and the PDF file are offered apart, because only the file works
 * without a connection.
 */
export default function ShareItineraryModal({ itinerary, onClose }) {
  const [token, setToken] = useState(null)
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [copied, setCopied] = useState(false)
  // Prepared ahead: browsers only open the share sheet straight after a tap, not after a long wait.
  const [pdfFile, setPdfFile] = useState(null)
  const [pdfState, setPdfState] = useState(() => (canShareFiles() ? 'checking' : 'unsupported'))
  const closeRef = useRef(null)

  useEffect(() => {
    closeRef.current?.focus()
    const onKey = (event) => event.key === 'Escape' && onClose()
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [onClose])

  useEffect(() => {
    getShareLink(itinerary.id)
      .then((data) => setToken(data.share_token))
      .catch(() => toast.error('Could not check this itinerary’s sharing. Check your connection.'))
      .finally(() => setLoading(false))
  }, [itinerary.id])

  useEffect(() => {
    if (!canShareFiles()) return
    let current = true
    downloadItineraryPdf(itinerary.id)
      .then((blob) => {
        if (!current) return
        const file = new File([blob], pdfFilename(itinerary.title), { type: 'application/pdf' })
        if (canShareFile(file)) {
          setPdfFile(file)
          setPdfState('ready')
        } else {
          setPdfState('unsupported')
        }
      })
      .catch(() => current && setPdfState('failed'))
    return () => {
      current = false
    }
  }, [itinerary.id, itinerary.title])

  const url = token ? shareUrl(token) : ''

  const createLink = async () => {
    setBusy(true)
    try {
      setToken((await createShareLink(itinerary.id)).share_token)
      toast.success('Share link created.')
    } catch {
      toast.error('Could not create the link. Check your connection.')
    } finally {
      setBusy(false)
    }
  }

  const stopSharing = async () => {
    if (!confirm('Stop sharing? The link will stop working for everyone you sent it to.')) return
    setBusy(true)
    try {
      await revokeShareLink(itinerary.id)
      setToken(null)
      setCopied(false)
      toast.success('This itinerary is private again.')
    } catch {
      toast.error('Could not stop sharing. Check your connection.')
    } finally {
      setBusy(false)
    }
  }

  const copyLink = async () => {
    if (await copyText(url)) {
      setCopied(true)
      toast.success('Link copied.')
    } else {
      toast.error('Could not copy automatically. Select the link above and copy it.')
    }
  }

  const share = async (data) => {
    try {
      await navigator.share(data)
    } catch (error) {
      // Closing the share sheet is not a failure.
      if (error.name !== 'AbortError') toast.error('Could not open your device’s sharing options.')
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/50 sm:p-4" onClick={onClose}>
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="share-itinerary-title"
        onClick={(event) => event.stopPropagation()}
        className="w-full sm:max-w-md max-h-[90vh] overflow-y-auto bg-white rounded-t-2xl sm:rounded-2xl p-5 sm:p-6 shadow-xl"
      >
        <div className="flex items-start justify-between gap-3 mb-4">
          <h2 id="share-itinerary-title" className="text-lg font-heading font-bold text-gray-900">Share this itinerary</h2>
          <button ref={closeRef} type="button" onClick={onClose} aria-label="Close" className="p-2 -m-1 rounded-lg text-gray-500 hover:bg-gray-100">
            <FiX size={18} aria-hidden="true" />
          </button>
        </div>

        <section>
          <p className="flex items-center gap-1.5 text-sm font-semibold text-gray-900">
            <FiLink size={14} className="text-primary-600" aria-hidden="true" /> Online link
          </p>
          <p className="text-sm text-gray-600 mt-1">Needs internet to open. Anyone with the link can view the plan but can't change it. Your account and private notes stay hidden.</p>

          {loading ? (
            <p className="flex items-center gap-2 text-sm text-gray-500 mt-3">
              <FiLoader size={14} className="animate-spin" aria-hidden="true" /> Checking…
            </p>
          ) : token ? (
            <div className="mt-3">
              <input
                readOnly
                value={url}
                aria-label="Share link"
                onFocus={(event) => event.target.select()}
                className="w-full text-sm text-gray-700 bg-gray-50 border border-gray-200 rounded-lg px-3 py-2.5"
              />
              <div className="grid grid-cols-2 gap-2 mt-2">
                {canShareNatively() && (
                  <button type="button" onClick={() => share({ title: itinerary.title, text: `My Sorsogon trip plan: ${itinerary.title}`, url })} className={PRIMARY}>
                    <FiShare2 size={15} aria-hidden="true" /> Share link
                  </button>
                )}
                <button type="button" onClick={copyLink} className={canShareNatively() ? OUTLINE : `${PRIMARY} col-span-2`}>
                  {copied ? <FiCheck size={15} aria-hidden="true" /> : <FiCopy size={15} aria-hidden="true" />} {copied ? 'Copied' : 'Copy link'}
                </button>
              </div>
              <button type="button" onClick={stopSharing} disabled={busy} className="mt-2 py-2 text-sm font-medium text-red-600 hover:underline disabled:opacity-60">
                Stop sharing
              </button>
            </div>
          ) : (
            <button type="button" onClick={createLink} disabled={busy} className={`${PRIMARY} w-full mt-3`}>
              {busy ? <FiLoader size={15} className="animate-spin" aria-hidden="true" /> : <FiLink size={15} aria-hidden="true" />} {busy ? 'Creating…' : 'Create share link'}
            </button>
          )}
        </section>

        <section className="mt-5 pt-5 border-t border-gray-100">
          <p className="flex items-center gap-1.5 text-sm font-semibold text-gray-900">
            <FiFileText size={14} className="text-primary-600" aria-hidden="true" /> PDF file
          </p>
          <p className="text-sm text-gray-600 mt-1">The file works offline once the other person has it, and includes your trip notes.</p>
          {pdfState === 'unsupported' ? (
            <p className="text-sm text-gray-500 mt-3">This browser can't send files directly. Use Download PDF, then attach the file in your messaging app.</p>
          ) : pdfState === 'failed' ? (
            <p className="text-sm text-gray-500 mt-3">The PDF could not be prepared. Check your connection, or use Download PDF.</p>
          ) : (
            <button
              type="button"
              onClick={() => share({ title: itinerary.title, files: [pdfFile] })}
              disabled={pdfState !== 'ready'}
              className={`${OUTLINE} w-full mt-3`}
            >
              {pdfState === 'ready' ? <FiShare2 size={15} aria-hidden="true" /> : <FiLoader size={15} className="animate-spin" aria-hidden="true" />}
              {pdfState === 'ready' ? 'Share PDF' : 'Preparing PDF…'}
            </button>
          )}
        </section>
      </div>
    </div>
  )
}
