import { Outlet, NavLink, useNavigate } from 'react-router-dom'
import { useAuth } from '../contexts/AuthContext'
import { FiGrid, FiMapPin, FiActivity, FiCalendar, FiFileText, FiUsers, FiBarChart2, FiLogOut, FiImage, FiBell } from 'react-icons/fi'

const sidebarLinks = [
  { to: '/admin', icon: FiGrid, label: 'Dashboard', end: true },
  { to: '/admin/destinations', icon: FiMapPin, label: 'Destinations' },
  { to: '/admin/activities', icon: FiActivity, label: 'Activities' },
  { to: '/admin/events', icon: FiCalendar, label: 'Events' },
  { to: '/admin/hero-slides', icon: FiImage, label: 'Hero Slides' },
  { to: '/admin/announcements', icon: FiBell, label: 'Announcements' },
  { to: '/admin/submissions', icon: FiFileText, label: 'Submissions' },
  { to: '/admin/users', icon: FiUsers, label: 'Users' },
  { to: '/admin/reports', icon: FiBarChart2, label: 'Reports' },
]

export default function AdminLayout() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()

  const handleLogout = async () => {
    await logout()
    navigate('/')
  }

  return (
    <div className="min-h-screen flex bg-gray-50">
      {/* Sidebar */}
      <aside className="w-64 bg-primary-800 text-white flex flex-col">
        <div className="p-6 border-b border-primary-700">
          <h1 className="text-lg font-heading font-bold">STIM Admin</h1>
          <p className="text-primary-200 text-sm mt-1">{user?.name}</p>
        </div>
        <nav className="flex-1 p-4 space-y-1">
          {sidebarLinks.map((link) => (
            <NavLink
              key={link.to}
              to={link.to}
              end={link.end}
              className={({ isActive }) =>
                `flex items-center gap-3 px-4 py-2.5 rounded-lg text-sm transition-colors ${
                  isActive ? 'bg-primary-600 text-white' : 'text-primary-200 hover:bg-primary-700 hover:text-white'
                }`
              }
            >
              <link.icon size={18} />
              {link.label}
            </NavLink>
          ))}
        </nav>
        <div className="p-4 border-t border-primary-700">
          <button onClick={handleLogout} className="flex items-center gap-3 px-4 py-2.5 text-primary-200 hover:text-white text-sm w-full">
            <FiLogOut size={18} /> Logout
          </button>
        </div>
      </aside>

      {/* Main Content */}
      <div className="flex-1 overflow-auto">
        <header className="bg-white border-b border-gray-200 px-8 py-4">
          <p className="text-sm text-gray-500">Tourism Information Management System</p>
        </header>
        <div className="p-8">
          <Outlet />
        </div>
      </div>
    </div>
  )
}