// frontend/src/components/admin/accounts/EnforcementModal.jsx
import { useState } from 'react'
import toast from 'react-hot-toast'
import { FiAlertTriangle, FiArrowLeft, FiPaperclip, FiX } from 'react-icons/fi'
import Modal from '../../common/Modal'
import { enforceUser } from '../../../api/admin'
import { ACTION_CONSEQUENCES, ENFORCEMENT_ACTIONS, formatDay, roleLabel } from '../../../utils/accountStates'
import { showApiError } from '../../../utils/apiErrors'

const FILE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf']
const FILE_MAX = 5 * 1024 * 1024
const DAY_PRESETS = [1, 3, 7, 14, 30]
const today = () => new Date().toISOString().slice(0, 10)

/**
 * Warn, temporarily suspend or deactivate an account: a complete, reviewed case
 * (category, reason, explanation, evidence), then a confirmation step that spells out
 * the consequences before anything is applied.
 */
export default function EnforcementModal({ user, initialAction = 'warning', categories, onClose, onDone }) {
  const [step, setStep] = useState('form')
  const [busy, setBusy] = useState(false)
  const [errors, setErrors] = useState({})
  const [acknowledged, setAcknowledged] = useState(false)
  const [files, setFiles] = useState([])
  const [form, setForm] = useState({
    action: initialAction,
    category: '',
    reason: '',
    details: '',
    evidence_notes: '',
    suspension_days: 7,
    review_date: '',
    reported_at: today(),
  })
  const set = (field) => (event) => setForm((prev) => ({ ...prev, [field]: event.target.value }))
  const action = ENFORCEMENT_ACTIONS[form.action]
  const categoryLabel = categories.find((c) => c.value === form.category)?.label

  const addFiles = (list) => {
    const next = [...files]
    for (const file of list) {
      if (!FILE_TYPES.includes(file.type)) {
        toast.error(`${file.name}: only JPG, PNG, WebP or PDF files can be attached.`)
      } else if (file.size > FILE_MAX) {
        toast.error(`${file.name} is larger than 5 MB.`)
      } else if (next.length >= 5) {
        toast.error('You can attach up to 5 files.')
        break
      } else {
        next.push(file)
      }
    }
    setFiles(next)
  }

  const validate = () => {
    const found = {}
    if (!form.category) found.category = 'Choose a violation category.'
    if (form.reason.trim().length < 5) found.reason = 'Give a short reason (at least 5 characters).'
    if (form.details.trim().length < 20) found.details = 'Explain what happened (at least 20 characters).'
    if (form.action === 'suspension' && !(Number(form.suspension_days) >= 1 && Number(form.suspension_days) <= 365)) {
      found.suspension_days = 'Enter a duration between 1 and 365 days.'
    }
    if (form.review_date && form.review_date <= today()) found.review_date = 'The review date must be in the future.'
    if (form.reported_at && form.reported_at > today()) found.reported_at = 'The report date cannot be in the future.'
    setErrors(found)
    return Object.keys(found).length === 0
  }

  const goToConfirm = (event) => {
    event.preventDefault()
    if (validate()) {
      setAcknowledged(false)
      setStep('confirm')
    }
  }

  const submit = async () => {
    const payload = new FormData()
    payload.append('action', form.action)
    payload.append('category', form.category)
    payload.append('reason', form.reason.trim())
    payload.append('details', form.details.trim())
    if (form.evidence_notes.trim()) payload.append('evidence_notes', form.evidence_notes.trim())
    if (form.action === 'suspension') payload.append('suspension_days', String(form.suspension_days))
    if (form.review_date) payload.append('review_date', form.review_date)
    if (form.reported_at) payload.append('reported_at', form.reported_at)
    files.forEach((file) => payload.append('evidence_files[]', file))
    payload.append('confirmed', '1')

    setBusy(true)
    try {
      const data = await enforceUser(user.id, payload)
      toast.success(data.message)
      onDone()
    } catch (error) {
      const serverErrors = error.response?.data?.errors
      if (serverErrors) {
        setErrors(Object.fromEntries(Object.entries(serverErrors).map(([key, messages]) => [key.split('.')[0], messages[0]])))
        setStep('form')
      }
      showApiError(error, 'The action could not be applied.')
    } finally {
      setBusy(false)
    }
  }

  const fieldError = (name) => errors[name] && <p className="mt-1 text-xs font-medium text-red-600">{errors[name]}</p>

  return (
    <Modal
      size="lg"
      busy={busy}
      onClose={onClose}
      title={step === 'form' ? 'Account enforcement' : `Confirm: ${action.verb.toLowerCase()}`}
      subtitle={`${user.name} · ${user.email} · ${roleLabel(user.role?.name || user.role)}`}
      footer={
        step === 'form' ? (
          <>
            <button type="button" onClick={onClose} className="btn-secondary">Cancel</button>
            <button type="submit" form="enforcement-form" className="btn-primary">Review and confirm</button>
          </>
        ) : (
          <>
            <button type="button" onClick={() => setStep('form')} disabled={busy} className="btn-secondary">
              <FiArrowLeft size={15} aria-hidden="true" /> Back to edit
            </button>
            <button
              type="button"
              onClick={submit}
              disabled={!acknowledged || busy}
              className={`inline-flex items-center justify-center gap-2 px-6 py-2.5 rounded-lg font-semibold text-white transition-colors disabled:opacity-50 disabled:cursor-not-allowed ${
                form.action === 'warning' ? 'bg-amber-600 hover:bg-amber-700' : 'bg-red-600 hover:bg-red-700'
              }`}
            >
              {busy ? 'Applying…' : action.verb}
            </button>
          </>
        )
      }
    >
      {step === 'form' ? (
        <form id="enforcement-form" onSubmit={goToConfirm} className="space-y-5" noValidate>
          <fieldset>
            <legend className="text-sm font-semibold text-gray-800 mb-2">Enforcement action</legend>
            <div className="grid gap-2 sm:grid-cols-3">
              {Object.entries(ENFORCEMENT_ACTIONS).map(([value, meta]) => (
                <label
                  key={value}
                  className={`cursor-pointer rounded-xl border-2 p-3 text-sm transition-colors ${
                    form.action === value ? 'border-primary-500 bg-primary-50/60' : 'border-gray-200 hover:border-gray-300'
                  }`}
                >
                  <input type="radio" name="action" value={value} checked={form.action === value} onChange={set('action')} className="sr-only" />
                  <span className="block font-semibold text-gray-900">{meta.label}</span>
                  <span className="block text-xs text-gray-500 mt-0.5">
                    {value === 'warning' ? 'Account stays active' : value === 'suspension' ? 'Restricted for a set number of days' : 'Restricted until reactivated'}
                  </span>
                </label>
              ))}
            </div>
          </fieldset>

          {form.action === 'suspension' && (
            <div>
              <label htmlFor="enf-days" className="block text-sm font-semibold text-gray-800 mb-2">Suspension duration (days)</label>
              <div className="flex flex-wrap items-center gap-2">
                <input id="enf-days" type="number" min="1" max="365" className="input-field w-28" value={form.suspension_days} onChange={set('suspension_days')} />
                {DAY_PRESETS.map((days) => (
                  <button
                    key={days}
                    type="button"
                    onClick={() => setForm((prev) => ({ ...prev, suspension_days: days }))}
                    className={`rounded-full border px-3 py-1.5 text-sm font-medium ${Number(form.suspension_days) === days ? 'border-primary-500 bg-primary-50 text-primary-700' : 'border-gray-200 text-gray-600 hover:border-gray-300'}`}
                  >
                    {days} day{days === 1 ? '' : 's'}
                  </button>
                ))}
              </div>
              {fieldError('suspension_days')}
            </div>
          )}

          <div className="grid gap-4 sm:grid-cols-2">
            <div className="sm:col-span-2">
              <label htmlFor="enf-category" className="block text-sm font-semibold text-gray-800 mb-1.5">Violation category</label>
              <select id="enf-category" className="input-field" value={form.category} onChange={set('category')}>
                <option value="">Choose a category…</option>
                {categories.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
              </select>
              {fieldError('category')}
            </div>
            <div className="sm:col-span-2">
              <label htmlFor="enf-reason" className="block text-sm font-semibold text-gray-800 mb-1.5">Reason (shown to the user)</label>
              <input id="enf-reason" className="input-field" maxLength={255} placeholder="e.g. Posted misleading prices on the listing" value={form.reason} onChange={set('reason')} />
              {fieldError('reason')}
            </div>
            <div className="sm:col-span-2">
              <label htmlFor="enf-details" className="block text-sm font-semibold text-gray-800 mb-1.5">Detailed explanation (shown to the user)</label>
              <textarea id="enf-details" rows={4} className="input-field" maxLength={5000} placeholder="What happened, when, and which rule it breaks." value={form.details} onChange={set('details')} />
              {fieldError('details')}
            </div>
            <div className="sm:col-span-2">
              <label htmlFor="enf-evidence" className="block text-sm font-semibold text-gray-800 mb-1.5">
                Supporting evidence <span className="font-normal text-gray-500">(internal, optional)</span>
              </label>
              <textarea id="enf-evidence" rows={2} className="input-field" maxLength={5000} placeholder="Links, review IDs, report notes…" value={form.evidence_notes} onChange={set('evidence_notes')} />
              <label className="mt-2 inline-flex cursor-pointer items-center gap-2 rounded-lg border border-dashed border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:border-primary-400 hover:text-primary-700">
                <FiPaperclip size={15} aria-hidden="true" /> Attach files (JPG, PNG, WebP or PDF, up to 5 MB, max 5)
                <input type="file" multiple accept="image/jpeg,image/png,image/webp,application/pdf" className="sr-only" onChange={(e) => { addFiles(Array.from(e.target.files || [])); e.target.value = '' }} />
              </label>
              {files.length > 0 && (
                <ul className="mt-2 space-y-1">
                  {files.map((file, index) => (
                    <li key={`${file.name}-${index}`} className="flex items-center justify-between gap-2 rounded-lg bg-gray-50 px-3 py-1.5 text-sm text-gray-700">
                      <span className="truncate">{file.name}</span>
                      <button type="button" onClick={() => setFiles(files.filter((_, i) => i !== index))} className="p-2 text-gray-400 hover:text-red-600" aria-label={`Remove ${file.name}`}>
                        <FiX size={14} />
                      </button>
                    </li>
                  ))}
                </ul>
              )}
              {fieldError('evidence_files')}
            </div>
            <div>
              <label htmlFor="enf-reported" className="block text-sm font-semibold text-gray-800 mb-1.5">Date reported</label>
              <input id="enf-reported" type="date" max={today()} className="input-field" value={form.reported_at} onChange={set('reported_at')} />
              {fieldError('reported_at')}
            </div>
            <div>
              <label htmlFor="enf-review" className="block text-sm font-semibold text-gray-800 mb-1.5">
                Review date <span className="font-normal text-gray-500">(optional)</span>
              </label>
              <input id="enf-review" type="date" min={today()} className="input-field" value={form.review_date} onChange={set('review_date')} />
              {fieldError('review_date')}
            </div>
          </div>

          <p className="rounded-lg bg-sky-50 border border-sky-100 px-3 py-2.5 text-sm text-sky-900">
            Only act on reports you have reviewed and verified. The next step shows exactly what will happen before anything is applied.
          </p>
        </form>
      ) : (
        <div className="space-y-5">
          <div className={`flex gap-3 rounded-xl border p-4 ${form.action === 'warning' ? 'border-amber-200 bg-amber-50' : 'border-red-200 bg-red-50'}`}>
            <FiAlertTriangle size={20} className={`mt-0.5 shrink-0 ${form.action === 'warning' ? 'text-amber-600' : 'text-red-600'}`} aria-hidden="true" />
            <div className="text-sm text-gray-800">
              <p className="font-semibold">
                {action.verb} for {user.name}
                {form.action === 'suspension' && ` — ${form.suspension_days} day${Number(form.suspension_days) === 1 ? '' : 's'}`}
              </p>
              <ul className="mt-2 list-disc space-y-1 pl-4">
                {ACTION_CONSEQUENCES[form.action].map((line) => <li key={line}>{line}</li>)}
              </ul>
            </div>
          </div>

          <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            <div><dt className="text-xs font-semibold uppercase tracking-wider text-gray-500">Category</dt><dd className="mt-0.5 text-gray-900">{categoryLabel}</dd></div>
            <div><dt className="text-xs font-semibold uppercase tracking-wider text-gray-500">Date reported</dt><dd className="mt-0.5 text-gray-900">{formatDay(form.reported_at)}</dd></div>
            <div className="sm:col-span-2"><dt className="text-xs font-semibold uppercase tracking-wider text-gray-500">Reason</dt><dd className="mt-0.5 text-gray-900">{form.reason}</dd></div>
            <div className="sm:col-span-2"><dt className="text-xs font-semibold uppercase tracking-wider text-gray-500">Explanation</dt><dd className="mt-0.5 text-gray-900 whitespace-pre-line">{form.details}</dd></div>
            <div><dt className="text-xs font-semibold uppercase tracking-wider text-gray-500">Evidence</dt><dd className="mt-0.5 text-gray-900">{files.length} file{files.length === 1 ? '' : 's'}{form.evidence_notes ? ' + notes' : ''}</dd></div>
            {form.review_date && <div><dt className="text-xs font-semibold uppercase tracking-wider text-gray-500">Review date</dt><dd className="mt-0.5 text-gray-900">{formatDay(form.review_date)}</dd></div>}
          </dl>

          <label className="flex items-start gap-3 rounded-xl border border-gray-200 p-3 text-sm text-gray-800">
            <input type="checkbox" className="mt-0.5 h-4 w-4" checked={acknowledged} onChange={(e) => setAcknowledged(e.target.checked)} />
            I have reviewed the evidence for this case and confirm this action is justified. It will be recorded under my name.
          </label>
        </div>
      )}
    </Modal>
  )
}
