import { useEffect, useState } from 'react'
import { Outlet, NavLink, useNavigate } from 'react-router-dom'
import { useAuth } from '../contexts/AuthContext'
import { FiGrid, FiMapPin, FiActivity, FiCalendar, FiFileText, FiUsers, FiBarChart2, FiLogOut, FiImage, FiBell, FiMenu, FiX } from 'react-icons/fi'

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
  // Phones only: the sidebar is a slide-in drawer. From md up it's always visible, as before.
  const [menuOpen, setMenuOpen] = useState(false)

  useEffect(() => {
    if (!menuOpen) return
    const onKey = (e) => e.key === 'Escape' && setMenuOpen(false)
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [menuOpen])

  const handleLogout = async () => {
    await logout()
    navigate('/')
  }

  return (
    <div className="min-h-screen flex bg-gray-50">
      {menuOpen && (
        <button
          className="md:hidden fixed inset-0 z-30 bg-black/40 cursor-default"
          onClick={() => setMenuOpen(false)}
          aria-label="Close menu"
        />
      )}

      {/* Sidebar */}
      <aside
        className={`fixed inset-y-0 left-0 z-40 w-64 bg-primary-800 text-white flex flex-col overflow-y-auto transition-transform duration-200 md:static md:z-auto md:translate-x-0 md:transition-none ${
          menuOpen ? 'translate-x-0' : '-translate-x-full'
        }`}
      >
        <div className="p-6 border-b border-primary-700 flex items-start justify-between gap-2">
          <div>
            <h1 className="text-lg font-heading font-bold">STIM Admin</h1>
            <p className="text-primary-200 text-sm mt-1">{user?.name}</p>
          </div>
          <button
            onClick={() => setMenuOpen(false)}
            className="md:hidden p-1 -mr-2 text-primary-200 hover:text-white"
            aria-label="Close menu"
          >
            <FiX size={20} />
          </button>
        </div>
        <nav className="flex-1 p-4 space-y-1">
          {sidebarLinks.map((link) => (
            <NavLink
              key={link.to}
              to={link.to}
              end={link.end}
              onClick={() => setMenuOpen(false)}
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
      <div className="flex-1 min-w-0 overflow-auto">
        <header className="md:hidden sticky top-0 z-20 bg-primary-800 text-white px-4 py-3 flex items-center gap-3">
          <button
            onClick={() => setMenuOpen(true)}
            className="p-1 -ml-1"
            aria-label="Open menu"
            aria-expanded={menuOpen}
          >
            <FiMenu size={22} />
          </button>
          <span className="font-heading font-bold">STIM Admin</span>
        </header>
        <header className="hidden md:block bg-white border-b border-gray-200 px-8 py-4">
          <p className="text-sm text-gray-500">Tourism Information Management System</p>
        </header>
        <div className="p-4 md:p-8">
          <Outlet />
        </div>
      </div>
    </div>
  )
}
