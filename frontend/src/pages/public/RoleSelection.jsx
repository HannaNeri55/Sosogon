import { useNavigate } from 'react-router-dom'
import { FiMap, FiBriefcase, FiArrowRight } from 'react-icons/fi'

const roles = [
  {
    id: 'tourist',
    icon: FiMap,
    title: 'Tourist',
    description: 'Explore destinations, plan trips with AI, and share reviews.',
  },
  {
    id: 'business_owner',
    icon: FiBriefcase,
    title: 'Business Owner',
    description: 'List your accommodation, restaurant, or retail product.',
  },
]

export default function RoleSelection() {
  const navigate = useNavigate()

  return (
    <div className="min-h-screen bg-gradient-to-br from-gray-50 via-gray-50 to-primary-50/40 flex items-center justify-center px-4 py-12">
      <div className="max-w-2xl w-full">
        <div className="text-center mb-10">
          <img src="/logo.png" alt="Sorsogon" className="h-12 mx-auto mb-6" onError={(e) => { e.target.style.display = 'none' }} />
          <h1 className="text-3xl font-heading font-bold text-gray-900 mb-3">Welcome to STIM</h1>
          <p className="text-gray-600">Select how you would like to use the platform</p>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
          {roles.map((role) => (
            <button
              key={role.id}
              onClick={() => navigate(`/register/${role.id}`)}
              className="bg-white rounded-2xl p-7 border border-gray-100 shadow-sm hover:shadow-lg hover:shadow-gray-200/60 hover:border-primary-200 transition-all duration-200 text-left group overflow-hidden relative"
            >
              <div className="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-primary-500 to-primary-600 opacity-0 group-hover:opacity-100 transition-opacity duration-200" />
              <div className="w-14 h-14 rounded-full bg-primary-50 ring-4 ring-primary-50/50 flex items-center justify-center mb-5 group-hover:bg-primary-100 group-hover:ring-primary-100/50 transition-colors">
                <role.icon size={26} className="text-primary-600" />
              </div>
              <h3 className="font-heading font-semibold text-lg text-gray-900 mb-2">{role.title}</h3>
              <p className="text-sm text-gray-500 leading-relaxed mb-5">{role.description}</p>
              <p className="flex items-center gap-1.5 text-sm font-medium text-primary-600 group-hover:gap-2.5 transition-all">
                Get started <FiArrowRight size={15} />
              </p>
            </button>
          ))}
        </div>

        <p className="text-center text-sm text-gray-500 mt-8">
          Already have an account?{' '}
          <button onClick={() => navigate('/login')} className="text-primary-600 font-medium hover:text-primary-700">
            Login here
          </button>
        </p>
      </div>
    </div>
  )
}