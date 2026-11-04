// frontend/src/pages/admin/OwnerRegistrations.jsx
import { useCallback, useEffect, useState } from 'react'
import { FiFileText, FiCheck, FiX, FiRefreshCw, FiAlertTriangle } from 'react-icons/fi'
import toast from 'react-hot-toast'
import {
  getOwnerApplications,
  approveOwnerApplication,
  rejectOwnerApplication,
  downloadOwnerApplicationPermit,
} from '../../api/admin'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import { formatShortDate } from '../../utils/helpers'

/**
 * Business Owner registration review (account approval). Approving activates
 * the owner's account so they can sign in and submit their listing; the listing
 * itself is still reviewed separately in Business Registrations and Listing
 * Publication Review.
 */

const FILTERS = [
  { value: 'pending', label: 'Pending' },
  { value: 'rejected', label: 'Rejected' },
  { value: 'approved', label: 'Approved' },
  { value: '', label: 'All' },
]

const STATUS_STYLES = {
  pending: 'bg-yellow-100 text-yellow-800',
  approved: 'bg-green-100 text-green-700',
  rejected: 'bg-red-100 text-red-700',
}

const TYPE_LABELS = {
  accommodation: 'Accommodation',
  food_beverage: 'Food & Beverage',
  retail_product: 'Retail / Local Products',
}

const REASON_MIN = 10
const REASON_MAX = 1000

const PERMIT_EXTENSIONS = {
  'application/pdf': 'pdf',
  'application/msword': 'doc',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'docx',
}

function errorMessage(error, fallback) {
  const data = error?.response?.data
  return Object.values(data?.errors || {}).flat()[0] || data?.message || fallback
}

function Detail({ label, children }) {
  return (
    <div>
      <dt className="text-xs text-gray-500">{label}</dt>
      <dd className="text-sm text-gray-900 wrap-anywhere">{children || '—'}</dd>
    </div>
  )
}

export default function OwnerRegistrations() {
  const [filter, setFilter] = useState('pending')
  const [items, setItems] = useState([])
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState('')
  const [actingId, setActingId] = useState(null)
  const [rejecting, setRejecting] = useState(null) // { id, reason, error }

  const load = useCallback(async () => {
    setLoading(true)
    setLoadError('')
    try {
      const data = await getOwnerApplications(filter ? { status: filter } : {})
      setItems(Array.isArray(data) ? data : [])
    } catch (error) {
      setLoadError(errorMessage(error, 'Could not load owner registrations.'))
    } finally {
      setLoading(false)
    }
  }, [filter])

  useEffect(() => {
    load()
  }, [load])

  const viewPermit = async (application) => {
    setActingId(application.id)
    // Open the tab during the click: a window opened after the download finishes is
    // treated as an unrequested pop-up and blocked. (With 'noopener', window.open
    // always returns null, so the tab is opened without it and detached below.)
    const tab = window.open('', '_blank')
    if (tab) tab.opener = null
    try {
      const blob = await downloadOwnerApplicationPermit(application.id)
      const url = URL.createObjectURL(blob)
      if (blob.type === 'application/pdf' && tab) {
        // Preview in the new tab; the file only ever comes from the authenticated endpoint.
        tab.location.href = url
      } else {
        // DOC/DOCX, or a PDF when pop-ups are blocked: download it instead.
        tab?.close()
        const link = document.createElement('a')
        link.href = url
        link.download = `business-permit-${application.id}.${PERMIT_EXTENSIONS[blob.type] || 'doc'}`
        link.click()
      }
      setTimeout(() => URL.revokeObjectURL(url), 60_000)
    } catch (error) {
      tab?.close()
      const status = error.response?.status
      toast.error(
        status === 404 ? 'No permit file was found for this registration.'
          : status === 401 ? 'Your session has expired. Please sign in again.'
            : status === 403 ? 'You do not have access to this business permit.'
              : 'Could not open the business permit.'
      )
    } finally {
      setActingId(null)
    }
  }

  const approve = async (application) => {
    if (actingId) return
    if (!window.confirm(`Approve the registration for "${application.business_name}"? The owner's account will be activated so they can sign in and submit their listing.`)) return

    setActingId(application.id)
    try {
      const response = await approveOwnerApplication(application.id)
      toast.success(response.message || 'Registration approved.')
      await load()
    } catch (error) {
      toast.error(errorMessage(error, 'Could not approve this registration.'))
      if (error.response?.status === 409) await load()
    } finally {
      setActingId(null)
    }
  }

  const submitRejection = async (event) => {
    event.preventDefault()
    if (!rejecting || actingId) return

    const reason = rejecting.reason.trim()
    if (reason.length < REASON_MIN) {
      setRejecting({ ...rejecting, error: `Please give a reason of at least ${REASON_MIN} characters so the owner knows what to correct.` })
      return
    }

    setActingId(rejecting.id)
    try {
      const response = await rejectOwnerApplication(rejecting.id, reason)
      toast.success(response.message || 'Registration rejected.')
      setRejecting(null)
      await load()
    } catch (error) {
      setRejecting((current) => current && { ...current, error: errorMessage(error, 'Could not reject this registration.') })
      if (error.response?.status === 409) await load()
    } finally {
      setActingId(null)
    }
  }

  return (
    <div>
      <div className="mb-6">
        <p className="text-primary-600 font-medium mb-2">Admin</p>
        <h1 className="text-2xl sm:text-3xl font-heading font-bold text-gray-900">Owner Registrations</h1>
        <p className="text-sm text-gray-500 mt-2 max-w-3xl">
          Review new Business Owner accounts and their business permits. Approving activates the account only; the
          owner&apos;s listing is still reviewed separately in Business Registrations and Listing Publication Review.
        </p>
      </div>

      <div className="flex flex-wrap items-center gap-2 mb-5" role="tablist" aria-label="Filter registrations by status">
        {FILTERS.map((option) => (
          <button
            key={option.value || 'all'}
            type="button"
            role="tab"
            aria-selected={filter === option.value}
            onClick={() => setFilter(option.value)}
            className={`min-h-9 px-3.5 rounded-full text-sm font-medium border transition-colors ${
              filter === option.value ? 'bg-primary-600 text-white border-primary-600' : 'bg-white text-gray-700 border-gray-200 hover:bg-gray-50'
            }`}
          >
            {option.label}
          </button>
        ))}
        <button type="button" onClick={load} disabled={loading} className="ml-auto inline-flex items-center gap-1.5 min-h-9 px-3 text-sm text-gray-600 hover:text-gray-900 disabled:opacity-50">
          <FiRefreshCw size={14} aria-hidden="true" /> Refresh
        </button>
      </div>

      {loading ? (
        <LoadingSpinner size="lg" />
      ) : loadError ? (
        <div role="alert" className="bg-red-50 border border-red-200 text-red-800 rounded-xl p-5 text-sm flex flex-wrap items-center gap-3">
          <FiAlertTriangle aria-hidden="true" /> {loadError}
          <button type="button" onClick={load} className="font-semibold underline">Try again</button>
        </div>
      ) : items.length === 0 ? (
        <div className="bg-white border border-gray-100 rounded-xl p-10 text-center text-sm text-gray-500">
          {filter === 'pending' ? 'No registrations are waiting for review.' : 'No registrations to show.'}
        </div>
      ) : (
        <ul className="space-y-4">
          {items.map((application) => {
            const busy = actingId === application.id
            const isPending = application.status === 'pending'
            const resubmitted = isPending && application.resubmitted_at
            const isRejecting = rejecting?.id === application.id

            return (
              <li key={application.id} className="bg-white border border-gray-100 rounded-xl p-5 sm:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3 mb-4">
                  <div className="min-w-0">
                    <h2 className="font-heading font-semibold text-xl text-gray-900 wrap-anywhere">{application.business_name}</h2>
                    <p className="text-sm text-gray-500">
                      {TYPE_LABELS[application.business_type] || application.business_type} · Registered {formatShortDate(application.created_at)}
                    </p>
                  </div>
                  <div className="flex flex-wrap gap-2">
                    {resubmitted && <span className="text-xs font-medium px-2.5 py-1 rounded-full bg-blue-100 text-blue-700">Resubmitted {formatShortDate(application.resubmitted_at)}</span>}
                    <span className={`text-xs font-medium px-2.5 py-1 rounded-full capitalize ${STATUS_STYLES[application.status] || 'bg-gray-100 text-gray-600'}`}>
                      {application.status}
                    </span>
                  </div>
                </div>

                <dl className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-3 mb-4">
                  <Detail label="Owner">{application.user?.name}</Detail>
                  <Detail label="Email">{application.user?.email}</Detail>
                  <Detail label="Phone">{application.user?.phone}</Detail>
                  <Detail label="Business address">{application.business_address}</Detail>
                  <Detail label="Municipality / barangay">
                    {[application.municipality?.name, application.barangay?.name].filter(Boolean).join(' · ')}
                  </Detail>
                  <Detail label="Business permit no.">{application.business_permit_number}</Detail>
                  <Detail label="Account status">{application.user?.status}</Detail>
                  {application.reviewed_at && (
                    <Detail label="Last reviewed">
                      {formatShortDate(application.reviewed_at)}{application.reviewer?.name ? ` by ${application.reviewer.name}` : ''}
                    </Detail>
                  )}
                </dl>

                {application.rejection_reason && (
                  <div className={`rounded-lg p-3 mb-4 text-sm ${application.status === 'rejected' ? 'bg-red-50 text-red-800' : 'bg-gray-50 text-gray-700'}`}>
                    <p className="font-medium">{application.status === 'rejected' ? 'Rejection reason sent to the owner' : 'Previous rejection reason'}</p>
                    <p className="whitespace-pre-line mt-1">{application.rejection_reason}</p>
                  </div>
                )}

                <div className="flex flex-wrap items-center gap-2">
                  {application.has_permit ? (
                    <button type="button" onClick={() => viewPermit(application)} disabled={busy}
                      className="inline-flex items-center gap-2 min-h-10 px-4 rounded-lg border border-gray-200 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50">
                      <FiFileText aria-hidden="true" /> View permit
                    </button>
                  ) : (
                    <span className="text-sm text-amber-700">No permit file on record.</span>
                  )}

                  {isPending && !isRejecting && (
                    <>
                      <button type="button" onClick={() => approve(application)} disabled={Boolean(actingId)}
                        className="inline-flex items-center gap-2 min-h-10 px-4 rounded-lg bg-green-600 text-white text-sm font-semibold hover:bg-green-700 disabled:opacity-50">
                        <FiCheck aria-hidden="true" /> {busy ? 'Working…' : 'Approve registration'}
                      </button>
                      <button type="button" onClick={() => setRejecting({ id: application.id, reason: '', error: '' })} disabled={Boolean(actingId)}
                        className="inline-flex items-center gap-2 min-h-10 px-4 rounded-lg border border-red-200 text-red-700 text-sm font-semibold hover:bg-red-50 disabled:opacity-50">
                        <FiX aria-hidden="true" /> Reject
                      </button>
                    </>
                  )}
                  {application.status === 'rejected' && (
                    <span className="text-sm text-gray-500">Waiting for the owner to correct and resubmit.</span>
                  )}
                </div>

                {isPending && isRejecting && (
                  <form onSubmit={submitRejection} className="mt-4 border-t border-gray-100 pt-4 space-y-2">
                    <label htmlFor={`reason-${application.id}`} className="block text-sm font-medium text-gray-700">
                      Reason for rejection (shown to the owner)
                    </label>
                    <textarea
                      id={`reason-${application.id}`}
                      className="input-field min-h-24"
                      maxLength={REASON_MAX}
                      value={rejecting.reason}
                      onChange={(e) => setRejecting({ ...rejecting, reason: e.target.value, error: '' })}
                      aria-invalid={Boolean(rejecting.error)}
                      aria-describedby={`reason-help-${application.id}`}
                      placeholder="For example: The permit has expired. Please upload the current year's business permit."
                      required
                    />
                    <p id={`reason-help-${application.id}`} className={`text-xs ${rejecting.error ? 'text-red-600' : 'text-gray-500'}`}>
                      {rejecting.error || `At least ${REASON_MIN} characters (${rejecting.reason.trim().length}/${REASON_MAX}).`}
                    </p>
                    <div className="flex flex-wrap gap-2">
                      <button type="submit" disabled={busy}
                        className="inline-flex items-center gap-2 min-h-10 px-4 rounded-lg bg-red-600 text-white text-sm font-semibold hover:bg-red-700 disabled:opacity-50">
                        {busy ? 'Rejecting…' : 'Reject registration'}
                      </button>
                      <button type="button" onClick={() => setRejecting(null)} disabled={busy}
                        className="min-h-10 px-4 rounded-lg text-sm text-gray-600 hover:bg-gray-100 disabled:opacity-50">
                        Cancel
                      </button>
                    </div>
                  </form>
                )}
              </li>
            )
          })}
        </ul>
      )}
    </div>
  )
}
