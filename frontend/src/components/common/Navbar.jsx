// frontend/src/components/common/Navbar.jsx
import { useEffect, useState } from 'react'
import { Link, NavLink, useNavigate } from 'react-router-dom'
import { useAuth } from '../../contexts/AuthContext'
import { FiChevronDown, FiGrid, FiLogOut, FiMenu, FiSearch, FiShield, FiX } from 'react-icons/fi'

const publicLinks = [
  { to: '/', label: 'Home' },
  { to: '/destinations', label: 'Destinations' },
  { to: '/accommodations', label: 'Accommodations' },
  { to: '/food-beverage', label: 'Food & Beverage' },
  { to: '/shops', label: 'Shops' },
  { to: '/events', label: 'Events' },
  { to: '/about', label: 'About' },
]

/** Search box that opens the results page; the API needs at least 2 characters. */
function SearchForm({ onSearch, autoFocus = false, className = '' }) {
  const navigate = useNavigate()
  const [query, setQuery] = useState('')

  const submit = (e) => {
    e.preventDefault()
    const q = query.trim()
    if (q.length < 2) return
    navigate(`/search?q=${encodeURIComponent(q)}`)
    setQuery('')
    onSearch?.()
  }

  return (
    <form role="search" onSubmit={submit} className={className}>
      <div className="relative">
        <FiSearch className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" aria-hidden="true" />
        <input
          type="search"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder="Search destinations, stays, food, shops…"
          aria-label="Search the site"
          minLength={2}
          maxLength={100}
          autoFocus={autoFocus}
          className="w-full rounded-full border border-gray-200 bg-white py-2 pl-9 pr-4 text-sm focus:border-primary-400 focus:outline-none focus:ring-2 focus:ring-primary-100"
        />
      </div>
    </form>
  )
}

export default function Navbar() {
  const { user, isAuthenticated, isAdmin, isBusinessOwner, logout } = useAuth()
  const navigate = useNavigate()
  const [mobileOpen, setMobileOpen] = useState(false)
  const [searchOpen, setSearchOpen] = useState(false)
  const [accountOpen, setAccountOpen] = useState(false)
  const closeAccount = () => setAccountOpen(false)

  // Escape closes the account menu.
  useEffect(() => {
    if (!accountOpen) return undefined
    const onKey = (event) => event.key === 'Escape' && setAccountOpen(false)
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [accountOpen])

  const getDashboardLink = () => {
    if (isAdmin) return '/admin'
    if (isBusinessOwner) return '/business'
    return '/dashboard'
  }

  const hasWarning = user?.account_state === 'warning'
  // Account Health is for tourists and business owners; admins have no account standing.
  const accountLinks = [
    { to: getDashboardLink(), label: 'Dashboard', icon: FiGrid },
    ...(isAdmin ? [] : [{ to: '/account/status', label: 'Account Health', icon: FiShield, warning: hasWarning }]),
  ]

  const handleLogout = async () => {
    setAccountOpen(false)
    setMobileOpen(false)
    await logout()
    navigate('/')
  }

  const initial = user?.name?.charAt(0)?.toUpperCase() || '?'

  return (
    <header className="bg-white/90 backdrop-blur-md border-b border-gray-200/80 sticky top-0 z-50">
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
                end={link.to === '/'}
                className={({ isActive }) =>
                  `px-3 py-2 rounded-full text-sm font-medium transition-colors ${
                    isActive ? 'text-primary-700 bg-primary-50' : 'text-gray-600 hover:text-primary-700 hover:bg-gray-100'
                  }`
                }
              >
                {link.label}
              </NavLink>
            ))}
          </nav>

          {/* Auth */}
          <div className="hidden lg:flex items-center gap-3">
            <button
              type="button"
              onClick={() => setSearchOpen(!searchOpen)}
              className="p-2 rounded-full text-gray-600 hover:text-primary-700 hover:bg-gray-100"
              aria-label={searchOpen ? 'Close search' : 'Search'}
              aria-expanded={searchOpen}
            >
              {searchOpen ? <FiX size={18} /> : <FiSearch size={18} />}
            </button>
            {isAuthenticated ? (
              <div className="relative">
                <button
                  type="button"
                  onClick={() => setAccountOpen(!accountOpen)}
                  aria-label="Account menu"
                  aria-expanded={accountOpen}
                  className="relative flex items-center gap-1 rounded-full p-0.5 pr-1.5 hover:bg-gray-100 transition-colors"
                >
                  <span className="w-9 h-9 rounded-full bg-primary-50 border border-primary-200 flex items-center justify-center text-primary-600 font-heading font-semibold text-sm">
                    {initial}
                  </span>
                  <FiChevronDown size={14} className="text-gray-400" aria-hidden="true" />
                  {hasWarning && <span className="absolute top-0 left-7 h-2.5 w-2.5 rounded-full bg-amber-500 ring-2 ring-white" aria-hidden="true" />}
                </button>

                {accountOpen && (
                  <>
                    <button className="fixed inset-0 z-10 cursor-default" onClick={closeAccount} aria-label="Close menu" tabIndex={-1} />
                    <div className="absolute right-0 mt-2 w-56 bg-white rounded-xl border border-gray-200/70 shadow-lg py-1.5 z-20">
                      <div className="px-4 py-2 border-b border-gray-100 mb-1">
                        <p className="text-sm font-semibold text-gray-900 truncate">{user.name}</p>
                        <p className="text-xs text-gray-500 truncate">{user.email}</p>
                      </div>
                      {accountLinks.map(({ to, label, icon: Icon, warning }) => (
                        <Link key={to} to={to} onClick={closeAccount} className="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                          <Icon size={14} aria-hidden="true" /> {label}
                          {warning && <span className="ml-auto rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800">Warning</span>}
                        </Link>
                      ))}
                      <button type="button" onClick={handleLogout} className="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 w-full text-left">
                        <FiLogOut size={14} aria-hidden="true" /> Logout
                      </button>
                    </div>
                  </>
                )}
              </div>
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

        {/* Desktop search row */}
        {searchOpen && (
          <div className="hidden lg:block pb-3">
            <SearchForm autoFocus onSearch={() => setSearchOpen(false)} className="max-w-xl ml-auto" />
          </div>
        )}

        {/* Mobile menu */}
        {mobileOpen && (
          <div className="lg:hidden pb-4 border-t border-gray-100 pt-3 space-y-0.5">
            <SearchForm onSearch={() => setMobileOpen(false)} className="px-3 pb-2" />
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
                <>
                  <p className="flex items-center gap-3 px-3 pb-2 text-sm text-gray-500">
                    <span className="w-7 h-7 rounded-full bg-primary-50 border border-primary-200 flex items-center justify-center text-sm font-semibold text-primary-600">{initial}</span>
                    <span className="truncate">{user.name}</span>
                  </p>
                  {accountLinks.map(({ to, label, icon: Icon, warning }) => (
                    <Link key={to} to={to} onClick={() => setMobileOpen(false)} className="flex items-center gap-3 px-3 py-3 rounded-lg text-base text-gray-700 hover:bg-gray-50 hover:text-primary-600">
                      <Icon size={18} aria-hidden="true" /> {label}
                      {warning && <span className="ml-auto rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">Warning</span>}
                    </Link>
                  ))}
                  <button type="button" onClick={handleLogout} className="flex w-full items-center gap-3 px-3 py-3 rounded-lg text-base text-gray-700 hover:bg-gray-50 hover:text-primary-600 text-left">
                    <FiLogOut size={18} aria-hidden="true" /> Logout
                  </button>
                </>
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