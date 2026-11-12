import { useState } from 'react'
import toast from 'react-hot-toast'
import { FiFlag, FiPaperclip } from 'react-icons/fi'
import { reportRating } from '../../api/ratings'
import Modal from '../common/Modal'

// Must match ReviewReport::REASONS on the server.
const REASONS = [
  ['offensive', 'Offensive or abusive language'],
  ['false_information', 'False or misleading information'],
  ['personal_information', 'Shares personal information'],
  ['spam', 'Spam or advertising'],
  ['not_a_visit', 'Not about a visit to this place'],
  ['other', 'Other'],
]

const STATUS = {
  pending: ['Reported · Pending review', 'bg-amber-50 text-amber-800'],
  reviewed: ['Reported · Being reviewed', 'bg-sky-50 text-sky-800'],
  action_taken: ['Report upheld · Review hidden', 'bg-red-50 text-red-700'],
  dismissed: ['Report dismissed · Review stays', 'bg-gray-100 text-gray-700'],
}

/**
 * Lets a business owner report a review on their listing to the Tourism Office (reason, what is
 * wrong, optional evidence), and shows where that report stands. Reviews are never removed for a
 * low rating; the Tourism Office decides whether the review breaks the rules.
 */
export default function ReportReviewButton({ review, onReported }) {
  const [open, setOpen] = useState(false)
  const [reason, setReason] = useState('')
  const [details, setDetails] = useState('')
  const [evidence, setEvidence] = useState(null)
  const [errors, setErrors] = useState({})
  const [sending, setSending] = useState(false)
  const report = review.latest_report

  const submit = async (event) => {
    event.preventDefault()
    const form = new FormData()
    form.append('reason', reason)
    form.append('details', details.trim())
    if (evidence) form.append('evidence', evidence)
    setSending(true)
    try {
      await reportRating(review.id, form)
      toast.success('Report sent to the Tourism Office.')
      setOpen(false)
      onReported?.()
    } catch (error) {
      const fields = error.response?.data?.errors
      if (fields) setErrors(Object.fromEntries(Object.entries(fields).map(([key, messages]) => [key, messages[0]])))
      else if (!error.errorDialogShown) toast.error(error.response?.data?.message || 'Could not send the report.')
    } finally {
      setSending(false)
    }
  }

  if (report && ['pending', 'reviewed'].includes(report.status)) {
    const [label, className] = STATUS[report.status]
    return <span className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ${className}`}>{label}</span>
  }

  return (
    <div className="flex flex-col items-end gap-1">
      {report && (
        <span className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ${STATUS[report.status]?.[1]}`} title={report.decision_note || undefined}>
          {STATUS[report.status]?.[0]}
        </span>
      )}
      {!review.hidden_at && (
        <button type="button" onClick={() => { setErrors({}); setOpen(true) }} className="inline-flex items-center gap-1 text-xs font-semibold text-gray-500 hover:text-red-600">
          <FiFlag size={12} aria-hidden="true" /> Report review
        </button>
      )}

      {open && (
        <Modal
          title="Report this review"
          subtitle="The Tourism Office reviews every report. A low rating or a negative opinion alone is not a reason to remove a review."
          onClose={() => !sending && setOpen(false)}
          busy={sending}
          footer={
            <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <button type="button" className="btn-secondary" disabled={sending} onClick={() => setOpen(false)}>Cancel</button>
              <button type="submit" form={`report-${review.id}`} className="btn-primary" disabled={sending || !reason || details.trim().length < 10}>
                {sending ? 'Sending…' : 'Send report'}
              </button>
            </div>
          }
        >
          <form id={`report-${review.id}`} onSubmit={submit} className="space-y-4 text-left">
            <label className="block text-sm">
              <span className="mb-1 block font-medium text-gray-700">Reason <span className="text-red-500">*</span></span>
              <select value={reason} onChange={(e) => setReason(e.target.value)} className="input-field">
                <option value="">Choose a reason</option>
                {REASONS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
              </select>
              {errors.reason && <span className="mt-1 block text-xs text-red-600">{errors.reason}</span>}
            </label>
            <label className="block text-sm">
              <span className="mb-1 block font-medium text-gray-700">What is wrong with this review? <span className="text-red-500">*</span></span>
              <textarea value={details} onChange={(e) => setDetails(e.target.value)} rows={4} maxLength={1000} className="input-field" placeholder="At least 10 characters." />
              {errors.details && <span className="mt-1 block text-xs text-red-600">{errors.details}</span>}
            </label>
            <label className="block text-sm">
              <span className="mb-1 block font-medium text-gray-700">Supporting evidence (optional)</span>
              <span className="flex items-center gap-2 text-xs text-gray-500"><FiPaperclip aria-hidden="true" /> A photo or PDF, up to 5 MB. Only the Tourism Office sees it.</span>
              <input type="file" accept="image/jpeg,image/png,image/webp,application/pdf" onChange={(e) => setEvidence(e.target.files?.[0] || null)} className="mt-2 block w-full text-sm" />
              {errors.evidence && <span className="mt-1 block text-xs text-red-600">{errors.evidence}</span>}
            </label>
          </form>
        </Modal>
      )}
    </div>
  )
}
