// frontend/src/pages/account/AccountStatus.jsx
import { useCallback, useEffect, useState, useRef } from 'react'
import { useNavigate } from 'react-router-dom'
import toast from 'react-hot-toast'
import {
  FiAlertTriangle, FiBell, FiCheckCircle, FiClock, FiLogOut, FiPaperclip, FiShield, FiSlash, FiX,
} from 'react-icons/fi'
import { useAuth } from '../../contexts/AuthContext'
import {
  downloadMyAppealAttachment, getAccountStatus, markAccountNotificationsRead, submitAccountAppeal,
} from '../../api/account'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import Modal from '../../components/common/Modal'
import PageHeader from '../../components/layout/PageHeader'
import StatusPill from '../../components/account/StatusPill'
import { openPrivateFile } from '../../utils/privateFile'
import {
  APPEAL_STATUSES, ENFORCEMENT_ACTIONS, VIOLATION_STATUSES, formatDateTime, formatDay,
} from '../../utils/accountStates'
import useFormErrors from '../../hooks/useFormErrors'
import FieldError, { FormErrorSummary } from '../../components/common/FieldError'

const FILE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf']

/** Plain-language summary of the account's current state. */
const STATE_COPY = {
  active: {
    icon: FiCheckCircle, tone: 'border-green-200 bg-green-50', iconTone: 'text-green-600',
    title: 'Your account is in good standing',
    text: 'There are no restrictions on your account. Thank you for helping keep SOSOGON a trustworthy place for travellers.',
  },
  warning: {
    icon: FiAlertTriangle, tone: 'border-amber-200 bg-amber-50', iconTone: 'text-amber-600',
    title: 'Your account has an active warning',
    text: 'You can keep using SOSOGON normally. Please review the warning below; repeated violations can lead to a suspension.',
  },
  suspended: {
    icon: FiClock, tone: 'border-orange-200 bg-orange-50', iconTone: 'text-orange-600',
    title: 'Your account is temporarily suspended',
    text: 'While suspended you can only view this page and submit an appeal. Access is restored automatically when the suspension ends.',
  },
  deactivated: {
    icon: FiSlash, tone: 'border-red-200 bg-red-50', iconTone: 'text-red-600',
    title: 'Your account is deactivated',
    text: 'Your account is restricted until the Sorsogon Tourism Office reactivates it. You can view this page and submit an appeal.',
  },
  pending: {
    icon: FiClock, tone: 'border-sky-200 bg-sky-50', iconTone: 'text-sky-600',
    title: 'Your registration is under review',
    text: 'The Sorsogon Tourism Office is reviewing your business owner registration.',
  },
}

function AppealModal({ violation, onClose, onDone }) {
  const [explanation, setExplanation] = useState('')
  const [files, setFiles] = useState([])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const formRef = useRef(null)
  const form = useFormErrors(formRef, {
    validate: () => ({
      explanation: explanation.trim().length < 30 ? 'Please explain your appeal in at least 30 characters.' : null,
    }),
  })

  const addFiles = (list) => {
    const next = [...files]
    let problem
    for (const file of list) {
      if (!FILE_TYPES.includes(file.type)) problem = `${file.name}: only JPG, PNG, WebP or PDF files can be attached.`
      else if (file.size > 5 * 1024 * 1024) problem = `${file.name} is larger than 5 MB.`
      else if (next.length >= 3) { problem = 'You can attach up to 3 files.'; break }
      else next.push(file)
    }
    setFiles(next)
    if (problem) form.setErrors({ attachments: problem })
    else form.clear('attachments')
  }

  const submit = async (event) => {
    event.preventDefault()
    if (!form.check()) return
    const payload = new FormData()
    payload.append('explanation', explanation.trim())
    files.forEach((file) => payload.append('attachments[]', file))
    setBusy(true)
    setError(null)
    try {
      const data = await submitAccountAppeal(violation.id, payload)
      toast.success(data.message)
      onDone()
    } catch (err) {
      if (!form.fromResponse(err) && !err.errorDialogShown) setError(err.response?.data?.message || 'Your appeal could not be submitted.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      title="Submit an appeal"
      subtitle={`${ENFORCEMENT_ACTIONS[violation.action]?.label} · ${violation.category_label}`}
      onClose={onClose}
      busy={busy}
      footer={
        <>
          <button type="button" onClick={onClose} disabled={busy} className="btn-secondary">Cancel</button>
          <button type="submit" form="appeal-form" disabled={busy} className="btn-primary">{busy ? 'Submitting…' : 'Submit appeal'}</button>
        </>
      }
    >
      <form id="appeal-form" ref={formRef} noValidate onChange={form.onChange} onSubmit={submit} className="space-y-4">
        <FormErrorSummary form={form} />
        <div className="rounded-xl bg-gray-50 border border-gray-200 p-4 text-sm text-gray-700">
          <p><span className="font-semibold">Reason given:</span> {violation.reason}</p>
          <p className="mt-2">You can appeal each decision once. An administrator will review your explanation and any files you attach, and you will be notified of the decision.</p>
        </div>
        <div>
          <label htmlFor="appeal-explanation" className="block text-sm font-semibold text-gray-800 mb-1.5">Why do you believe this decision is incorrect?</label>
          <textarea id="appeal-explanation" name="explanation" rows={6} maxLength={5000} className="input-field" value={explanation} onChange={(e) => setExplanation(e.target.value)} />
          <FieldError form={form} name="explanation" />
          <p className="mt-1 text-xs text-gray-500">{explanation.trim().length}/5000 · at least 30 characters</p>
        </div>
        <div>
          <label className="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-dashed border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:border-primary-400 hover:text-primary-700">
            <FiPaperclip size={15} aria-hidden="true" /> Attach supporting files (optional, up to 3)
            <input type="file" multiple accept="image/jpeg,image/png,image/webp,application/pdf" className="sr-only" onChange={(e) => { addFiles(Array.from(e.target.files || [])); e.target.value = '' }} />
          </label>
          <FieldError form={form} name="attachments" />
          <p className="mt-1 text-xs text-gray-500">JPG, PNG, WebP or PDF, up to 5 MB each.</p>
          {files.length > 0 && (
            <ul className="mt-2 space-y-1">
              {files.map((file, index) => (
                <li key={`${file.name}-${index}`} className="flex items-center justify-between gap-2 rounded-lg bg-gray-50 px-3 py-1.5 text-sm">
                  <span className="truncate">{file.name}</span>
                  <button type="button" onClick={() => setFiles(files.filter((_, i) => i !== index))} className="p-2 text-gray-400 hover:text-red-600" aria-label={`Remove ${file.name}`}>
                    <FiX size={14} />
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
        {error && <p className="rounded-lg bg-red-50 px-3 py-2 text-sm font-medium text-red-700">{error}</p>}
      </form>
    </Modal>
  )
}

function ViolationItem({ violation, onAppeal }) {
  const appeal = violation.appeal

  return (
    <article className="bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) p-5 sm:p-6">
      <div className="flex flex-wrap items-center gap-2">
        <StatusPill value={violation.action} map={ENFORCEMENT_ACTIONS} />
        <StatusPill value={violation.is_current ? 'active' : violation.status === 'active' ? 'expired' : violation.status} map={VIOLATION_STATUSES} />
        <span className="ml-auto text-xs text-gray-500">Issued {formatDateTime(violation.starts_at)}</span>
      </div>
      <h3 className="mt-3 font-heading text-lg font-bold text-gray-900">{violation.category_label}</h3>
      <p className="mt-1 text-sm text-gray-800"><span className="font-semibold">Reason:</span> {violation.reason}</p>
      <p className="mt-1 text-sm text-gray-600 whitespace-pre-line">{violation.details}</p>

      <dl className="mt-3 grid grid-cols-1 gap-x-6 gap-y-1 text-sm text-gray-600 sm:grid-cols-2">
        {violation.action === 'suspension' && (
          <>
            <div><dt className="inline font-semibold text-gray-700">Duration: </dt><dd className="inline">{violation.suspension_days} day{violation.suspension_days === 1 ? '' : 's'}</dd></div>
            <div><dt className="inline font-semibold text-gray-700">Ends: </dt><dd className="inline">{formatDateTime(violation.ends_at)}</dd></div>
          </>
        )}
        {violation.action === 'warning' && violation.ends_at && (
          <div><dt className="inline font-semibold text-gray-700">Warning shown until: </dt><dd className="inline">{formatDay(violation.ends_at)}</dd></div>
        )}
        {violation.review_date && <div><dt className="inline font-semibold text-gray-700">Review date: </dt><dd className="inline">{formatDay(violation.review_date)}</dd></div>}
        {violation.resolution_note && (
          <div className="sm:col-span-2"><dt className="inline font-semibold text-gray-700">Outcome: </dt><dd className="inline">{violation.resolution_note}</dd></div>
        )}
      </dl>

      {appeal ? (
        <div className="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm">
          <div className="flex flex-wrap items-center gap-2">
            <StatusPill value={appeal.status} map={APPEAL_STATUSES} />
            <span className="text-xs text-gray-500">Submitted {formatDateTime(appeal.submitted_at)}</span>
          </div>
          <p className="mt-2 text-gray-700 whitespace-pre-line">{appeal.explanation}</p>
          {appeal.attachments?.length > 0 && (
            <div className="mt-2 flex flex-wrap gap-2">
              {appeal.attachments.map((file) => (
                <button
                  key={file.index}
                  type="button"
                  onClick={() => openPrivateFile(() => downloadMyAppealAttachment(appeal.id, file.index), file.name)}
                  className="inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-medium text-primary-700"
                >
                  <FiPaperclip size={12} aria-hidden="true" /> {file.name}
                </button>
              ))}
            </div>
          )}
          {appeal.decision_note && (
            <div className="mt-3 border-t border-gray-200 pt-3">
              <p className="font-semibold text-gray-900">Decision from the Sorsogon Tourism Office</p>
              <p className="mt-1 text-gray-700 whitespace-pre-line">{appeal.decision_note}</p>
              <p className="mt-1 text-xs text-gray-500">{formatDateTime(appeal.decided_at)}</p>
            </div>
          )}
          {appeal.status === 'pending' && <p className="mt-2 text-xs text-gray-500">An administrator will review your appeal. You will be notified of the decision.</p>}
        </div>
      ) : violation.can_appeal ? (
        <div className="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-dashed border-gray-300 px-4 py-3">
          <p className="text-sm text-gray-600">Think this decision is wrong? You can appeal it once.</p>
          <button type="button" onClick={() => onAppeal(violation)} className="btn-secondary px-4 py-2 text-sm">Submit appeal</button>
        </div>
      ) : null}
    </article>
  )
}

/**
 * Account Health for tourists and business owners: current status, notifications,
 * warnings and violations with reasons and dates, and appeals. It stays available while
 * the account is suspended or deactivated.
 */
export default function AccountStatus() {
  const { logout, updateUser } = useAuth()
  const navigate = useNavigate()
  const [data, setData] = useState(null)
  const [error, setError] = useState(false)
  const [appealing, setAppealing] = useState(null)

  const load = useCallback(async () => {
    try {
      const result = await getAccountStatus()
      setData(result)
      setError(false)
      // Keep the signed-in user's state in sync (e.g. a suspension that just ended).
      updateUser({ account_state: result.account_state, suspended_until: result.suspended_until })
      if (result.unread_notifications > 0) markAccountNotificationsRead().catch(() => {})
    } catch {
      setError(true)
    }
  }, [updateUser])

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load()
  }, [load])

  const closeAppeal = useCallback(() => setAppealing(null), [])

  const handleLogout = async () => {
    await logout()
    navigate('/login', { replace: true })
  }

  if (error) return <div className="p-8 text-center text-gray-600">Your account status could not be loaded. Please refresh the page.</div>
  if (!data) return <LoadingSpinner size="lg" />

  const state = STATE_COPY[data.account_state] || STATE_COPY.active
  const restricted = ['suspended', 'deactivated'].includes(data.account_state)
  const backTo = data.role === 'business_owner' ? { to: '/business', label: 'Back to Business Portal' } : { to: '/dashboard', label: 'Back to Dashboard' }

  return (
    <div className="bg-gray-50 min-h-screen">
      <div className="max-w-4xl mx-auto px-4 sm:px-6 pb-16">
        <PageHeader
          back={restricted ? undefined : backTo}
          eyebrow={<p className="text-xs font-semibold uppercase tracking-wider text-secondary-500">Your account</p>}
          title="Account Health"
          subtitle="Your account status, any warnings or restrictions, and your appeals."
          actions={restricted && (
            <button type="button" onClick={handleLogout} className="btn-secondary px-4 py-2 text-sm">
              <FiLogOut size={15} aria-hidden="true" /> Sign out
            </button>
          )}
        />

        {/* ==================== CURRENT STATUS ==================== */}
        <section className={`rounded-2xl border p-5 sm:p-6 ${state.tone}`}>
          <div className="flex items-start gap-4">
            <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-white">
              <state.icon size={22} className={state.iconTone} aria-hidden="true" />
            </span>
            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-2">
                <h2 className="font-heading text-xl font-bold text-gray-900">{state.title}</h2>
                <StatusPill value={data.account_state} withDot />
              </div>
              <p className="mt-1 text-gray-700">{state.text}</p>
              {data.account_state === 'suspended' && data.suspended_until && (
                <p className="mt-2 font-semibold text-gray-900">Suspension ends: {formatDateTime(data.suspended_until)} (Philippine time)</p>
              )}
            </div>
          </div>
        </section>

        {/* ==================== NOTIFICATIONS ==================== */}
        <section className="mt-8">
          <h2 className="flex items-center gap-2 font-heading text-xl font-bold text-gray-900 mb-3">
            <FiBell size={18} className="text-primary-600" aria-hidden="true" /> Account notifications
          </h2>
          {data.notifications.length === 0 ? (
            <p className="rounded-2xl border border-dashed border-gray-300 bg-white/60 px-5 py-6 text-center text-sm text-gray-500">No account notifications.</p>
          ) : (
            <ul className="space-y-2">
              {data.notifications.map((notice) => (
                <li key={notice.id} className={`rounded-xl border bg-white px-4 py-3 ${notice.read_at ? 'border-gray-200' : 'border-primary-200 ring-1 ring-primary-100'}`}>
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="font-semibold text-gray-900">
                      {!notice.read_at && <span className="mr-2 inline-block h-2 w-2 rounded-full bg-primary-500 align-middle" aria-label="New" />}
                      {notice.data.title}
                    </p>
                    <span className="text-xs text-gray-500">{formatDateTime(notice.created_at)}</span>
                  </div>
                  <p className="mt-1 text-sm text-gray-700">{notice.data.message}</p>
                  {notice.data.reason && <p className="mt-1 text-sm text-gray-700"><span className="font-semibold">Reason:</span> {notice.data.reason}</p>}
                  {notice.data.next_steps && <p className="mt-1 text-sm text-gray-600">{notice.data.next_steps}</p>}
                </li>
              ))}
            </ul>
          )}
        </section>

        {/* ==================== VIOLATIONS & APPEALS ==================== */}
        <section className="mt-8">
          <h2 className="flex items-center gap-2 font-heading text-xl font-bold text-gray-900 mb-3">
            <FiShield size={18} className="text-primary-600" aria-hidden="true" /> Warnings and violations
          </h2>
          {data.violations.length === 0 ? (
            <p className="rounded-2xl border border-dashed border-gray-300 bg-white/60 px-5 py-6 text-center text-sm text-gray-500">You have no warnings or violations on record.</p>
          ) : (
            <div className="space-y-4">
              {data.violations.map((violation) => <ViolationItem key={violation.id} violation={violation} onAppeal={setAppealing} />)}
            </div>
          )}
        </section>

        <p className="mt-8 text-sm text-gray-500">
          Questions about your account? Contact the Sorsogon Tourism Office. Records stay on this page for your reference, including after a restriction ends.
        </p>
      </div>

      {appealing && <AppealModal violation={appealing} onClose={closeAppeal} onDone={() => { setAppealing(null); load() }} />}
    </div>
  )
}
