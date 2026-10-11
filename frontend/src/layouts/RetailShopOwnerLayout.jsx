import { useState } from 'react'
import { Outlet, NavLink, useNavigate, Link } from 'react-router-dom'
import { useAuth } from '../contexts/AuthContext'
import ListingWarningBanner from '../components/business/ListingWarningBanner'
import { FiGrid, FiShoppingBag, FiPackage, FiUser, FiLogOut, FiChevronDown } from 'react-icons/fi'

const navLinks = [
  { to: '/business/retail-shop', icon: FiGrid, label: 'Dashboard', end: true },
  { to: '/business/retail-shop/shop', icon: FiShoppingBag, label: 'My Shop' },
  { to: '/business/retail-shop/products', icon: FiPackage, label: 'Product Catalog' },
]

export default function RetailShopOwnerLayout() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const [menuOpen, setMenuOpen] = useState(false)

  const handleLogout = async () => {
    await logout()
    navigate('/')
  }

  return (
    <div className="min-h-screen bg-gray-50">
      <header className="bg-white border-b border-gray-200 sticky top-0 z-20">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 flex items-center justify-between h-16 gap-4">
          <Link to="/business/retail-shop" className="flex items-center gap-2 shrink-0">
            <img src="/images/Logo.png" alt="Sorsogon" className="h-9 w-auto" />
            <span className="hidden sm:inline font-heading font-bold text-gray-900">Local Products Portal</span>
          </Link>

          <nav className="flex items-center gap-1 overflow-x-auto">
            {navLinks.map((link) => (
              <NavLink
                key={link.to}
                to={link.to}
                end={link.end}
                className={({ isActive }) =>
                  `flex items-center gap-2 px-3 sm:px-4 py-2 rounded-lg text-sm font-medium whitespace-nowrap transition-colors ${
                    isActive ? 'bg-primary-50 text-primary-600' : 'text-gray-600 hover:bg-gray-50'
                  }`
                }
              >
                <link.icon size={16} />
                <span className="hidden md:inline">{link.label}</span>
              </NavLink>
            ))}
          </nav>

          <div className="relative shrink-0">
            <button
              onClick={() => setMenuOpen((v) => !v)}
              className="flex items-center gap-2 px-3 py-2 rounded-lg text-sm text-gray-700 hover:bg-gray-50"
            >
              <span className="w-8 h-8 rounded-full bg-primary-100 text-primary-700 flex items-center justify-center font-medium">
                {user?.name?.[0]?.toUpperCase() || <FiUser size={14} />}
              </span>
              <span className="hidden sm:inline">{user?.name}</span>
              <FiChevronDown size={14} className="text-gray-400" />
            </button>

            {menuOpen && (
              <>
                <button
                  className="fixed inset-0 z-10 cursor-default"
                  onClick={() => setMenuOpen(false)}
                  aria-label="Close menu"
                />
                <div className="absolute right-0 mt-2 w-44 bg-white rounded-lg border border-gray-100 shadow-lg py-1 z-20">
                  <button
                    onClick={handleLogout}
                    className="flex items-center gap-2 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50 w-full text-left"
                  >
                    <FiLogOut size={14} /> Logout
                  </button>
                </div>
              </>
            )}
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
