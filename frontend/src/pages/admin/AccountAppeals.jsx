// frontend/src/pages/admin/AccountAppeals.jsx
import { useCallback, useEffect, useState } from 'react'
import toast from 'react-hot-toast'
import { FiInbox, FiPaperclip, FiSearch } from 'react-icons/fi'
import { decideAccountAppeal, downloadAppealAttachment, getAccountAppeals } from '../../api/admin'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import EmptyState from '../../components/common/EmptyState'
import Pagination from '../../components/common/Pagination'
import Modal from '../../components/common/Modal'
import StatusPill from '../../components/account/StatusPill'
import ViolationCard from '../../components/admin/accounts/ViolationCard'
import { openPrivateFile } from '../../utils/privateFile'
import { APPEAL_STATUSES, formatDateTime, roleLabel } from '../../utils/accountStates'
import { showApiError } from '../../utils/apiErrors'

const STATUS_TABS = [
  { value: 'pending', label: 'Waiting for review' },
  { value: 'approved', label: 'Approved' },
  { value: 'rejected', label: 'Rejected' },
  { value: '', label: 'All' },
]

/** Review an appeal next to the original violation, then approve (reverses it) or reject with a written decision. */
function DecisionModal({ appeal, onClose, onDone }) {
  const [decision, setDecision] = useState('')
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)
  const pending = appeal.status === 'pending'

  const submit = async () => {
    setBusy(true)
    try {
      const data = await decideAccountAppeal(appeal.id, { decision, note: note.trim() })
      toast.success(data.message)
      onDone()
    } catch (error) {
      showApiError(error, 'The decision could not be saved.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      size="xl"
      busy={busy}
      onClose={onClose}
      title={pending ? 'Review appeal' : 'Appeal decision'}
      subtitle={`${appeal.user?.name} · ${appeal.user?.email} · ${roleLabel(appeal.user?.role?.name)}`}
      footer={pending && (
        <>
          <button type="button" onClick={onClose} disabled={busy} className="btn-secondary">Cancel</button>
          <button
            type="button"
            onClick={submit}
            disabled={!decision || note.trim().length < 10 || busy}
            className={`inline-flex items-center justify-center px-6 py-2.5 rounded-lg font-semibold text-white transition-colors disabled:opacity-50 disabled:cursor-not-allowed ${decision === 'rejected' ? 'bg-gray-800 hover:bg-gray-900' : 'bg-green-600 hover:bg-green-700'}`}
          >
            {busy ? 'Saving…' : decision === 'rejected' ? 'Reject appeal' : decision === 'approved' ? 'Approve and reverse' : 'Choose a decision'}
          </button>
        </>
      )}
    >
      <div className="grid gap-5 lg:grid-cols-2">
        <section>
          <h3 className="text-xs font-semibold uppercase tracking-wider text-gray-500 mb-2">Original violation</h3>
          <ViolationCard violation={{ ...appeal.violation, latest_appeal: null }} />
        </section>
        <section className="space-y-4">
          <div>
            <h3 className="text-xs font-semibold uppercase tracking-wider text-gray-500 mb-2">User&apos;s appeal</h3>
            <div className="rounded-xl border border-gray-200 bg-white p-4">
              <p className="text-xs text-gray-500">Submitted {formatDateTime(appeal.created_at)}</p>
              <p className="mt-2 text-sm text-gray-800 whitespace-pre-line">{appeal.explanation}</p>
              {appeal.attachments?.length > 0 && (
                <div className="mt-3 flex flex-wrap gap-2">
                  {appeal.attachments.map((file, index) => (
                    <button
                      key={index}
                      type="button"
                      onClick={() => openPrivateFile(() => downloadAppealAttachment(appeal.id, index), file.name)}
                      className="inline-flex items-center gap-1.5 rounded-full border border-gray-200 px-3 py-1 text-xs font-medium text-primary-700 hover:border-primary-300"
                    >
                      <FiPaperclip size={12} aria-hidden="true" /> {file.name}
                    </button>
                  ))}
                </div>
              )}
            </div>
          </div>

          {pending ? (
            <div>
              <h3 className="text-xs font-semibold uppercase tracking-wider text-gray-500 mb-2">Your decision</h3>
              <div className="grid gap-2 sm:grid-cols-2">
                {[
                  { value: 'approved', title: 'Approve', text: 'Reverse the action, correct the record and restore access if nothing else applies.' },
                  { value: 'rejected', title: 'Reject', text: 'Keep the original decision. This is final for this violation.' },
                ].map((option) => (
                  <label key={option.value} className={`cursor-pointer rounded-xl border-2 p-3 text-sm ${decision === option.value ? 'border-primary-500 bg-primary-50/60' : 'border-gray-200 hover:border-gray-300'}`}>
                    <input type="radio" name="decision" value={option.value} checked={decision === option.value} onChange={() => setDecision(option.value)} className="sr-only" />
                    <span className="block font-semibold text-gray-900">{option.title}</span>
                    <span className="block text-xs text-gray-500 mt-0.5">{option.text}</span>
                  </label>
                ))}
              </div>
              <label htmlFor="decision-note" className="mt-3 block text-sm font-semibold text-gray-800 mb-1.5">Decision explanation (sent to the user)</label>
              <textarea id="decision-note" rows={4} maxLength={2000} className="input-field" value={note} onChange={(e) => setNote(e.target.value)} />
              <p className="mt-1 text-xs text-gray-500">At least 10 characters. Your name and the date are recorded.</p>
            </div>
          ) : (
            <div className="rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm">
              <StatusPill value={appeal.status} map={APPEAL_STATUSES} />
              <p className="mt-2 text-gray-800 whitespace-pre-line">{appeal.decision_note}</p>
              <p className="mt-2 text-xs text-gray-500">Decided {formatDateTime(appeal.decided_at)}{appeal.decider?.name ? ` by ${appeal.decider.name}` : ''}</p>
            </div>
          )}
        </section>
      </div>
    </Modal>
  )
}

export default function AccountAppeals() {
  const [status, setStatus] = useState('pending')
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [page, setPage] = useState(1)
  const [result, setResult] = useState(null)
  const [loading, setLoading] = useState(true)
  const [open, setOpen] = useState(null)

  useEffect(() => {
    const timer = setTimeout(() => { setQuery(search); setPage(1) }, 350)
    return () => clearTimeout(timer)
  }, [search])

  const load = useCallback(async () => {
    setLoading(true)
    try {
      setResult(await getAccountAppeals({ status: status || undefined, q: query || undefined, page, per_page: 20 }))
    } finally {
      setLoading(false)
    }
  }, [status, query, page])

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load()
  }, [load])

  const closeModal = useCallback(() => setOpen(null), [])

  return (
    <div>
      <div className="mb-6">
        <p className="text-xs font-semibold uppercase tracking-wider text-secondary-500 mb-2">Account enforcement</p>
        <h1 className="text-2xl sm:text-3xl font-heading font-bold text-gray-900">Appeals</h1>
        <p className="mt-1 text-sm text-gray-600">Users who believe an enforcement decision was wrong. Review the original case and their explanation before deciding.</p>
      </div>

      <div className="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div role="tablist" className="inline-flex flex-wrap rounded-full bg-white p-1 border border-gray-200 shadow-sm self-start">
          {STATUS_TABS.map((tab) => (
            <button
              key={tab.value || 'all'}
              type="button"
              role="tab"
              aria-selected={status === tab.value}
              onClick={() => { setStatus(tab.value); setPage(1) }}
              className={`px-4 py-1.5 rounded-full text-sm font-semibold transition-colors ${status === tab.value ? 'bg-primary-500 text-white' : 'text-gray-600 hover:text-primary-700'}`}
            >
              {tab.label}
            </button>
          ))}
        </div>
        <div className="relative w-full sm:max-w-xs">
          <FiSearch className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={16} />
          <input className="input-field pl-9" placeholder="Search user…" value={search} onChange={(e) => setSearch(e.target.value)} />
        </div>
      </div>

      {loading && !result ? (
        <LoadingSpinner size="lg" />
      ) : result?.data?.length === 0 ? (
        <EmptyState icon={FiInbox} title={status === 'pending' ? 'No appeals waiting for review' : 'No appeals here'}>
          Appeals submitted from users&apos; Account Health pages appear here.
        </EmptyState>
      ) : (
        <>
          <div className={`bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) overflow-hidden transition-opacity ${loading ? 'opacity-60' : ''}`}>
            <ul className="divide-y divide-gray-100">
              {result.data.map((appeal) => (
                <li key={appeal.id}>
                  <button type="button" onClick={() => setOpen(appeal)} className="flex w-full flex-col gap-2 px-5 py-4 text-left hover:bg-primary-50/40 sm:flex-row sm:items-center sm:justify-between">
                    <div className="min-w-0">
                      <p className="font-semibold text-gray-900">{appeal.user?.name} <span className="font-normal text-gray-500">· {appeal.user?.email}</span></p>
                      <p className="text-sm text-gray-600 truncate">
                        {appeal.violation?.category_label} — {appeal.violation?.action} · submitted {formatDateTime(appeal.created_at)}
                      </p>
                    </div>
                    <span className="flex items-center gap-3 shrink-0">
                      <StatusPill value={appeal.status} map={APPEAL_STATUSES} />
                      <span className="text-sm font-semibold text-primary-700">{appeal.status === 'pending' ? 'Review' : 'View'}</span>
                    </span>
                  </button>
                </li>
              ))}
            </ul>
          </div>
          <Pagination currentPage={result.current_page} lastPage={result.last_page} onPageChange={setPage} />
        </>
      )}

      {open && <DecisionModal appeal={open} onClose={closeModal} onDone={() => { setOpen(null); load() }} />}
    </div>
  )
}
