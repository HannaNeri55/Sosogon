
import { useState } from 'react'
import {
  FiX,
  FiCheck,
  FiFileText,
  FiMapPin,
  FiPhone,
  FiClock,
  FiUser,
  FiCalendar,
  FiInfo,
  FiImage,
} from 'react-icons/fi'
import toast from 'react-hot-toast'
import { downloadSubmissionPermit } from '../../api/admin'
import LocationMap from '../maps/LocationMap'
import {
  formatDate,
  getImageUrl,
  IMAGE_FALLBACK_SVG,
} from '../../utils/helpers'
import { STATUS_COLORS } from '../../utils/constants'

function Detail({ icon: Icon, label, children }) {
  return (
    <div className="flex items-start gap-2 text-sm">
      <Icon size={15} className="text-gray-400 mt-0.5 shrink-0" />

      <div className="min-w-0">
        <p className="text-gray-500 text-xs">{label}</p>
        <div className="text-gray-800 break-words">
          {children || '—'}
        </div>
      </div>
    </div>
  )
}

export default function SubmissionReviewModal({
  submission,
  onClose,
  onApprove,
  onReject,
}) {
  const [mode, setMode] = useState(null)
  const [notes, setNotes] = useState('')
  const [busy, setBusy] = useState(false)

  const isPending = submission.status === 'pending'

  const location = [
    submission.address,
    submission.barangay?.name,
    submission.municipality?.name,
  ]
    .filter(Boolean)
    .join(', ')

  const openPermit = async () => {
    try {
      const blob = await downloadSubmissionPermit(submission.id)

      const url = URL.createObjectURL(blob)
      const newWindow = window.open(url, '_blank', 'noopener')

      if (!newWindow) {
        toast.error(
          'Your browser blocked the permit window. Please allow pop-ups.'
        )
      }

      setTimeout(() => {
        URL.revokeObjectURL(url)
      }, 60000)
    } catch {
      toast.error('Could not open the business permit.')
    }
  }

  const confirmDecision = async () => {
    if (busy || !mode) return

    if (mode === 'reject' && !notes.trim()) {
      toast.error('Please enter a rejection reason.')
      return
    }

    setBusy(true)

    try {
      if (mode === 'approve') {
        await onApprove(
          submission.id,
          notes.trim()
        )
      } else {
        await onReject(
          submission.id,
          notes.trim()
        )
      }
    } finally {
      setBusy(false)
    }
  }

  return (
    <div
      className="fixed inset-0 bg-black/40 flex items-center justify-center p-4 z-50"
      role="dialog"
      aria-modal="true"
      aria-label="Review business registration"
    >
      <div className="bg-white rounded-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">

        {/* ==================== HEADER ==================== */}

        <div className="flex items-start justify-between gap-3 p-5 sm:p-6 pb-4 border-b border-gray-100">
          <div className="min-w-0">
            <p className="text-xs font-semibold uppercase tracking-wide text-primary-600 mb-1">
              Business Registration Review
            </p>

            <h2 className="text-lg font-heading font-semibold text-gray-900">
              {submission.business_name}
            </h2>

            <p className="text-sm text-gray-500 capitalize mt-1">
              {(submission.type || '')
                .replaceAll('_', ' ')}
              {' · '}
              Submitted {formatDate(submission.created_at)}
            </p>
          </div>

          <div className="flex items-center gap-3 shrink-0">
            <span
              className={`text-xs px-2.5 py-1 rounded-full font-medium capitalize ${
                STATUS_COLORS[submission.status] ||
                'bg-gray-100 text-gray-700'
              }`}
            >
              {submission.status}
            </span>

            <button
              type="button"
              onClick={onClose}
              disabled={busy}
              className="text-gray-400 hover:text-gray-600 disabled:opacity-50"
              aria-label="Close"
            >
              <FiX size={20} />
            </button>
          </div>
        </div>

        {/* ==================== BUSINESS DETAILS ==================== */}

        <div className="p-5 sm:p-6 space-y-5">

          {/* BUSINESS PHOTO */}

          {submission.image_path && (
            <div>
              <img
                src={getImageUrl(submission.image_path)}
                alt={submission.business_name}
                onError={(event) => {
                  event.currentTarget.onerror = null
                  event.currentTarget.src =
                    IMAGE_FALLBACK_SVG
                }}
                className="w-full h-44 sm:h-56 object-cover rounded-lg"
              />

              <p className="text-xs text-gray-500 mt-2 flex items-center gap-1.5">
                <FiImage size={13} />
                Initial business photo submitted during registration
              </p>
            </div>
          )}

          {/* DESCRIPTION */}

          <div>
            <p className="text-xs text-gray-500 mb-1">
              Description
            </p>

            <p className="text-gray-700 whitespace-pre-line">
              {submission.description || 'No description provided.'}
            </p>
          </div>

          {/* OWNER & BUSINESS INFORMATION */}

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <Detail icon={FiUser} label="Owner">
              {submission.user?.name || '—'}

              {submission.user?.email && (
                <span className="block text-gray-500">
                  {submission.user.email}
                </span>
              )}
            </Detail>

            <Detail icon={FiPhone} label="Contact number">
              {submission.contact_number}
            </Detail>

            <Detail icon={FiMapPin} label="Address">
              {location}
            </Detail>

            <Detail icon={FiClock} label="Operating hours">
              {submission.operating_hours}
            </Detail>

            {submission.reviewed_at && (
              <Detail
                icon={FiCalendar}
                label="Reviewed on"
              >
                {formatDate(submission.reviewed_at)}
              </Detail>
            )}
          </div>

          {/* LOCATION MAP */}

          {submission.latitude && submission.longitude && (
            <div className="rounded-lg overflow-hidden border border-gray-100">
              <LocationMap
                latitude={submission.latitude}
                longitude={submission.longitude}
                title={submission.business_name}
              />
            </div>
          )}

          {/* BUSINESS PERMIT */}

          {submission.business_permit_path && (
            <button
              type="button"
              onClick={openPermit}
              className="flex items-center gap-2 px-4 py-2 border border-gray-200 rounded-lg text-sm font-medium text-gray-700 hover:border-primary-300"
            >
              <FiFileText size={15} />
              View business permit
            </button>
          )}

          {/* REGISTRATION VS PUBLICATION EXPLANATION */}

          <div className="rounded-xl bg-blue-50 border border-blue-100 p-4">
            <div className="flex items-start gap-3">
              <FiInfo
                size={19}
                className="text-blue-600 shrink-0 mt-0.5"
              />

              <div>
                <h3 className="text-sm font-semibold text-blue-900">
                  Registration Approval vs. Listing Publication
                </h3>

                <p className="text-sm text-blue-800 mt-2 leading-relaxed">
                  This review verifies the business registration
                  and its submitted information, including the
                  business permit.
                </p>

                <p className="text-sm text-blue-800 mt-2 leading-relaxed">
                  Approving this registration allows the business
                  owner to complete their listing. It does not
                  automatically publish the listing to tourists.
                </p>

                <p className="text-sm text-blue-800 mt-2 leading-relaxed">
                  Before publication, the owner must save at least
                  5 and no more than 100 gallery photos, complete
                  the listing, and submit it for a separate
                  Tourism Office publication review.
                </p>
              </div>
            </div>
          </div>

          {/* ADMIN NOTES */}

          {submission.admin_notes && (
            <div className="p-3 bg-gray-50 rounded-lg">
              <p className="text-sm font-medium text-gray-700">
                {submission.status === 'removed'
                  ? 'Reason for removal'
                  : 'Admin Notes'}
              </p>

              <p className="text-sm text-gray-600 mt-1 whitespace-pre-line">
                {submission.admin_notes}
              </p>
            </div>
          )}
        </div>

        {/* ==================== APPROVAL / REJECTION ==================== */}

        <div className="sticky bottom-0 bg-white sm:static p-5 sm:p-6 pt-4 border-t border-gray-100">

          {isPending && mode ? (
            <div>
              <label
                htmlFor="review-notes"
                className="block text-sm font-medium text-gray-700 mb-1.5"
              >
                {mode === 'approve'
                  ? 'Note to the owner (optional)'
                  : 'Reason for rejection'}
              </label>

              <textarea
                id="review-notes"
                value={notes}
                onChange={(event) =>
                  setNotes(event.target.value)
                }
                rows={3}
                autoFocus
                maxLength={1000}
                placeholder={
                  mode === 'approve'
                    ? 'e.g. Registration verified. You may now complete your business listing.'
                    : 'Explain why this business registration is being rejected...'
                }
                className={`w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-700 focus:outline-none focus:ring-2 ${
                  mode === 'approve'
                    ? 'focus:ring-green-400'
                    : 'focus:ring-red-400'
                }`}
              />

              {mode === 'approve' && (
                <p className="text-xs text-blue-700 mt-2">
                  This approves the registration only.
                  The business listing will still require
                  separate publication approval.
                </p>
              )}

              <div className="flex gap-2 mt-3">
                <button
                  type="button"
                  onClick={confirmDecision}
                  disabled={busy}
                  className={`flex-1 sm:flex-none px-4 py-2.5 sm:py-2 text-white rounded-lg text-sm font-medium transition-colors disabled:opacity-50 ${
                    mode === 'approve'
                      ? 'bg-green-600 hover:bg-green-700'
                      : 'bg-red-600 hover:bg-red-700'
                  }`}
                >
                  {busy
                    ? 'Processing...'
                    : mode === 'approve'
                      ? 'Confirm Registration Approval'
                      : 'Confirm Rejection'}
                </button>

                <button
                  type="button"
                  onClick={() => {
                    setMode(null)
                    setNotes('')
                  }}
                  disabled={busy}
                  className="flex-1 sm:flex-none px-4 py-2.5 sm:py-2 bg-white border border-gray-200 text-gray-600 rounded-lg hover:border-gray-300 text-sm font-medium transition-colors disabled:opacity-50"
                >
                  Back
                </button>
              </div>
            </div>
          ) : isPending ? (
            <div className="flex gap-2 sm:justify-end">
              <button
                type="button"
                onClick={() => setMode('approve')}
                className="flex-1 sm:flex-none justify-center flex items-center gap-1.5 px-4 py-2.5 sm:py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm font-medium transition-colors"
              >
                <FiCheck size={15} />
                Approve Registration
              </button>

              <button
                type="button"
                onClick={() => setMode('reject')}
                className="flex-1 sm:flex-none justify-center flex items-center gap-1.5 px-4 py-2.5 sm:py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 text-sm font-medium transition-colors"
              >
                <FiX size={15} />
                Reject
              </button>
            </div>
          ) : (
            <div className="flex justify-end">
              <button
                type="button"
                onClick={onClose}
                className="px-4 py-2 bg-white border border-gray-200 text-gray-600 rounded-lg hover:border-gray-300 text-sm font-medium transition-colors"
              >
                Close
              </button>
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
