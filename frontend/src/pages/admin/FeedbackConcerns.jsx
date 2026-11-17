import { useCallback, useEffect, useRef, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import toast from 'react-hot-toast'
import { FiCheckCircle, FiExternalLink, FiMail, FiMapPin, FiMessageSquare, FiSearch, FiUser } from 'react-icons/fi'
import { addAdminFeedbackNote, getAdminFeedback, getAdminFeedbackItem, updateAdminFeedback } from '../../api/admin'
import Pagination from '../../components/common/Pagination'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import Modal from '../../components/common/Modal'
import ManagedBy from '../../components/admin/ManagedBy'
import { Badge } from '../../components/admin/reviews/ReviewContent'
import { formatShortDate } from '../../utils/helpers'
import { showApiError } from '../../utils/apiErrors'

import { FEEDBACK_COUNT_CHANGED } from '../../api/admin'

const STATUS_STYLE = {
  pending: 'border-amber-200 bg-amber-50 text-amber-800',
  under_review: 'border-sky-200 bg-sky-50 text-sky-800',
  resolved: 'border-green-200 bg-green-50 text-green-800',
  rejected: 'border-gray-200 bg-gray-100 text-gray-700',
}
const TABS = [
  { key: 'open', label: 'Open' },
  { key: 'pending', label: 'Pending' },
  { key: 'under_review', label: 'Under Review' },
  { key: 'resolved', label: 'Resolved' },
  { key: 'rejected', label: 'Rejected' },
  { key: 'all', label: 'All' },
]

const dateTime = (value) => new Date(value).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' })

/**
 * Feedback & Concerns sent from the public site. Open feedback (Pending and Under Review) shows
 * first; each one opens to the full message, the sender's contact details if they gave them, the
 * place it is about, a status change and internal notes. A municipal admin sees their own town's;
 * feedback another office handles is view only. ?open=<id> (from a notification) opens one.
 */
export default function FeedbackConcerns() {
  const [params, setParams] = useSearchParams()
  const [status, setStatus] = useState('open')
  const [category, setCategory] = useState('')
  const [searchInput, setSearchInput] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(false)
  const latestRequest = useRef(0)
  const openId = Number(params.get('open')) || null

  const load = useCallback(() => {
    const request = ++latestRequest.current
    return getAdminFeedback({ status, category: category || undefined, search: search || undefined, page })
      .then((result) => {
        if (request !== latestRequest.current) return
        setData(result)
        setError(false)
      })
      .catch(() => request === latestRequest.current && setError(true))
      .finally(() => request === latestRequest.current && setLoading(false))
  }, [status, category, search, page])

  useEffect(() => {
    load()
  }, [load])

  const openItem = (id) => setParams((current) => { const next = new URLSearchParams(current); next.set('open', id); return next })
  const closeItem = () => setParams((current) => { const next = new URLSearchParams(current); next.delete('open'); return next })
  const changed = () => {
    load()
    window.dispatchEvent(new Event(FEEDBACK_COUNT_CHANGED))
  }

  const counts = data?.counts || {}
  const countOf = (key) => {
    if (key === 'all') return Object.values(counts).reduce((sum, n) => sum + n, 0)
    if (key === 'open') return (counts.pending ?? 0) + (counts.under_review ?? 0)
    return counts[key] ?? 0
  }
  const items = data?.data || []
  const categories = data?.categories || {}

  return (
    <div>
      <div className="mb-6">
        <p className="text-primary-600 font-medium mb-2">Admin</p>
        <h1 className="text-2xl sm:text-3xl font-heading font-bold text-gray-900">Feedback &amp; Concerns</h1>
        <p className="text-gray-600 mt-2">
          Feedback, suggestions and reports of wrong information sent by visitors. Messages are private: they are never shown on the website.
        </p>
      </div>

      <div className="mb-4 flex flex-wrap gap-2" role="tablist" aria-label="Feedback status">
        {TABS.map(({ key, label }) => {
          const active = status === key
          return (
            <button
              key={key}
              type="button"
              role="tab"
              aria-selected={active}
              onClick={() => { setLoading(true); setStatus(key); setPage(1) }}
              className={`rounded-full border px-4 py-1.5 text-sm font-medium transition-colors ${active ? 'border-primary-600 bg-primary-600 text-white' : 'border-gray-200 bg-white text-gray-700 hover:border-primary-300'}`}
            >
              {label} <span className={active ? 'text-primary-100' : 'text-gray-400'}>({countOf(key)})</span>
            </button>
          )
        })}
      </div>

      <form
        className="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-[minmax(0,14rem)_minmax(0,1fr)_auto]"
        onSubmit={(e) => { e.preventDefault(); setLoading(true); setSearch(searchInput.trim()); setPage(1) }}
      >
        <label className="sr-only" htmlFor="feedback-filter-category">Category</label>
        <select id="feedback-filter-category" value={category} onChange={(e) => { setLoading(true); setCategory(e.target.value); setPage(1) }} className="input-field">
          <option value="">All categories</option>
          {Object.entries(categories).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
        </select>
        <div className="relative">
          <FiSearch className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={16} aria-hidden="true" />
          <label className="sr-only" htmlFor="feedback-filter-search">Search</label>
          <input id="feedback-filter-search" type="search" value={searchInput} onChange={(e) => setSearchInput(e.target.value)} maxLength={100}
            className="input-field pl-9" placeholder="Reference, subject, message, name or email" />
        </div>
        <button type="submit" className="btn-secondary justify-center">Search</button>
      </form>

      {loading ? (
        <LoadingSpinner size="lg" />
      ) : error ? (
        <div className="bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) p-8 text-center">
          <p className="text-gray-600 mb-4">We couldn&apos;t load the feedback.</p>
          <button type="button" onClick={() => { setLoading(true); load() }} className="btn-secondary">Try again</button>
        </div>
      ) : items.length === 0 ? (
        <div className="bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) p-10 text-center">
          <FiCheckCircle className="mx-auto text-primary-500" size={28} aria-hidden="true" />
          <p className="mt-2 text-gray-600">
            {search || category ? 'No feedback matches these filters.' : status === 'open' ? 'No open feedback. Everything has been handled.' : 'No feedback here.'}
          </p>
        </div>
      ) : (
        <>
          <ul className="space-y-3">
            {items.map((item) => (
              <li key={item.id}>
                <button
                  type="button"
                  onClick={() => openItem(item.id)}
                  className="block w-full text-left bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) p-4 sm:p-5 hover:border-primary-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                >
                  <div className="flex flex-wrap items-center gap-2">
                    <Badge className={STATUS_STYLE[item.status]}>{item.status_label}</Badge>
                    <Badge className="border-gray-200 bg-white text-gray-700">{item.category_label}</Badge>
                    <span className="ml-auto font-mono text-xs text-gray-500">{item.reference}</span>
                  </div>
                  <p className="mt-2 font-semibold text-gray-900 wrap-break-word">{item.subject}</p>
                  <p className="mt-1 text-sm text-gray-600 line-clamp-2 wrap-break-word">{item.message}</p>
                  <p className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500">
                    <span>{formatShortDate(item.created_at)}</span>
                    <span className="inline-flex items-center gap-1"><FiUser size={12} aria-hidden="true" />{item.name || 'Anonymous'}</span>
                    {item.listing && <span className="inline-flex items-center gap-1"><FiMapPin size={12} aria-hidden="true" />{item.listing.name}</span>}
                    {item.municipality?.name && <span>{item.municipality.name}</span>}
                    {item.wants_reply && <span className="inline-flex items-center gap-1 font-medium text-primary-700"><FiMail size={12} aria-hidden="true" />Wants a reply</span>}
                  </p>
                </button>
              </li>
            ))}
          </ul>
          <Pagination
            currentPage={data.current_page}
            lastPage={data.last_page}
            onPageChange={(value) => { setLoading(true); setPage(value); window.scrollTo({ top: 0, behavior: 'smooth' }) }}
          />
        </>
      )}

      {openId && <FeedbackDetail id={openId} statuses={data?.statuses} onClose={closeItem} onChange={changed} />}
    </div>
  )
}

function FeedbackDetail({ id, statuses, onClose, onChange }) {
  const [item, setItem] = useState(null)
  const [failed, setFailed] = useState(false)
  const [nextStatus, setNextStatus] = useState('')
  const [note, setNote] = useState('')
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    let current = true
    getAdminFeedbackItem(id)
      .then((result) => { if (current) { setItem(result.feedback); setNextStatus(result.feedback.status) } })
      .catch((error) => { if (current) { setFailed(true); showApiError(error, 'This feedback could not be opened.') } })
    return () => { current = false }
  }, [id])

  const save = async () => {
    const statusChanged = nextStatus !== item.status
    if (!statusChanged && !note.trim()) return
    setSaving(true)
    try {
      const result = statusChanged
        ? await updateAdminFeedback(item.id, { status: nextStatus, note: note.trim() || undefined })
        : await addAdminFeedbackNote(item.id, note.trim())
      setItem(result.feedback)
      setNextStatus(result.feedback.status)
      setNote('')
      toast.success(statusChanged ? `Marked as ${result.feedback.status_label}.` : 'Note added.')
      onChange()
    } catch (error) {
      showApiError(error, 'Your changes could not be saved.')
    } finally {
      setSaving(false)
    }
  }

  const canManage = item && item.can_manage !== false
  const statusOptions = statuses || { pending: 'Pending', under_review: 'Under Review', resolved: 'Resolved', rejected: 'Rejected' }
  const replySubject = item ? encodeURIComponent(`Re: ${item.subject} [${item.reference}]`) : ''

  return (
    <Modal
      title={item ? item.subject : 'Feedback'}
      subtitle={item ? `${item.reference} · ${dateTime(item.created_at)}` : undefined}
      onClose={() => !saving && onClose()}
      busy={saving}
      size="lg"
      footer={canManage ? (
        <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" className="btn-secondary" disabled={saving} onClick={onClose}>Close</button>
          <button type="button" className="btn-primary justify-center" disabled={saving || (nextStatus === item.status && !note.trim())} onClick={save}>
            {saving ? 'Saving…' : nextStatus !== item.status ? 'Save status' : 'Add note'}
          </button>
        </div>
      ) : (
        <div className="flex justify-end"><button type="button" className="btn-secondary" onClick={onClose}>Close</button></div>
      )}
    >
      {!item ? (
        failed ? <p className="text-sm text-gray-600">This feedback could not be opened.</p> : <LoadingSpinner />
      ) : (
        <div className="space-y-5 text-sm">
          <div className="flex flex-wrap gap-2">
            <Badge className={STATUS_STYLE[item.status]}>{item.status_label}</Badge>
            <Badge className="border-gray-200 bg-white text-gray-700">{item.category_label}</Badge>
            {item.module_label && <Badge className="border-gray-200 bg-white text-gray-700">{item.module_label}</Badge>}
          </div>

          <p className="whitespace-pre-line wrap-break-word text-gray-800 leading-relaxed">{item.message}</p>

          <dl className="grid grid-cols-1 gap-3 rounded-xl bg-gray-50 p-4 sm:grid-cols-2">
            <div>
              <dt className="text-xs font-semibold uppercase tracking-wider text-gray-500">From</dt>
              <dd className="mt-1 text-gray-900 wrap-break-word">{item.name || 'Anonymous'}</dd>
              {item.email ? (
                <dd className="mt-0.5">
                  <a href={`mailto:${item.email}?subject=${replySubject}`} className="inline-flex items-center gap-1 font-medium text-primary-700 hover:text-primary-800 break-all">
                    <FiMail size={13} aria-hidden="true" /> {item.email}
                  </a>
                </dd>
              ) : (
                <dd className="mt-0.5 text-gray-500">No email given</dd>
              )}
              {item.wants_reply && <dd className="mt-1 text-xs font-medium text-primary-700">Asked for a reply by email</dd>}
            </div>
            <div>
              <dt className="text-xs font-semibold uppercase tracking-wider text-gray-500">About</dt>
              {item.listing ? (
                <dd className="mt-1 text-gray-900 wrap-break-word">
                  {item.listing.path ? (
                    <a href={item.listing.path} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 font-medium text-primary-700 hover:text-primary-800">
                      {item.listing.name} <FiExternalLink size={12} aria-hidden="true" />
                    </a>
                  ) : item.listing.name}
                </dd>
              ) : (
                <dd className="mt-1 text-gray-500">No specific place</dd>
              )}
              <dd className="mt-0.5 text-gray-500">{item.municipality?.name || 'Province-wide'}</dd>
            </div>
          </dl>

          {item.handled_at && (
            <p className="text-xs text-gray-500">
              Status last changed by {item.handler?.name || 'an administrator'} · {dateTime(item.handled_at)}
            </p>
          )}

          {canManage ? (
            <div className="space-y-3 border-t border-gray-100 pt-5">
              <label className="block">
                <span className="mb-1 block font-medium text-gray-700">Status</span>
                <select value={nextStatus} onChange={(e) => setNextStatus(e.target.value)} className="input-field" disabled={saving}>
                  {Object.entries(statusOptions).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
                </select>
              </label>
              <label className="block">
                <span className="mb-1 block font-medium text-gray-700">Internal note <span className="font-normal text-gray-500">(only admins see this)</span></span>
                <textarea value={note} onChange={(e) => setNote(e.target.value)} rows={3} maxLength={2000} className="input-field" disabled={saving}
                  placeholder="What was checked or done, for example: Called the resort; the new rate is ₱150." />
              </label>
            </div>
          ) : (
            <ManagedBy office={item.managed_by} />
          )}

          <div className="border-t border-gray-100 pt-5">
            <p className="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-gray-500">
              <FiMessageSquare size={13} aria-hidden="true" /> Internal notes
            </p>
            {item.notes?.length ? (
              <ul className="space-y-3">
                {item.notes.map((entry) => (
                  <li key={entry.id} className="rounded-xl border border-gray-200 p-3">
                    <p className="whitespace-pre-line wrap-break-word text-gray-800">{entry.note}</p>
                    <p className="mt-1 text-xs text-gray-500">{entry.author?.name || 'An administrator'} · {dateTime(entry.created_at)}</p>
                  </li>
                ))}
              </ul>
            ) : (
              <p className="text-gray-500">No notes yet.</p>
            )}
          </div>
        </div>
      )}
    </Modal>
  )
}
