// frontend/src/components/common/Modal.jsx
import { useEffect, useId } from 'react'
import { FiX } from 'react-icons/fi'

const WIDTHS = { sm: 'sm:max-w-md', md: 'sm:max-w-xl', lg: 'sm:max-w-2xl', xl: 'sm:max-w-4xl' }

/**
 * Standard dialog: title bar with close button, scrollable body, optional footer.
 * Escape and a click outside close it (unless `busy`). Bottom sheet on phones.
 */
export default function Modal({ title, subtitle, onClose, footer, size = 'md', busy = false, children }) {
  const titleId = useId()

  useEffect(() => {
    const onKey = (event) => event.key === 'Escape' && !busy && onClose()
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    window.addEventListener('keydown', onKey)
    return () => {
      document.body.style.overflow = previousOverflow
      window.removeEventListener('keydown', onKey)
    }
  }, [onClose, busy])

  return (
    <div
      className="fixed inset-0 z-100 bg-black/50 flex items-end sm:items-center justify-center sm:p-6"
      onClick={() => !busy && onClose()}
      role="dialog"
      aria-modal="true"
      aria-labelledby={titleId}
    >
      <div
        className={`bg-white w-full ${WIDTHS[size]} max-h-[92vh] flex flex-col rounded-t-2xl sm:rounded-2xl shadow-xl`}
        onClick={(event) => event.stopPropagation()}
      >
        <div className="flex items-start justify-between gap-4 border-b border-gray-100 px-5 sm:px-6 py-4">
          <div className="min-w-0">
            <h2 id={titleId} className="font-heading text-lg font-bold text-gray-900">{title}</h2>
            {subtitle && <p className="mt-0.5 text-sm text-gray-500">{subtitle}</p>}
          </div>
          <button
            type="button"
            onClick={onClose}
            disabled={busy}
            className="-mr-2 p-2 rounded-full text-gray-500 hover:bg-gray-100 hover:text-gray-800 disabled:opacity-50"
            aria-label="Close"
          >
            <FiX size={20} />
          </button>
        </div>
        <div className="flex-1 overflow-y-auto px-5 sm:px-6 py-5">{children}</div>
        {footer && <div className="flex flex-wrap justify-end gap-3 border-t border-gray-100 px-5 sm:px-6 py-4">{footer}</div>}
      </div>
    </div>
  )
}
