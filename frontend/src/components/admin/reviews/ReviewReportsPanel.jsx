import { useCallback, useEffect, useState } from 'react'
import toast from 'react-hot-toast'
import { FiAlertTriangle, FiCheckCircle, FiEye, FiEyeOff, FiFlag, FiPaperclip } from 'react-icons/fi'
import { decideReviewReport, downloadReviewReportEvidence, getReviewReports } from '../../../api/admin'
import { formatShortDate } from '../../../utils/helpers'
import { openPrivateFile } from '../../../utils/privateFile'
import Modal from '../../common/Modal'
import Pagination from '../../common/Pagination'
import LoadingSpinner from '../../common/LoadingSpinner'
import ReviewContent, { Badge } from './ReviewContent'
import { REPORT_STATUS } from './reviewLabels'

const STATUS_TABS = ['pending', 'reviewed', 'action_taken', 'dismissed', 'all']

// What each decision does, as the confirmation dialog explains it.
const DECISIONS = {
  action_taken: {
    title: 'Hide this review',
    button: 'Hide review',
    explain: 'The review stays on record but is hidden from the public and left out of the listing’s rating. You can restore it later by dismissing the report.',
    className: 'bg-red-600 hover:bg-red-700',
  },
  dismissed: {
    title: 'Dismiss this report',
    button: 'Dismiss report',
    explain: 'The review stays public. A low rating or negative opinion is not a reason to remove a review.',
    className: 'bg-primary-600 hover:bg-primary-700',
  },
}

/**
 * Reviews that listing owners reported, and the Tourism Office's decisions: Pending Review →
 * Reviewed → Action Taken (the review is hidden, never deleted) or Dismissed (the review stays).
 */
export default function ReviewReportsPanel({ onChange }) {
  const [status, setStatus] = useState('pending')
  const [page, setPage] = useState(1)
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(false)
  const [deciding, setDeciding] = useState(null) // { report, status }
  const [note, setNote] = useState('')
  const [saving, setSaving] = useState(false)

  const load = useCallback(
    () =>
      getReviewReports({ status, page })
        .then((result) => {
          setData(result)
          setError(false)
        })
        .catch(() => setError(true))
        .finally(() => setLoading(false)),
    [status, page]
  )

  useEffect(() => {
    load()
  }, [load])

  const decide = async (report, next, decisionNote) => {
    setSaving(true)
    try {
      await decideReviewReport(report.id, { status: next, decision_note: decisionNote || undefined })
      toast.success(next === 'action_taken' ? 'Review hidden from the public.' : next === 'dismissed' ? 'Report dismissed. The review stays public.' : 'Marked as reviewed.')
      setDeciding(null)
      setNote('')
      load()
      onChange?.()
    } catch (err) {
      if (!err.errorDialogShown) toast.error(err.response?.data?.message || 'Could not save the decision.')
    } finally {
      setSaving(false)
    }
  }

  const counts = data?.counts || {}
  const reports = data?.data || []

  return (
    <div>
      <div className="mb-5 flex flex-wrap gap-2" role="tablist" aria-label="Report status">
        {STATUS_TABS.map((key) => {
          const active = status === key
          const count = key === 'all' ? Object.values(counts).reduce((sum, n) => sum + n, 0) : counts[key] ?? 0
          return (
            <button
              key={key}
              type="button"
              role="tab"
              aria-selected={active}
              onClick={() => { setLoading(true); setStatus(key); setPage(1) }}
              className={`rounded-full border px-4 py-1.5 text-sm font-medium transition-colors ${active ? 'border-primary-600 bg-primary-600 text-white' : 'border-gray-200 bg-white text-gray-700 hover:border-primary-300'}`}
            >
              {key === 'all' ? 'All reports' : REPORT_STATUS[key].label} <span className={active ? 'text-primary-100' : 'text-gray-400'}>({count})</span>
            </button>
          )
        })}
      </div>

      {loading ? (
        <LoadingSpinner size="lg" />
      ) : error ? (
        <div className="bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) p-8 text-center">
          <p className="text-gray-600 mb-4">We couldn't load the reports.</p>
          <button type="button" onClick={() => { setLoading(true); load() }} className="btn-secondary">Try again</button>
        </div>
      ) : reports.length === 0 ? (
        <div className="bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) p-10 text-center">
          <FiCheckCircle className="mx-auto text-primary-500" size={28} aria-hidden="true" />
          <p className="mt-2 text-gray-600">{status === 'pending' ? 'No reports waiting for review.' : 'No reports here.'}</p>
        </div>
      ) : (
        <>
          <ul className="space-y-4">
            {reports.map((report) => {
              const state = REPORT_STATUS[report.status]
              const open = ['pending', 'reviewed'].includes(report.status)
              return (
                <li key={report.id} className="bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) overflow-hidden">
                  <div className="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 bg-gray-50/70 px-5 py-4">
                    <div className="min-w-0">
                      <p className="flex items-center gap-2 text-sm font-semibold text-gray-900">
                        <FiFlag className="text-red-500" size={15} aria-hidden="true" />
                        {data.reasons?.[report.reason] || report.reason}
                      </p>
                      <p className="mt-0.5 text-xs text-gray-500">
                        Reported by {report.reporter?.name || 'the business owner'} · {formatShortDate(report.created_at)}
                      </p>
                    </div>
                    <Badge className={state.className}>{state.label}</Badge>
                  </div>

                  <div className="px-5 py-4">
                    <p className="text-sm text-gray-700 whitespace-pre-line"><span className="font-medium text-gray-900">Owner&apos;s explanation: </span>{report.details}</p>
                    {report.has_evidence && (
                      <button
                        type="button"
                        onClick={() => openPrivateFile(() => downloadReviewReportEvidence(report.id), `report-${report.id}-evidence`)}
                        className="mt-2 inline-flex items-center gap-1.5 text-sm font-semibold text-primary-700 hover:text-primary-800"
                      >
                        <FiPaperclip size={14} aria-hidden="true" /> View evidence
                      </button>
                    )}

                    <div className="mt-4 rounded-xl border border-gray-200 p-4">
                      <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500">Reported review</p>
                      {report.rating ? (
                        <ReviewContent
                          review={report.rating}
                          aside={report.rating.hidden_at && <Badge className="border-red-200 bg-red-50 text-red-700"><FiEyeOff size={12} aria-hidden="true" /> Hidden from public</Badge>}
                        />
                      ) : (
                        <p className="text-sm text-gray-400">This review was deleted by its author.</p>
                      )}
                    </div>

                    {report.decided_at && report.status !== 'reviewed' && (
                      <p className="mt-4 text-sm text-gray-600">
                        <span className="font-medium text-gray-900">Decision:</span> {report.decision_note || state.label}
                        <span className="text-gray-400"> · {report.decider?.name ? `${report.decider.name}, ` : ''}{formatShortDate(report.decided_at)}</span>
                      </p>
                    )}

                    {report.rating && (
                      <div className="mt-4 flex flex-wrap gap-2">
                        {report.status === 'pending' && (
                          <button type="button" disabled={saving} onClick={() => decide(report, 'reviewed')} className="btn-secondary py-2 text-sm">
                            <FiEye size={14} aria-hidden="true" /> Mark as reviewed
                          </button>
                        )}
                        {(open || report.status === 'dismissed') && (
                          <button type="button" disabled={saving} onClick={() => { setNote(''); setDeciding({ report, status: 'action_taken' }) }} className="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-60">
                            <FiEyeOff size={14} aria-hidden="true" /> Hide review
                          </button>
                        )}
                        {(open || report.status === 'action_taken') && (
                          <button type="button" disabled={saving} onClick={() => { setNote(''); setDeciding({ report, status: 'dismissed' }) }} className="btn-secondary py-2 text-sm">
                            {report.status === 'action_taken' ? 'Restore review (dismiss report)' : 'Dismiss report'}
                          </button>
                        )}
                      </div>
                    )}
                  </div>
                </li>
              )
            })}
          </ul>

          <Pagination
            currentPage={data.current_page}
            lastPage={data.last_page}
            onPageChange={(value) => { setLoading(true); setPage(value); window.scrollTo({ top: 0, behavior: 'smooth' }) }}
          />
        </>
      )}

      {deciding && (
        <Modal
          title={DECISIONS[deciding.status].title}
          onClose={() => !saving && setDeciding(null)}
          busy={saving}
          footer={
            <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <button type="button" className="btn-secondary" disabled={saving} onClick={() => setDeciding(null)}>Cancel</button>
              <button
                type="button"
                disabled={saving || note.trim().length === 0}
                onClick={() => decide(deciding.report, deciding.status, note.trim())}
                className={`inline-flex items-center justify-center rounded-lg px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50 ${DECISIONS[deciding.status].className}`}
              >
                {saving ? 'Saving…' : DECISIONS[deciding.status].button}
              </button>
            </div>
          }
        >
          <p className="flex gap-2 text-sm text-gray-600">
            <FiAlertTriangle className="mt-0.5 shrink-0 text-amber-500" aria-hidden="true" /> {DECISIONS[deciding.status].explain}
          </p>
          <label className="mt-4 block text-sm">
            <span className="mb-1 block font-medium text-gray-700">Reason for the decision <span className="text-red-500">*</span></span>
            <textarea value={note} onChange={(e) => setNote(e.target.value)} rows={3} maxLength={1000} className="input-field" placeholder="This is sent to the business owner who reported the review." />
          </label>
        </Modal>
      )}
    </div>
  )
}
