import { useState } from 'react'
import { Outlet, NavLink, useNavigate, Link } from 'react-router-dom'
import { useAuth } from '../contexts/AuthContext'
import ListingWarningBanner from '../components/business/ListingWarningBanner'
import NotificationBell from '../components/notifications/NotificationBell'
import { FiUser, FiLogOut, FiChevronDown, FiExternalLink, FiShield } from 'react-icons/fi'

/**
 * Shared shell of the three business-owner portals (accommodation, food &
 * beverage, retail shop): top bar with the portal name, section tabs and the
 * account menu, then the page. Each portal passes its own label and links.
 */
export default function OwnerPortalLayout({ portalLabel, homePath, navLinks }) {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const [menuOpen, setMenuOpen] = useState(false)

  const handleLogout = async () => {
    await logout()
    navigate('/')
  }

  return (
    <div className="min-h-screen bg-gray-50">
      <header className="bg-white/90 backdrop-blur-md border-b border-gray-200/80 sticky top-0 z-20">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 flex items-center justify-between h-16 gap-2 sm:gap-4">
          <Link to={homePath} className="flex items-center gap-3 shrink-0">
            <img src="/images/Logo.png" alt="Sorsogon" className="h-7 sm:h-9 w-auto" />
            <span className="hidden xl:inline-flex items-center rounded-full bg-primary-50 border border-primary-100 px-3 py-1 text-xs font-semibold text-primary-700">
              {portalLabel}
            </span>
          </Link>

          <nav className="flex items-center gap-1 overflow-x-auto" aria-label={portalLabel}>
            {navLinks.map((link) => (
              <NavLink
                key={link.to}
                to={link.to}
                end={link.end}
                className={({ isActive }) =>
                  `flex flex-col lg:flex-row items-center gap-1 lg:gap-2 px-1.5 sm:px-4 py-1.5 lg:py-2 rounded-xl lg:rounded-full text-sm font-medium whitespace-nowrap transition-colors ${
                    isActive ? 'bg-primary-50 text-primary-700' : 'text-gray-600 hover:bg-gray-100 hover:text-primary-700'
                  }`
                }
              >
                <link.icon size={16} aria-hidden="true" />
                <span className="lg:hidden text-[11px] leading-none">{link.short}</span>
                <span className="hidden lg:inline">{link.label}</span>
              </NavLink>
            ))}
          </nav>

          <div className="flex items-center gap-1 shrink-0">
            <NotificationBell />
            <div className="relative shrink-0">
              <button
                onClick={() => setMenuOpen((v) => !v)}
                aria-label="Account menu"
                aria-expanded={menuOpen}
                className="flex items-center gap-2 px-1 sm:px-2 py-1.5 rounded-full text-sm text-gray-700 hover:bg-gray-100"
              >
                <span className="w-8 h-8 rounded-full bg-accent-400 text-gray-900 flex items-center justify-center font-bold">
                  {user?.name?.[0]?.toUpperCase() || <FiUser size={14} />}
                </span>
                <span className="hidden xl:inline font-medium">{user?.name}</span>
                <FiChevronDown size={14} className="hidden sm:block text-gray-400" aria-hidden="true" />
              </button>

              {menuOpen && (
                <>
                  <button
                    className="fixed inset-0 z-10 cursor-default"
                    onClick={() => setMenuOpen(false)}
                    aria-label="Close menu"
                  />
                  <div className="absolute right-0 mt-2 w-56 bg-white rounded-xl border border-gray-200/70 shadow-(--shadow-card-hover) py-1.5 z-20">
                    <div className="px-4 py-2 border-b border-gray-100 mb-1">
                      <p className="text-sm font-semibold text-gray-900 truncate">{user?.name}</p>
                      <p className="text-xs text-gray-500">{portalLabel}</p>
                    </div>
                    <Link
                      to="/account/status"
                      onClick={() => setMenuOpen(false)}
                      className="flex items-center gap-2 px-4 py-3 sm:py-2 text-sm text-gray-700 hover:bg-gray-50"
                    >
                      <FiShield size={14} aria-hidden="true" /> Account Health
                      {user?.account_state === 'warning' && <span className="ml-auto rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800">Warning</span>}
                    </Link>
                    <a
                      href="/"
                      target="_blank"
                      rel="noopener noreferrer"
                      className="flex items-center gap-2 px-4 py-3 sm:py-2 text-sm text-gray-700 hover:bg-gray-50"
                    >
                      <FiExternalLink size={14} aria-hidden="true" /> View public site
                    </a>
                    <button
                      onClick={handleLogout}
                      className="flex items-center gap-2 px-4 py-3 sm:py-2 text-sm text-gray-700 hover:bg-gray-50 w-full text-left"
                    >
                      <FiLogOut size={14} aria-hidden="true" /> Logout
                    </button>
                  </div>
                </>
              )}
            </div>
          </div>
        </div>
      </header>

      <main className="max-w-7xl mx-auto px-4 sm:px-6 py-8">
        <ListingWarningBanner />
        <Outlet />
      </main>
    </div>
  )
}
