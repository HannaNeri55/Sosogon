// frontend/src/components/events/AnnouncementModal.jsx
import { useEffect } from 'react'
import { FiBell, FiX } from 'react-icons/fi'
import { getImageUrl } from '../../utils/helpers'

const formatPostedDate = (value) =>
  value ? new Date(value).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' }) : ''

/**
 * Full announcement in a dialog: title, date posted, complete text and its
 * image when one is attached. Close with the X, Escape or a click outside.
 */
export default function AnnouncementModal({ announcement, onClose }) {
  useEffect(() => {
    const onKey = (event) => event.key === 'Escape' && onClose()
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    window.addEventListener('keydown', onKey)
    return () => {
      document.body.style.overflow = previousOverflow
      window.removeEventListener('keydown', onKey)
    }
  }, [onClose])

  return (
    <div
      className="fixed inset-0 z-100 bg-black/60 flex items-end sm:items-center justify-center sm:p-6"
      onClick={onClose}
      role="dialog"
      aria-modal="true"
      aria-labelledby="announcement-title"
    >
      <div
        className="bg-white w-full sm:max-w-2xl max-h-[90vh] overflow-y-auto rounded-t-2xl sm:rounded-2xl shadow-xl"
        onClick={(event) => event.stopPropagation()}
      >
        <div className="sticky top-0 z-10 flex items-center justify-between gap-3 bg-white/95 backdrop-blur border-b border-gray-100 px-5 sm:px-7 py-3">
          <span className="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-secondary-600">
            <FiBell size={14} aria-hidden="true" /> Announcement
          </span>
          <button
            type="button"
            onClick={onClose}
            className="p-2 -mr-2 rounded-full text-gray-500 hover:bg-gray-100 hover:text-gray-800"
            aria-label="Close announcement"
          >
            <FiX size={20} />
          </button>
        </div>

        {announcement.image_path && (
          <img
            src={getImageUrl(announcement.image_path)}
            alt=""
            className="w-full max-h-80 object-cover bg-gray-100"
          />
        )}

        <div className="px-5 sm:px-7 py-6">
          <div className="flex flex-wrap items-center gap-2 mb-3">
            {announcement.is_pinned && (
              <span className="text-xs font-semibold bg-accent-100 text-accent-700 px-2.5 py-1 rounded-full">Pinned</span>
            )}
            {announcement.created_at && (
              <time className="text-sm text-gray-500" dateTime={announcement.created_at}>
                Posted {formatPostedDate(announcement.created_at)}
              </time>
            )}
          </div>
          <h2 id="announcement-title" className="text-2xl sm:text-3xl font-heading font-bold text-gray-900 leading-tight">
            {announcement.title}
          </h2>
          <p className="mt-4 text-gray-700 leading-relaxed whitespace-pre-line">{announcement.content}</p>
        </div>
      </div>
    </div>
  )
}
