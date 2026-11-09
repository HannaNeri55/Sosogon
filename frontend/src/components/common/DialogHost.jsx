import { useSyncExternalStore } from 'react'
import { FiAlertTriangle, FiClock, FiLock, FiLogIn, FiServer, FiTrash2, FiWifiOff } from 'react-icons/fi'
import Modal from './Modal'
import { answerConfirm, closeErrorDialog, getDialogs, subscribeDialogs } from '../../utils/dialogs'

const ICONS = {
  connection: FiWifiOff,
  server: FiServer,
  unavailable: FiServer,
  'rate-limit': FiClock,
  forbidden: FiLock,
  session: FiLogIn,
}

function isTourist() {
  try {
    return JSON.parse(sessionStorage.getItem('user'))?.role === 'tourist'
  } catch {
    return false
  }
}

function ErrorDialog({ dialog }) {
  const Icon = ICONS[dialog.kind] || FiAlertTriangle
  const retryable = dialog.waiters.some((waiter) => waiter.retryable)
  const close = () => closeErrorDialog('close')

  return (
    <Modal
      variant="alert"
      icon={Icon}
      title={dialog.title}
      onClose={close}
      footer={retryable ? (
        <>
          <button type="button" className="btn-secondary" onClick={close}>Close</button>
          <button type="button" className="btn-primary" onClick={() => closeErrorDialog('retry')} autoFocus>Try again</button>
        </>
      ) : (
        <button type="button" className="btn-primary" onClick={close} autoFocus>{dialog.confirmLabel || 'OK'}</button>
      )}
    >
      <p className="whitespace-pre-line">{dialog.message}</p>
      {dialog.kind === 'connection' && isTourist() && (
        <p className="mt-3">
          Itineraries you saved for offline use can still be opened from{' '}
          <a href="/offline.html" className="font-medium text-primary-700 hover:underline">Offline Copies</a>.
        </p>
      )}
    </Modal>
  )
}

function ConfirmDialog({ dialog }) {
  const cancel = () => answerConfirm(false)

  return (
    <Modal
      variant="alert"
      icon={dialog.danger ? FiTrash2 : undefined}
      title={dialog.title}
      onClose={cancel}
      footer={(
        <>
          <button type="button" className="btn-secondary" onClick={cancel}>{dialog.cancelLabel}</button>
          <button
            type="button"
            onClick={() => answerConfirm(true)}
            autoFocus
            className={dialog.danger
              ? 'inline-flex items-center justify-center gap-2 rounded-lg bg-red-600 px-6 py-2.5 font-semibold text-white transition-colors hover:bg-red-700'
              : 'btn-primary'}
          >
            {dialog.confirmLabel}
          </button>
        </>
      )}
    >
      {dialog.message && <p className="whitespace-pre-line">{dialog.message}</p>}
    </Modal>
  )
}

/** Renders the global error and confirm dialogs (see utils/dialogs.js). Mounted once, in main.jsx. */
export default function DialogHost() {
  const { errors, confirm } = useSyncExternalStore(subscribeDialogs, getDialogs)

  return (
    <>
      {confirm && <ConfirmDialog dialog={confirm} />}
      {/* After the confirm dialog, so a failure while one is open shows on top of it. */}
      {errors[0] && <ErrorDialog key={errors[0].id} dialog={errors[0]} />}
    </>
  )
}
