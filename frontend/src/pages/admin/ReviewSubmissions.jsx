// frontend/src/pages/admin/ReviewSubmissions.jsx
import { useEffect, useMemo, useState } from 'react'
import { FiFileText, FiEye, FiAlertTriangle, FiTrash2, FiStar } from 'react-icons/fi'
import {
  getAdminSubmissions,
  approveSubmission,
  rejectSubmission,
  warnSubmission,
  clearSubmissionWarning,
  removeSubmission,
} from '../../api/admin'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import SampleBadge from '../../components/common/SampleBadge'
import SubmissionReviewModal from '../../components/admin/SubmissionReviewModal'
import { STATUS_COLORS } from '../../utils/constants'
import { daysUntil, formatShortDate } from '../../utils/helpers'
import toast from 'react-hot-toast'
import { showApiError } from '../../utils/apiErrors'
import { confirmDialog } from '../../utils/dialogs'

const TABS = [
  { key: 'pending', label: 'Pending' },
  { key: 'approved', label: 'Approved' },
  { key: 'rejected', label: 'Rejected' },
  { key: 'removed', label: 'Removed' },
  { key: 'all', label: 'All' },
]

const WARNING_GRACE_DAYS = 7

const DEFAULT_REMOVAL_REASON =
  'Your business has been removed from the STIM platform due to persistent negative visitor reviews that were not resolved within the notice period.'

function warningPreview(name) {
  return `We have observed a continued pattern of negative reviews from visitors for "${name}". We kindly ask that you review recent visitor feedback and take appropriate corrective action. If negative reviews persist, your business will be removed from the system after ${WARNING_GRACE_DAYS} days.`
}

export default function ReviewSubmissions() {
  const [items, setItems] = useState([])
  const [loading, setLoading] = useState(true)
  const [tab, setTab] = useState('pending')
  const [reviewing, setReviewing] = useState(null)
  // Inline action panel on an approved card: { id, type: 'warn' | 'remove' }
  const [panel, setPanel] = useState(null)
  const [panelText, setPanelText] = useState('')
  const [panelError, setPanelError] = useState('')
  const [busy, setBusy] = useState(false)

  const loadData = async () => {
    setLoading(true)
    try {
      const data = await getAdminSubmissions({ per_page: 100 })
      setItems(data.data || [])
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    loadData()
  }, [])

  const filteredItems = useMemo(() => {
    if (tab === 'all') return items
    return items.filter((item) => item.status === tab)
  }, [items, tab])

  const openPanel = (id, type) => {
    setPanel({ id, type })
    setPanelError('')
    setPanelText(type === 'remove' ? DEFAULT_REMOVAL_REASON : '')
  }

  const closePanel = () => {
    setPanel(null)
    setPanelText('')
  }

  const handleApprove = async (id, notes) => {
    try {
      await approveSubmission(id, notes || 'Approved by admin')
      toast.success('Submission approved')
      setReviewing(null)
      loadData()
    } catch (err) {
      showApiError(err, 'Failed to approve')
    }
  }

  const handleReject = async (id, notes) => {
    try {
      await rejectSubmission(id, notes)
      toast.success('Submission rejected')
      setReviewing(null)
      loadData()
    } catch (err) {
      showApiError(err, 'Failed to reject')
    }
  }

  const handleWarn = async (item) => {
    setBusy(true)
    try {
      await warnSubmission(item.id, panelText.trim())
      toast.success('Warning sent to the business owner')
      closePanel()
      loadData()
    } catch (err) {
      showApiError(err, 'Failed to send warning')
    } finally {
      setBusy(false)
    }
  }

  const handleClearWarning = async (item) => {
    if (!(await confirmDialog({ title: `Clear the warning for "${item.business_name}"?`, message: "The owner's dashboard notice will be removed.", confirmLabel: 'Clear warning' }))) return
    try {
      await clearSubmissionWarning(item.id)
      toast.success('Warning cleared')
      loadData()
    } catch (err) {
      showApiError(err, 'Failed to clear warning')
    }
  }

  const handleRemove = async (item) => {
    if (!panelText.trim()) {
      setPanelError('Enter a reason for removal. It is sent to the owner.')
      return
    }
    const left = daysUntil(item.warning_deadline)
    const caution = !item.warned_at
      ? '\n\nNote: the owner was never warned about this.'
      : left > 0
        ? `\n\nNote: the ${WARNING_GRACE_DAYS}-day notice period has not ended yet (${left} day${left === 1 ? '' : 's'} left).`
        : ''
    if (!(await confirmDialog({ title: `Remove "${item.business_name}"?`, message: `Its listing, photos and reviews will be permanently deleted.${caution}`, confirmLabel: 'Remove', danger: true }))) return

    setBusy(true)
    try {
      await removeSubmission(item.id, panelText.trim())
      toast.success('Business removed from the system')
      closePanel()
      loadData()
    } catch (err) {
      showApiError(err, 'Failed to remove business')
    } finally {
      setBusy(false)
    }
  }

  if (loading) return <LoadingSpinner size="lg" />

  return (
    <div>
      <div className="mb-6">
        <p className="text-primary-600 font-medium mb-2">Admin</p>
        <h1 className="text-2xl sm:text-3xl font-heading font-bold text-gray-900">Review Business Submissions</h1>
      </div>

      <div className="flex flex-wrap gap-2 mb-6">
        {TABS.map((t) => {
          const count = t.key === 'all' ? items.length : items.filter((i) => i.status === t.key).length
          return (
            <button
              key={t.key}
              onClick={() => setTab(t.key)}
              className={`px-4 py-2 rounded-lg text-sm font-medium border transition-colors ${
                tab === t.key ? 'bg-primary-500 text-white border-primary-500' : 'bg-white text-gray-600 border-gray-200 hover:border-primary-300'
              }`}
            >
              {t.label} <span className="opacity-70">({count})</span>
            </button>
          )
        })}
      </div>

      {filteredItems.length === 0 ? (
        <div className="bg-white rounded-xl p-12 text-center border border-gray-100">
          <FiFileText className="mx-auto text-gray-300 mb-3" size={32} />
          <p className="text-gray-500">No {tab === 'all' ? '' : tab} submissions.</p>
        </div>
      ) : (
        <div className="space-y-4">
          {filteredItems.map((item) => {
            const isApproved = item.status === 'approved'
            const left = daysUntil(item.warning_deadline)
            const panelOpen = panel?.id === item.id

            return (
              <div key={item.id} className="bg-white rounded-xl p-6 border border-gray-100">
                <div className="flex flex-col md:flex-row md:items-start justify-between gap-4">
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-3 mb-2">
                      <h3 className="font-heading font-semibold text-xl text-gray-900">{item.business_name}</h3>
                      <span className={`text-xs px-2.5 py-1 rounded-full font-medium ${STATUS_COLORS[item.status] || 'bg-gray-100 text-gray-700'}`}>
                        {item.status}
                      </span>
                      {item.is_sample && <SampleBadge />}
                    </div>
                    <p className="text-sm text-gray-500 capitalize mb-3">{item.type.replace('_', ' ')} &middot; Submitted by {item.user?.name}</p>
                    <p className="text-gray-600 mb-3 line-clamp-2">{item.description}</p>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                      <div><span className="text-gray-500">Address:</span> {item.address}</div>
                      <div><span className="text-gray-500">Contact:</span> {item.contact_number}</div>
                    </div>

                    {isApproved && item.reviews_count !== undefined && (
                      <div className="flex flex-wrap items-center gap-x-3 gap-y-1 mt-3 text-sm text-gray-600">
                        <span className="flex items-center gap-1">
                          <FiStar size={14} className="text-yellow-500" />
                          {item.average_rating ?? '—'}
                        </span>
                        <span>&middot; {item.reviews_count} review{item.reviews_count === 1 ? '' : 's'}</span>
                        <span className={item.negative_reviews_count > 0 ? 'text-red-600 font-medium' : ''}>
                          &middot; {item.negative_reviews_count} negative
                        </span>
                      </div>
                    )}

                    {isApproved && item.warned_at && (
                      <div className="mt-4 p-3 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-800 flex flex-wrap items-center justify-between gap-2">
                        <span className="flex items-center gap-2">
                          <FiAlertTriangle size={15} />
                          Warned on {formatShortDate(item.warned_at)} &middot; deadline {formatShortDate(item.warning_deadline)}{' '}
                          <strong>({left > 0 ? `${left} day${left === 1 ? '' : 's'} left` : 'notice period ended'})</strong>
                        </span>
                        <button onClick={() => handleClearWarning(item)} className="text-amber-700 hover:text-amber-900 underline font-medium">
                          Clear warning
                        </button>
                      </div>
                    )}

                    {item.admin_notes && (
                      <div className="mt-4 p-3 bg-gray-50 rounded-lg">
                        <p className="text-sm font-medium text-gray-700">{item.status === 'removed' ? 'Reason for removal' : 'Admin Notes'}</p>
                        <p className="text-sm text-gray-600 mt-1">{item.admin_notes}</p>
                      </div>
                    )}
                  </div>

                  <div className="flex flex-wrap gap-2 shrink-0">
                    <button
                      onClick={() => setReviewing(item)}
                      className={`flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                        item.status === 'pending'
                          ? 'bg-primary-500 text-white hover:bg-primary-600'
                          : 'bg-white border border-gray-200 text-gray-600 hover:border-primary-300'
                      }`}
                    >
                      <FiEye size={15} /> {item.status === 'pending' ? 'Review' : 'View details'}
                    </button>
                    {isApproved && !panelOpen && (
                      <>
                        <button
                          onClick={() => openPanel(item.id, 'warn')}
                          className="flex items-center gap-1.5 px-4 py-2 bg-amber-500 text-white rounded-lg hover:bg-amber-600 text-sm font-medium transition-colors"
                        >
                          <FiAlertTriangle size={15} /> {item.warned_at ? 'Re-send warning' : 'Warn owner'}
                        </button>
                        <button
                          onClick={() => openPanel(item.id, 'remove')}
                          className="flex items-center gap-1.5 px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 text-sm font-medium transition-colors"
                        >
                          <FiTrash2 size={15} /> Delete
                        </button>
                      </>
                    )}
                  </div>
                </div>

                {panelOpen && panel.type === 'warn' && (
                  <div className="mt-4 pt-4 border-t border-gray-100">
                    <p className="text-sm font-medium text-gray-700 mb-1.5">Automated message to the owner</p>
                    <p className="text-sm text-gray-600 bg-amber-50 border border-amber-100 rounded-lg p-3 mb-3">
                      {warningPreview(item.business_name)}
                    </p>
                    <label htmlFor={`warn-note-${item.id}`} className="block text-sm font-medium text-gray-700 mb-1.5">
                      Additional note (optional)
                    </label>
                    <textarea
                      id={`warn-note-${item.id}`}
                      value={panelText}
                      onChange={(e) => setPanelText(e.target.value)}
                      rows={3}
                      autoFocus
                      maxLength={1000}
                      placeholder="e.g. Several visitors reported slow service and cleanliness issues."
                      className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-amber-400"
                    />
                    <div className="flex gap-2 mt-3">
                      <button
                        onClick={() => handleWarn(item)}
                        disabled={busy}
                        className="px-4 py-2 bg-amber-500 text-white rounded-lg hover:bg-amber-600 text-sm font-medium transition-colors disabled:opacity-50"
                      >
                        Send warning
                      </button>
                      <button onClick={closePanel} className="px-4 py-2 bg-white border border-gray-200 text-gray-600 rounded-lg hover:border-gray-300 text-sm font-medium transition-colors">
                        Cancel
                      </button>
                    </div>
                  </div>
                )}

                {panelOpen && panel.type === 'remove' && (
                  <div className="mt-4 pt-4 border-t border-gray-100">
                    <label htmlFor={`remove-reason-${item.id}`} className="block text-sm font-medium text-gray-700 mb-1.5">
                      Reason for removal (sent to the owner)
                    </label>
                    <textarea
                      id={`remove-reason-${item.id}`}
                      name="reason"
                      value={panelText}
                      onChange={(e) => {
                        setPanelText(e.target.value)
                        setPanelError('')
                      }}
                      aria-invalid={!!panelError}
                      rows={3}
                      autoFocus
                      maxLength={1000}
                      className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-red-400"
                    />
                    {panelError && <p className="field-error" role="alert">{panelError}</p>}
                    <p className="text-xs text-gray-500 mt-1">
                      The live listing, its photos and reviews will be deleted. The submission record is kept under the Removed tab.
                    </p>
                    <div className="flex gap-2 mt-3">
                      <button
                        onClick={() => handleRemove(item)}
                        disabled={busy}
                        className="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 text-sm font-medium transition-colors disabled:opacity-50"
                      >
                        Delete listing
                      </button>
                      <button onClick={closePanel} className="px-4 py-2 bg-white border border-gray-200 text-gray-600 rounded-lg hover:border-gray-300 text-sm font-medium transition-colors">
                        Cancel
                      </button>
                    </div>
                  </div>
                )}
              </div>
            )
          })}
        </div>
      )}

      {reviewing && (
        <SubmissionReviewModal
          submission={reviewing}
          onClose={() => setReviewing(null)}
          onApprove={handleApprove}
          onReject={handleReject}
        />
      )}
    </div>
  )
}
