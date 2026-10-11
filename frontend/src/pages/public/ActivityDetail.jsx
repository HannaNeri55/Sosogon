import { useEffect, useState } from 'react'
import { useParams, Link } from 'react-router-dom'
import { FiArrowLeft, FiFlag, FiStar, FiSun } from 'react-icons/fi'
import { getActivity } from '../../api/activities'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import { getImageUrl } from '../../utils/helpers'

export default function ActivityDetail() {
  const { id } = useParams()
  const [activity, setActivity] = useState(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setLoading(true)
    getActivity(id)
      .then(setActivity)
      .catch(() => setActivity(null))
      .finally(() => setLoading(false))
  }, [id])

  if (loading) return <LoadingSpinner size="lg" />
  if (!activity) return <div className="p-6 sm:p-8 text-center text-gray-500">Activity not found.</div>

  const thingsToDo = activity.things_to_do?.split('\n').map((s) => s.trim()).filter(Boolean) || []

  return (
    <div className="bg-gray-50 min-h-screen">
      <div className="max-w-4xl mx-auto px-4 sm:px-6 py-10">
        <div className="flex items-start justify-between gap-4 mb-6">
          <h1 className="min-w-0 wrap-break-word font-heading font-extrabold text-3xl sm:text-4xl uppercase text-secondary-700">
            {activity.name}
          </h1>
          <Link
            to={activity.destination_id ? `/destinations/${activity.destination_id}` : '/destinations'}
            className="inline-flex items-center gap-1.5 font-semibold text-secondary-700 hover:text-secondary-800 shrink-0 mt-2"
          >
            <FiArrowLeft size={16} /> Back
          </Link>
        </div>

        <div className="aspect-[4/3] rounded-xl overflow-hidden mb-8">
          <img
            src={getImageUrl(activity.image_path)}
            alt={activity.name}
            className="w-full h-full object-cover"
          />
        </div>

        {activity.description && <p className="text-gray-700 leading-relaxed mb-8">{activity.description}</p>}

        <div className="space-y-3 mb-10">
          {activity.type && <InfoRow icon={<FiFlag size={18} />} label="Type" value={activity.type === 'indoor' ? 'Indoor' : 'Outdoor'} />}
          {activity.best_time_to_visit && <InfoRow icon={<FiStar size={18} />} label="Best Time To Visit" value={activity.best_time_to_visit} />}
          {activity.what_to_wear && <InfoRow icon={<FiSun size={18} />} label="What To Wear" value={activity.what_to_wear} />}
        </div>

        {thingsToDo.length > 0 && (
          <section>
            <h2 className="font-heading font-extrabold text-2xl uppercase text-secondary-700 mb-3">
              Things To Do
            </h2>
            <ul className="list-disc pl-5 space-y-2 text-gray-700">
              {thingsToDo.map((item, i) => (
                <li key={i}>{item}</li>
              ))}
            </ul>
          </section>
        )}
      </div>
    </div>
  )
}

function InfoRow({ icon, label, value }) {
  return (
    <div className="flex items-center gap-3">
      <span className="text-secondary-600 shrink-0">{icon}</span>
      <span className="font-semibold text-gray-900 w-44 shrink-0">{label}:</span>
      <span className="text-gray-700">{value}</span>
    </div>
  )
}
