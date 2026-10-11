// frontend/src/components/common/ReviewSection.jsx
import { useState } from 'react'
import { useAuth } from '../../contexts/AuthContext'
import { submitRating } from '../../api/ratings'
import { StarDisplay, StarInput } from './StarRating'
import toast from 'react-hot-toast'

export default function ReviewSection({ item, rateableType, onReviewed }) {
  const { isAuthenticated, isTourist } = useAuth()
  const [rating, setRating] = useState(0)
  const [comment, setComment] = useState('')
  const [submitting, setSubmitting] = useState(false)

  const handleSubmit = async (e) => {
    e.preventDefault()
    if (!rating) return toast.error('Please select a rating')

    setSubmitting(true)
    try {
      await submitRating({ rateable_type: rateableType, rateable_id: item.id, score: rating, comment })
      toast.success('Review submitted successfully')
      setRating(0)
      setComment('')
      onReviewed?.()
    } catch (err) {
      toast.error(err.response?.data?.message || 'Failed to submit review')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="card p-8">
      <h2 className="text-2xl font-heading font-semibold text-gray-900 mb-6">Ratings and Reviews</h2>

      {isAuthenticated && isTourist && (
        <form onSubmit={handleSubmit} className="mb-8 pb-8 border-b border-gray-100">
          <div className="mb-4">
            <label className="block text-sm font-medium text-gray-700 mb-2">Your Rating</label>
            <StarInput value={rating} onChange={setRating} />
          </div>
          <textarea
            value={comment}
            onChange={(e) => setComment(e.target.value)}
            placeholder="Share your experience..."
            rows={4}
            className="input-field mb-4"
          />
          <button type="submit" className="btn-primary" disabled={submitting}>
            {submitting ? 'Submitting...' : 'Submit Review'}
          </button>
        </form>
      )}

      <div className="space-y-4">
        {(!item.ratings || item.ratings.length === 0) ? (
          <p className="text-gray-500">No reviews yet.</p>
        ) : (
          item.ratings.map((review) => (
            <div key={review.id} className="border-b border-gray-100 pb-4 last:border-b-0">
              <div className="flex items-center justify-between mb-2">
                <p className="font-medium text-gray-900">{review.user?.name}</p>
                <StarDisplay rating={review.score} size={14} />
              </div>
              {review.comment && <p className="text-sm text-gray-600">{review.comment}</p>}
            </div>
          ))
        )}
      </div>
    </div>
  )
}