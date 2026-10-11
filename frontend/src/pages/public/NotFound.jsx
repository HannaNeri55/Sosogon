import { Link } from 'react-router-dom'
import { FiCompass } from 'react-icons/fi'

// Shown for any URL that doesn't match a route (mistyped or outdated links), instead of a blank page.
export default function NotFound() {
  return (
    <div className="bg-gray-50 flex items-center justify-center px-4 py-16 sm:py-24">
      <div className="max-w-md w-full bg-white rounded-2xl shadow-sm border border-gray-100 p-6 sm:p-8 text-center">
        <div className="w-16 h-16 rounded-full bg-primary-50 flex items-center justify-center mx-auto mb-6">
          <FiCompass size={32} className="text-primary-600" />
        </div>
        <p className="text-sm font-medium text-primary-600 mb-1">Page not found</p>
        <h1 className="text-2xl font-heading font-bold text-gray-900 mb-3">This page took a detour</h1>
        <p className="text-gray-600 mb-6 leading-relaxed">
          The link may be mistyped or the page may have moved. Let&apos;s get you back to exploring Sorsogon.
        </p>
        <div className="flex flex-col sm:flex-row gap-3 justify-center">
          <Link to="/" className="btn-primary justify-center">Go to Home</Link>
          <Link to="/destinations" className="btn-secondary justify-center text-center">Explore Destinations</Link>
        </div>
      </div>
    </div>
  )
}
