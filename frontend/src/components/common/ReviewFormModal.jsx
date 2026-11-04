// frontend/src/components/common/ReviewFormModal.jsx
import { useEffect, useRef, useState } from 'react'
import { FiX } from 'react-icons/fi'
import toast from 'react-hot-toast'
import { submitRating } from '../../api/ratings'
import { StarInput } from './StarRating'

const COMMENT_MAX = 1000
const SCORE_LABELS = ['', 'Poor', 'Fair', 'Good', 'Very good', 'Excellent']

export default function ReviewFormModal({ open, itemName, itemId, rateableType, onClose, onSubmitted }) {
  const [rating, setRating] = useState(0)
  const [comment, setComment] = useState('')
  const [errors, setErrors] = useState({})
  const [submitting, setSubmitting] = useState(false)
  const dialogRef = useRef(null)

  const close = () => {
    if (!submitting) onClose()
  }

  useEffect(() => {
    if (!open) return undefined

    const previousFocus = document.activeElement
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    dialogRef.current?.focus()

    return () => {
      document.body.style.overflow = previousOverflow
      previousFocus?.focus?.()
    }
  }, [open])

  if (!open) return null

  const handleSubmit = async (e) => {
    e.preventDefault()
    if (submitting) return
    if (!rating) {
      setErrors({ score: 'Please select a star rating.' })
      return
    }

    setSubmitting(true)
    setErrors({})
    try {
      await submitRating({ rateable_type: rateableType, rateable_id: itemId, score: rating, comment })
      toast.success('Thank you! Your review has been posted.')
      setRating(0)
      setComment('')
      onSubmitted?.()
      onClose()
    } catch (err) {
      // Keep what the Tourist typed so they can correct and resubmit.
      const data = err.response?.data
      setErrors({
        score: data?.errors?.score?.[0],
        comment: data?.errors?.comment?.[0],
        general: data?.errors ? null : data?.message || 'Could not submit your review. Please try again.',
      })
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/40 p-0 sm:p-4"
      onMouseDown={(e) => e.target === e.currentTarget && close()}
    >
      <div
        ref={dialogRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby="review-modal-title"
        tabIndex={-1}
        onKeyDown={(e) => e.key === 'Escape' && close()}
        className="w-full sm:max-w-lg max-h-[90vh] overflow-y-auto rounded-t-2xl sm:rounded-xl bg-white shadow-xl outline-none"
      >
        <div className="flex items-start justify-between gap-4 border-b border-gray-100 px-6 py-4">
          <div className="min-w-0">
            <h2 id="review-modal-title" className="text-xl font-heading font-semibold text-gray-900">
              Write a Review
            </h2>
            <p className="mt-0.5 text-sm text-gray-500 truncate">{itemName}</p>
          </div>
          <button
            type="button"
            onClick={close}
            disabled={submitting}
            aria-label="Close"
            className="-mr-2 flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-gray-500 hover:bg-gray-100 disabled:opacity-50"
          >
            <FiX size={20} />
          </button>
        </div>

        <form onSubmit={handleSubmit} noValidate className="px-6 py-5">
          <fieldset className="mb-5">
            <legend className="block text-sm font-medium text-gray-700 mb-2">
              Your rating <span className="text-red-600">*</span>
            </legend>
            <div className="flex items-center gap-3">
              <StarInput
                value={rating}
                onChange={(value) => {
                  setRating(value)
                  setErrors((prev) => ({ ...prev, score: null }))
                }}
              />
              {rating > 0 && <span className="text-sm text-gray-600">{SCORE_LABELS[rating]}</span>}
            </div>
            {errors.score && (
              <p role="alert" className="mt-2 text-sm text-red-600">{errors.score}</p>
            )}
          </fieldset>

          <div className="mb-5">
            <label htmlFor="review-comment" className="block text-sm font-medium text-gray-700 mb-2">
              Your review <span className="font-normal text-gray-500">(optional)</span>
            </label>
            <textarea
              id="review-comment"
              value={comment}
              onChange={(e) => setComment(e.target.value)}
              maxLength={COMMENT_MAX}
              rows={5}
              placeholder="Share your experience with other travelers..."
              aria-invalid={Boolean(errors.comment)}
              aria-describedby="review-comment-help"
              className="input-field resize-y"
            />
            <div id="review-comment-help" className="mt-1 flex justify-between gap-2 text-xs">
              <span className="text-red-600" role={errors.comment ? 'alert' : undefined}>{errors.comment}</span>
              <span className="text-gray-500 tabular-nums">{comment.length}/{COMMENT_MAX}</span>
            </div>
          </div>

          {errors.general && (
            <p role="alert" className="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{errors.general}</p>
          )}

          <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-3">
            <button type="button" onClick={close} disabled={submitting} className="btn-secondary justify-center disabled:opacity-50">
              Cancel
            </button>
            <button type="submit" disabled={submitting} aria-busy={submitting} className="btn-primary justify-center disabled:opacity-60">
              {submitting ? 'Submitting...' : 'Submit Review'}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
