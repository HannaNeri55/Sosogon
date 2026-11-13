
import { useEffect, useState } from 'react'
import { Outlet, NavLink, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../contexts/AuthContext'
import {
  FiGrid,
  FiMapPin,
  FiCalendar,
  FiUsers,
  FiBarChart2,
  FiLogOut,
  FiImage,
  FiBell,
  FiMenu,
  FiX,
  FiCheckSquare,
  FiStar,
  FiLayers,
  FiChevronDown,
  FiExternalLink,
  FiShield,
  FiInbox,
  FiClipboard,
} from 'react-icons/fi'
import { APPROVALS_LABEL, APPROVAL_STAGES, isApprovalPath } from './approvalStages'
import NotificationBell from '../components/notifications/NotificationBell'

const linkClass = ({ isActive }) =>
  `relative flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors ${
    isActive
      ? 'bg-white/10 text-white before:absolute before:left-0 before:top-2 before:bottom-2 before:w-1 before:rounded-r-full before:bg-accent-400'
      : 'text-white/70 hover:bg-white/5 hover:text-white'
  }`

const sidebarLinks = [
  {
    section: 'Overview',
    to: '/admin',
    icon: FiGrid,
    label: 'Dashboard',
    end: true,
  },
  {
    section: 'Content',
    to: '/admin/destinations',
    icon: FiMapPin,
    label: 'Destinations',
  },
  {
    to: '/admin/events',
    icon: FiCalendar,
    label: 'Events',
  },
  {
    to: '/admin/hero-slides',
    icon: FiImage,
    label: 'Hero Slides',
  },
  {
    to: '/admin/announcements',
    icon: FiBell,
    label: 'Announcements',
  },
  {
    section: 'Approvals',
    // Owner accounts → listing submissions → publication review (see approvalStages.js).
    group: APPROVALS_LABEL,
    icon: FiCheckSquare,
    children: APPROVAL_STAGES,
  },
  {
    // New rooms/dishes/products and proposed changes to approved ones.
    to: '/admin/item-reviews',
    icon: FiLayers,
    label: 'Offerings',
  },
  {
    section: 'Community',
    to: '/admin/guest-reviews',
    icon: FiStar,
    label: 'Guest Reviews',
  },
  {
    to: '/admin/users',
    icon: FiUsers,
    label: 'Users',
  },
  {
    to: '/admin/violations',
    icon: FiShield,
    label: 'Violation Records',
  },
  {
    to: '/admin/appeals',
    icon: FiInbox,
    label: 'Appeals',
  },
  {
    to: '/admin/account-log',
    icon: FiClipboard,
    label: 'Account Activity Log',
  },
  {
    section: 'Insights',
    to: '/admin/reports',
    icon: FiBarChart2,
    label: 'Reports',
  },
]

export default function AdminLayout() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const { pathname } = useLocation()

  // The approvals group is open while one of its pages is showing; it can also be toggled.
  const inApprovals = isApprovalPath(pathname)
  const [approvalsToggled, setApprovalsToggled] = useState(false)
  const approvalsOpen = inApprovals || approvalsToggled

  // Phones only: the sidebar is a slide-in drawer.
  // From md up, it stays visible as before.
  const [menuOpen, setMenuOpen] = useState(false)

  useEffect(() => {
    if (!menuOpen) return

    const onKey = (event) => {
      if (event.key === 'Escape') {
        setMenuOpen(false)
      }
    }

    window.addEventListener('keydown', onKey)

    return () => {
      window.removeEventListener('keydown', onKey)
    }
  }, [menuOpen])

  const handleLogout = async () => {
    await logout()
    navigate('/login', { replace: true })
  }

  return (
    <div className="min-h-screen flex bg-gray-50">
      {/* Mobile sidebar backdrop */}
      {menuOpen && (
        <button
          type="button"
          className="md:hidden fixed inset-0 z-30 bg-black/40 cursor-default"
          onClick={() => setMenuOpen(false)}
          aria-label="Close menu"
        />
      )}

      {/* Sidebar */}
      <aside
        className={`fixed inset-y-0 left-0 z-40 w-64 bg-primary-900 text-white flex flex-col overflow-y-auto transition-transform duration-200 md:sticky md:top-0 md:h-screen md:z-auto md:translate-x-0 md:transition-none ${
          menuOpen
            ? 'translate-x-0'
            : '-translate-x-full'
        }`}
      >
        <div className="px-5 py-5 border-b border-white/10 flex items-start justify-between gap-2">
          <div className="flex items-center gap-3 min-w-0">
            <img src="/images/Logo.png" alt="" className="h-9 w-auto brightness-0 invert" />
            <div className="min-w-0">
              <p className="font-heading text-base font-bold leading-tight">STIM Admin</p>
              <p className="text-white/60 text-xs mt-0.5 truncate">Tourism Office</p>
            </div>
          </div>

          <button
            type="button"
            onClick={() => setMenuOpen(false)}
            className="md:hidden p-1 -mr-2 text-white/70 hover:text-white"
            aria-label="Close menu"
          >
            <FiX size={20} />
          </button>
        </div>

        {/* Admin navigation */}
        <nav className="flex-1 px-3 py-4 space-y-1" aria-label="Admin">
          {sidebarLinks.map((link) => [
            link.section && (
              <p key={`section-${link.section}`} className="px-3 pt-4 pb-1.5 text-[11px] font-semibold uppercase tracking-[0.16em] text-white/40 first:pt-0">
                {link.section}
              </p>
            ),
            link.group ? (
            <div key={link.group}>
              <button
                type="button"
                onClick={() => setApprovalsToggled((open) => !open)}
                disabled={inApprovals}
                aria-expanded={approvalsOpen}
                aria-controls="admin-approvals-links"
                className={`flex w-full items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-left transition-colors disabled:cursor-default ${
                  inApprovals
                    ? 'text-white'
                    : 'text-white/70 hover:bg-white/5 hover:text-white'
                }`}
              >
                <link.icon size={18} className="shrink-0" />
                <span className="flex-1">{link.group}</span>
                <FiChevronDown
                  size={16}
                  aria-hidden="true"
                  className={`shrink-0 transition-transform ${approvalsOpen ? 'rotate-180' : ''}`}
                />
              </button>

              {approvalsOpen && (
                <div id="admin-approvals-links" className="mt-1 ml-5 pl-2 border-l border-white/10 space-y-1">
                  {link.children.map((child) => (
                    <NavLink
                      key={child.to}
                      to={child.to}
                      onClick={() => setMenuOpen(false)}
                      className={linkClass}
                    >
                      <child.icon size={16} className="shrink-0" />
                      <span>{child.label}</span>
                    </NavLink>
                  ))}
                </div>
              )}
            </div>
          ) : (
            <NavLink
              key={link.to}
              to={link.to}
              end={link.end}
              onClick={() => setMenuOpen(false)}
              className={linkClass}
            >
              <link.icon
                size={18}
                className="shrink-0"
              />

              <span>{link.label}</span>
            </NavLink>
          ),
          ])}
        </nav>

        {/* Logout */}
        <div className="p-3 border-t border-white/10">
          <div className="flex items-center gap-3 px-3 py-2 mb-1">
            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-accent-400 text-sm font-bold text-gray-900">
              {user?.name?.charAt(0)?.toUpperCase() || 'A'}
            </span>
            <span className="min-w-0 text-sm font-medium truncate">{user?.name}</span>
          </div>
          <button
            type="button"
            onClick={handleLogout}
            className="flex items-center gap-3 px-3 py-2.5 rounded-lg text-white/70 hover:bg-white/5 hover:text-white text-sm font-medium w-full"
          >
            <FiLogOut size={18} />
            Logout
          </button>
        </div>
      </aside>

      {/* Main content */}
      <div className="flex-1 min-w-0 overflow-x-clip">
        {/* Mobile header */}
        <header className="md:hidden sticky top-0 z-20 bg-primary-900 text-white px-4 py-3 flex items-center gap-3">
          <button
            type="button"
            onClick={() => setMenuOpen(true)}
            className="p-1 -ml-1"
            aria-label="Open menu"
            aria-expanded={menuOpen}
          >
            <FiMenu size={22} />
          </button>

          <span className="font-heading font-bold">
            STIM Admin
          </span>

          <div className="ml-auto">
            <NotificationBell tone="dark" />
          </div>
        </header>

        {/* Desktop header */}
        <header className="hidden md:flex items-center justify-between gap-4 bg-white/90 backdrop-blur border-b border-gray-200 px-8 py-3.5 sticky top-0 z-20">
          <p className="min-w-0 truncate text-sm font-medium text-gray-500">
            Tourism Information Management System
          </p>
          <div className="flex shrink-0 items-center gap-4 whitespace-nowrap">
            <a
              href="/"
              target="_blank"
              rel="noopener noreferrer"
              className="inline-flex items-center gap-1.5 text-sm font-semibold text-primary-700 hover:text-primary-800"
            >
              <FiExternalLink size={14} aria-hidden="true" /> View site
            </a>
            <NotificationBell />
            <span className="h-5 w-px bg-gray-200" aria-hidden="true" />
            <span className="flex items-center gap-2 text-sm font-medium text-gray-700">
              <span className="flex h-8 w-8 items-center justify-center rounded-full bg-primary-50 text-primary-700 font-semibold">
                {user?.name?.charAt(0)?.toUpperCase() || 'A'}
              </span>
              {user?.name}
            </span>
          </div>
        </header>

        {/* Page content */}
        <div className="p-4 md:p-8">
          <Outlet />
        </div>
      </div>
    </div>
  )
}
