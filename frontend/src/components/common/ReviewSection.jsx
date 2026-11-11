// frontend/src/components/common/ReviewSection.jsx
import { useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { FiEdit2 } from 'react-icons/fi'
import { useAuth } from '../../contexts/AuthContext'
import { StarDisplay } from './StarRating'
import ReviewFormModal from './ReviewFormModal'
import OwnerReply from './OwnerReply'
import { formatShortDate, parseReview } from '../../utils/helpers'
import toast from 'react-hot-toast'

export default function ReviewSection({ item, rateableType, onReviewed }) {
  const { user, isAuthenticated, isTourist, isBusinessOwner } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [modalOpen, setModalOpen] = useState(false)

  const reviews = item.ratings || []
  const isOwner = isBusinessOwner && user && item.user_id != null && Number(item.user_id) === Number(user.id)
  const hasReviewed = isTourist && reviews.some((review) => Number(review.user_id) === Number(user?.id))
  // Admins and Business Owners cannot submit reviews, so they don't see the button.
  const showWriteButton = !isAuthenticated || isTourist

  const handleWriteClick = () => {
    if (!isAuthenticated) {
      toast('Please log in with a tourist account to write a review.')
      navigate('/login', { state: { from: location.pathname } })
      return
    }
    setModalOpen(true)
  }

  return (
    <div className="card p-6 sm:p-8">
      <div className="flex flex-wrap items-center justify-between gap-4 mb-6">
        <h2 className="text-2xl font-heading font-semibold text-gray-900">Ratings and Reviews</h2>

        {showWriteButton && (
          <button
            type="button"
            onClick={handleWriteClick}
            disabled={hasReviewed}
            className="btn-primary disabled:cursor-not-allowed disabled:opacity-60"
          >
            <FiEdit2 size={16} aria-hidden="true" />
            {hasReviewed ? 'You reviewed this' : 'Write a Review'}
          </button>
        )}
      </div>

      <div className="space-y-4">
        {reviews.length === 0 ? (
          <p className="text-gray-500">No reviews yet.</p>
        ) : (
          reviews.map((review) => {
            const { comment } = parseReview(review)
            return (
              <div key={review.id} className="border-b border-gray-100 pb-4 last:border-b-0">
                <div className="flex items-start justify-between gap-3 mb-2">
                  <div className="min-w-0">
                    <p className="font-medium text-gray-900">{review.user?.name || 'Guest'}</p>
                    {review.created_at && (
                      <p className="text-xs text-gray-500 mt-0.5">{formatShortDate(review.created_at)}</p>
                    )}
                  </div>
                  <StarDisplay rating={review.score} size={14} />
                </div>
                {comment && <p className="text-sm text-gray-600">{comment}</p>}

                <OwnerReply review={review} canReply={isOwner} onSaved={onReviewed} />
              </div>
            )
          })
        )}
      </div>

      <ReviewFormModal
        open={modalOpen}
        itemName={item.name}
        itemId={item.id}
        rateableType={rateableType}
        onClose={() => setModalOpen(false)}
        onSubmitted={onReviewed}
      />
    </div>
  )
}
