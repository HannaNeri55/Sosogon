import { Link } from 'react-router-dom'
import { FiClock } from 'react-icons/fi'

export default function RegistrationPending() {
  return (
    <div className="min-h-screen bg-gray-50 flex items-center justify-center px-4 py-12">
      <div className="max-w-md w-full bg-white rounded-2xl shadow-sm border border-gray-100 p-8 text-center">
        <div className="w-16 h-16 rounded-full bg-yellow-50 flex items-center justify-center mx-auto mb-6">
          <FiClock size={32} className="text-yellow-600" />
        </div>
        <h1 className="text-2xl font-heading font-bold text-gray-900 mb-3">Registration Submitted</h1>
        <p className="text-gray-600 mb-6 leading-relaxed">
          Your business owner application has been submitted successfully. An administrator from the Tourism Office will review your documents and approve your account.
        </p>
        <p className="text-sm text-gray-500 mb-8">
          You will not be able to log in until your account is approved. This process typically takes 1-3 business days.
        </p>
        <Link to="/" className="btn-primary">Return to Homepage</Link>
      </div>
    </div>
  )
}