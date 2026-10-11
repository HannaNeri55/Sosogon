import { Link, useLocation } from 'react-router-dom'
import { FiShieldOff } from 'react-icons/fi'

const TYPE_LABELS = {
  accommodation: 'Accommodations',
  food_beverage: 'Food & Beverage',
  retail_product: 'Local Products',
}

export default function Forbidden() {
  const location = useLocation()
  const attemptedType = location.state?.attemptedType
  const attemptedLabel = TYPE_LABELS[attemptedType]

  return (
    <div className="min-h-screen bg-gray-50 flex items-center justify-center px-4 py-12">
      <div className="max-w-md w-full bg-white rounded-2xl shadow-sm border border-gray-100 p-8 text-center">
        <div className="w-16 h-16 rounded-full bg-red-50 flex items-center justify-center mx-auto mb-6">
          <FiShieldOff size={32} className="text-red-600" />
        </div>
        <h1 className="text-2xl font-heading font-bold text-gray-900 mb-3">This dashboard isn't yours</h1>
        <p className="text-gray-600 mb-6 leading-relaxed">
          {attemptedLabel
            ? `The ${attemptedLabel} dashboard is reserved for business owners registered under that business type.`
            : 'This dashboard is reserved for business owners registered under a different business type.'}
          {' '}You can only manage the business type you registered with.
        </p>
        <Link to="/business" className="btn-primary">Go to My Dashboard</Link>
      </div>
    </div>
  )
}
