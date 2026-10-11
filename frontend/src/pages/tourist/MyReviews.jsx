import { useEffect, useState } from 'react'
import { getMyReviews } from '../../api/ratings'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import { StarDisplay } from '../../components/common/StarRating'

export default function MyReviews() {
  const [reviews, setReviews] = useState([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    getMyReviews().then((data) => setReviews(data.data || [])).finally(() => setLoading(false))
  }, [])

  if (loading) return <LoadingSpinner size="lg" />

  return (
    <div className="bg-gray-50 min-h-screen">
      <div className="max-w-5xl mx-auto px-4 sm:px-6 py-12">
        <div className="mb-8">
          <p className="text-primary-600 font-medium mb-2">Tourist</p>
          <h1 className="text-4xl font-heading font-bold text-gray-900">My Reviews</h1>
        </div>

        {reviews.length === 0 ? (
          <div className="bg-white rounded-xl p-12 text-center border border-gray-100">
            <p className="text-gray-500">No reviews submitted yet.</p>
          </div>
        ) : (
          <div className="space-y-4">
            {reviews.map((review) => (
              <div key={review.id} className="bg-white rounded-xl p-6 border border-gray-100">
                <div className="flex items-center justify-between mb-3">
                  <h3 className="font-heading font-semibold text-lg text-gray-900">{review.rateable?.name || 'Item'}</h3>
                  <StarDisplay rating={review.score} size={16} />
                </div>
                {review.comment && <p className="text-gray-600">{review.comment}</p>}
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  )
}