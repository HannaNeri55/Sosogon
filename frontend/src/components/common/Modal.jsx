// frontend/src/components/common/Modal.jsx
import { useEffect, useId } from 'react'
import { FiX } from 'react-icons/fi'

const WIDTHS = { sm: 'sm:max-w-md', md: 'sm:max-w-xl', lg: 'sm:max-w-2xl', xl: 'sm:max-w-4xl' }

// Open modals, newest last. Escape closes only the top one, e.g. an error dialog shown over a form.
const openModals = []

/**
 * Standard dialog: title bar with close button, scrollable body, optional footer.
 * Escape and a click outside close it (unless `busy`). Bottom sheet on phones.
 *
 * `variant="alert"` is the short centered message used for errors and confirmations: an
 * optional `icon` in a red circle, the title, the message, and full-width buttons.
 */
export default function Modal({ title, subtitle, onClose, footer, size = 'md', busy = false, variant, icon: Icon, children }) {
  const titleId = useId()

  useEffect(() => {
    openModals.push(titleId)
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => {
      openModals.splice(openModals.indexOf(titleId), 1)
      document.body.style.overflow = previousOverflow
    }
  }, [titleId])

  // Capture phase, so the top modal sees Escape before panels and menus underneath it that
  // also close on Escape, and can keep the key from reaching them.
  useEffect(() => {
    const onKey = (event) => {
      if (event.key !== 'Escape' || openModals.at(-1) !== titleId) return
      event.stopPropagation()
      if (!busy) onClose()
    }
    window.addEventListener('keydown', onKey, true)
    return () => window.removeEventListener('keydown', onKey, true)
  }, [onClose, busy, titleId])

  if (variant === 'alert') {
    return (
      <div
        className="fixed inset-0 z-100 bg-black/50 flex items-center justify-center p-4"
        onClick={() => !busy && onClose()}
        role="alertdialog"
        aria-modal="true"
        aria-labelledby={titleId}
      >
        <div
          className="bg-white w-full max-w-sm rounded-2xl shadow-xl px-6 py-7 text-center"
          onClick={(event) => event.stopPropagation()}
        >
          {Icon && (
            <span className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-red-50 text-red-600">
              <Icon size={22} aria-hidden="true" />
            </span>
          )}
          <h2 id={titleId} className="font-heading text-xl font-bold text-gray-900">{title}</h2>
          <div className="mt-2 text-sm leading-relaxed text-gray-600">{children}</div>
          {footer && <div className="mt-6 flex flex-col-reverse gap-2 sm:flex-row [&>*]:flex-1 [&>*]:justify-center">{footer}</div>}
        </div>
      </div>
    )
  }

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
