// frontend/src/components/admin/accounts/ReasonActionModal.jsx
import { useState, useRef } from 'react'
import toast from 'react-hot-toast'
import Modal from '../../common/Modal'
import { closeUserAccount, reactivateUser } from '../../../api/admin'
import useFormErrors from '../../../hooks/useFormErrors'
import FieldError, { FormErrorSummary } from '../../../components/common/FieldError'

const MODES = {
  reactivate: {
    title: 'Reactivate account',
    intro: 'This lifts the current suspension or deactivation and restores full access.',
    points: [
      'The user can sign in and use every feature again.',
      'Their violation records are kept and marked as lifted.',
      'They are notified in-app and by email, with your reason.',
    ],
    minReason: 5,
    button: 'Reactivate account',
    buttonClass: 'bg-green-600 hover:bg-green-700',
  },
  close: {
    title: 'Close account (separate deletion process)',
    intro: 'Closing replaces deletion. The person can no longer sign in, but nothing is erased.',
    points: [
      'They are signed out everywhere and can no longer sign in.',
      'Their profile, listings, rooms, products, reviews and every enforcement record are kept for records and privacy requirements.',
      'The account moves to the "Closed" filter in Manage Users.',
      'They are notified by email.',
    ],
    minReason: 10,
    button: 'Close account',
    buttonClass: 'bg-red-600 hover:bg-red-700',
  },
}

/** Reactivate, or close (the guarded "delete account" step that keeps all data). */
export default function ReasonActionModal({ user, mode, onClose, onDone }) {
  const config = MODES[mode]
  const [reason, setReason] = useState('')
  const [confirmEmail, setConfirmEmail] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  const formRef = useRef(null)
  const form = useFormErrors(formRef, {
    validate: () => ({
      reason: reason.trim().length < config.minReason ? `Write at least ${config.minReason} characters.` : null,
      confirm_email: mode === 'close' && confirmEmail.trim().toLowerCase() !== user.email.toLowerCase()
        ? "Type the account's email address exactly as shown."
        : null,
    }),
  })

  const submit = async (event) => {
    event.preventDefault()
    if (busy || !form.check()) return
    setBusy(true)
    setError(null)
    try {
      const data = mode === 'close'
        ? await closeUserAccount(user.id, { reason: reason.trim(), confirm_email: confirmEmail.trim() })
        : await reactivateUser(user.id, reason.trim())
      toast.success(data.message)
      onDone()
    } catch (err) {
      if (!form.fromResponse(err) && !err.errorDialogShown) setError(err.response?.data?.message || 'The action could not be completed.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      title={config.title}
      subtitle={`${user.name} · ${user.email}`}
      onClose={onClose}
      busy={busy}
      footer={
        <>
          <button type="button" onClick={onClose} disabled={busy} className="btn-secondary">Cancel</button>
          <button
            type="submit"
            form="reason-action-form"
            disabled={busy}
            className={`inline-flex items-center justify-center px-6 py-2.5 rounded-lg font-semibold text-white transition-colors disabled:opacity-50 disabled:cursor-not-allowed ${config.buttonClass}`}
          >
            {busy ? 'Working…' : config.button}
          </button>
        </>
      }
    >
      <form id="reason-action-form" ref={formRef} noValidate onChange={form.onChange} onSubmit={submit} className="space-y-4">
        <FormErrorSummary form={form} />
        <div className="rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-800">
          <p className="font-semibold">{config.intro}</p>
          <ul className="mt-2 list-disc space-y-1 pl-4">
            {config.points.map((point) => <li key={point}>{point}</li>)}
          </ul>
        </div>
        <div>
          <label htmlFor="action-reason" className="block text-sm font-semibold text-gray-800 mb-1.5">Reason (recorded in the activity log and sent to the user)</label>
          <textarea id="action-reason" name="reason" rows={3} className="input-field" maxLength={1000} value={reason} onChange={(e) => setReason(e.target.value)} />
          <FieldError form={form} name="reason" />
          <p className="mt-1 text-xs text-gray-500">At least {config.minReason} characters.</p>
        </div>
        {mode === 'close' && (
          <div>
            <label htmlFor="confirm-email" className="block text-sm font-semibold text-gray-800 mb-1.5">
              Type <span className="font-mono text-red-700">{user.email}</span> to confirm
            </label>
            <input id="confirm-email" name="confirm_email" className="input-field" autoComplete="off" value={confirmEmail} onChange={(e) => setConfirmEmail(e.target.value)} />
            <FieldError form={form} name="confirm_email" />
          </div>
        )}
        {error && <p className="rounded-lg bg-red-50 px-3 py-2 text-sm font-medium text-red-700">{error}</p>}
      </form>
    </Modal>
  )
}
