import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { getPhotoCredits } from '../../api/photoCredits'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import Reveal from '../../components/common/Reveal'

/**
 * Credits for openly licensed photos used on listings, gathered in one place so image cards stay
 * clean. Each entry names the photographer, source and licence, and links to the original.
 */
export default function PhotoCredits() {
  const [listings, setListings] = useState(null)

  useEffect(() => {
    getPhotoCredits()
      .then((data) => setListings(data.listings ?? []))
      .catch(() => setListings([]))
  }, [])

  return (
    <div className="bg-gray-50 min-h-screen">
      <div className="max-w-3xl mx-auto px-4 sm:px-6 pt-16 pb-4">
        <h1 className="text-3xl font-heading font-bold text-gray-900 mb-2">Photo Credits</h1>
        <p className="text-gray-600 leading-relaxed">
          Some photos on this site come from other photographers and sources, including Wikimedia Commons, and are used
          under their licences. They are credited here rather than under each image. Photos marked <span className="font-medium text-gray-800">representative</span>{' '}
          illustrate the activity, room type or shop's goods in general and were not taken at that listing. Photos not listed here were
          provided by the Tourism Office or the business.
        </p>
      </div>

      <div className="max-w-3xl mx-auto px-4 sm:px-6 pb-16 space-y-6">
        {listings === null ? (
          <LoadingSpinner />
        ) : listings.length === 0 ? (
          <p className="text-gray-500">No credited photos to show.</p>
        ) : (
          listings.map((listing, i) => (
            <Reveal
              key={`${listing.type}-${listing.id}`}
              className="bg-white rounded-xl p-6 sm:p-8 border border-gray-100"
              delay={Math.min(i * 40, 300)}
            >
              <h2 className="text-lg font-heading font-semibold text-gray-900 mb-3">
                <Link
                  to={`/${{ destination: 'destinations', accommodation: 'accommodations', shop: 'shops' }[listing.type]}/${listing.id}`}
                  className="hover:text-primary-600"
                >
                  {listing.name}
                </Link>
              </h2>
              <ul className="space-y-2 text-sm text-gray-600">
                {listing.credits.map((c, j) => (
                  <li key={j} className="leading-relaxed">
                    <span className="font-medium text-gray-800">{c.subject}</span>
                    {c.representative && <span className="text-gray-500"> (representative)</span>}
                    {': '}
                    {c.source_url ? (
                      <a href={c.source_url} target="_blank" rel="noopener noreferrer" className="wrap-break-word text-primary-700 hover:underline">
                        {c.credit}
                      </a>
                    ) : (
                      <span className="wrap-break-word">{c.credit}</span>
                    )}
                  </li>
                ))}
              </ul>
            </Reveal>
          ))
        )}
      </div>
    </div>
  )
}
