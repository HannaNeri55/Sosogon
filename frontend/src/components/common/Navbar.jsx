// frontend/src/components/common/Navbar.jsx
import { useState } from 'react'
import { Link, NavLink } from 'react-router-dom'
import { useAuth } from '../../contexts/AuthContext'
import { FiMenu, FiX } from 'react-icons/fi'

const publicLinks = [
  { to: '/', label: 'Home' },
  { to: '/destinations', label: 'Destinations' },
  { to: '/accommodations', label: 'Accommodations' },
  { to: '/food-beverage', label: 'Food & Beverage' },
  { to: '/shops', label: 'Shops' },
  { to: '/events', label: 'Events' },  { to: '/about', label: 'About' },
]

export default function Navbar() {
  const { user, isAuthenticated, isAdmin, isBusinessOwner } = useAuth()
  const [mobileOpen, setMobileOpen] = useState(false)

  const getDashboardLink = () => {
    if (isAdmin) return '/admin'
    if (isBusinessOwner) return '/business'
    return '/dashboard'
  }

  const initial = user?.name?.charAt(0)?.toUpperCase() || '?'

  return (
    <header className="bg-white border-b border-gray-200 sticky top-0 z-50">
      <div className="max-w-7xl mx-auto px-4 sm:px-6">
        <div className="flex items-center justify-between h-16">
          {/* Logo */}
          <Link to="/" className="flex items-center gap-2">
            <img src="/images/Logo.png" alt="Sorsogon" className="h-12 w-auto" />
          </Link>

          {/* Desktop Nav */}
          <nav className="hidden lg:flex items-center gap-1">
            {publicLinks.map((link) => (
              <NavLink
                key={link.to}
                to={link.to}
                className={({ isActive }) =>
                  `px-3 py-2 rounded-md text-sm font-medium transition-colors ${
                    isActive ? 'text-primary-600 bg-primary-50' : 'text-gray-600 hover:text-primary-600'
                  }`
                }
              >
                {link.label}
              </NavLink>
            ))}
          </nav>

          {/* Auth */}
          <div className="hidden lg:flex items-center gap-3">
            {isAuthenticated ? (
              <Link
                to={getDashboardLink()}
                title={user.name}
                className="w-9 h-9 rounded-full bg-primary-50 border border-primary-200 flex items-center justify-center text-primary-600 font-heading font-semibold text-sm hover:bg-primary-100 transition-colors"
              >
                {initial}
              </Link>
            ) : (
              <>
                <Link to="/login" className="text-sm text-gray-600 hover:text-primary-600 font-medium">Login</Link>
                <Link to="/plan-trip" className="btn-primary text-sm">Plan Your Trip</Link>
              </>
            )}
          </div>

          {/* Mobile toggle */}
          <button
            onClick={() => setMobileOpen(!mobileOpen)}
            className="lg:hidden p-2 -mr-2"
            aria-label={mobileOpen ? 'Close menu' : 'Open menu'}
            aria-expanded={mobileOpen}
          >
            {mobileOpen ? <FiX size={24} /> : <FiMenu size={24} />}
          </button>
        </div>

        {/* Mobile menu */}
        {mobileOpen && (
          <div className="lg:hidden pb-4 border-t border-gray-100 pt-3 space-y-0.5">
            {publicLinks.map((link) => (
              <NavLink key={link.to} to={link.to} end={link.to === '/'} onClick={() => setMobileOpen(false)}
                className={({ isActive }) =>
                  `block px-3 py-3 rounded-lg text-base ${
                    isActive ? 'bg-primary-50 text-primary-700 font-medium' : 'text-gray-700 hover:bg-gray-50 hover:text-primary-600'
                  }`
                }>
                {link.label}
              </NavLink>
            ))}
            <div className="mt-3 pt-3 border-t border-gray-100">
              {isAuthenticated ? (
                <Link to={getDashboardLink()} onClick={() => setMobileOpen(false)} className="flex items-center gap-3 px-3 py-3 rounded-lg text-base font-medium text-primary-600 hover:bg-primary-50">
                  <span className="w-7 h-7 rounded-full bg-primary-50 border border-primary-200 flex items-center justify-center text-sm">{initial}</span>
                  Dashboard
                </Link>
              ) : (
                <div className="grid grid-cols-2 gap-3 px-3 pt-1">
                  <Link to="/login" onClick={() => setMobileOpen(false)} className="btn-secondary justify-center text-center">Login</Link>
                  <Link to="/get-started" onClick={() => setMobileOpen(false)} className="btn-primary justify-center text-center">Register</Link>
                </div>
              )}
            </div>
          </div>
        )}
      </div>
    </header>
  )
}