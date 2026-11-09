import { Link } from 'react-router-dom'
import { useEffect, useState } from 'react'
import {
  FiMapPin,
  FiHome,
  FiCoffee,
  FiShoppingBag,
  FiActivity,
  FiCalendar,
  FiClock,
  FiUsers,
  FiEye,
  FiTrendingUp,
  FiFileText,
  FiBarChart2,
  FiArrowRight,
  FiCheckCircle,
} from 'react-icons/fi'
import { getAdminDashboard } from '../../api/admin'
import { useAuth } from '../../contexts/AuthContext'
import StatCard from '../../components/common/StatCard'
import LoadingSpinner from '../../components/common/LoadingSpinner'

function VisitorTrendChart({ data }) {
  const max = Math.max(...data.map((d) => d.views), 1)
  const total = data.reduce((sum, d) => sum + d.views, 0)

  return (
    <div>
      <p className="mb-5 text-sm text-gray-500">
        <span className="font-heading text-2xl font-bold text-gray-900 tabular-nums">{total}</span> page views this week
      </p>
      <div className="flex items-end justify-between gap-2 sm:gap-4 h-40 border-b border-gray-100">
        {data.map((d) => (
          <div key={d.date} className="group flex-1 flex flex-col items-center justify-end h-full gap-1.5">
            <span className="text-xs font-semibold text-gray-700 tabular-nums opacity-0 group-hover:opacity-100 transition-opacity">
              {d.views}
            </span>
            <div
              className="w-full max-w-10 bg-primary-400 group-hover:bg-primary-600 rounded-t-lg transition-all duration-500"
              style={{ height: `${Math.max((d.views / max) * 110, 4)}px` }}
              title={`${d.views} views`}
            />
          </div>
        ))}
      </div>
      <div className="mt-2 flex justify-between gap-2 sm:gap-4">
        {data.map((d) => (
          <span key={d.date} className="flex-1 text-center text-xs font-medium text-gray-500">
            {new Date(d.date).toLocaleDateString('en-US', { weekday: 'short' })}
          </span>
        ))}
      </div>
    </div>
  )
}

function Panel({ icon: Icon, title, action, children }) {
  return (
    <section className="bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) p-5 sm:p-6">
      <div className="flex items-center justify-between gap-3 mb-5">
        <h2 className="flex items-center gap-2 text-lg font-heading font-bold text-gray-900">
          <Icon className="text-primary-600" size={18} aria-hidden="true" /> {title}
        </h2>
        {action}
      </div>
      {children}
    </section>
  )
}

const QUICK_LINKS = [
  { to: '/admin/destinations', icon: FiMapPin, label: 'Manage Destinations' },
  { to: '/admin/events', icon: FiCalendar, label: 'Manage Events' },
  { to: '/admin/submissions', icon: FiFileText, label: 'Review Submissions' },
  { to: '/admin/users', icon: FiUsers, label: 'Manage Users' },
  { to: '/admin/reports', icon: FiBarChart2, label: 'Reports & Analytics' },
]

export default function AdminDashboard() {
  const { user } = useAuth()
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    getAdminDashboard().then(setData).finally(() => setLoading(false))
  }, [])

  if (loading) return <LoadingSpinner size="lg" />

  const stats = data?.stats || {}
  const pending = stats.pending_submissions || 0
  const today = new Date().toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })

  return (
    <div className="space-y-8">
      {/* ==================== GREETING ==================== */}
      <header className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <p className="text-sm font-medium text-gray-500">{today}</p>
          <h1 className="text-2xl sm:text-3xl font-heading font-bold text-gray-900 mt-1">
            Welcome back{user?.name ? `, ${user.name.split(' ')[0]}` : ''}
          </h1>
          <p className="text-gray-600 mt-1">Here&apos;s what&apos;s happening across Sorsogon tourism today.</p>
        </div>
        {pending > 0 ? (
          <Link
            to="/admin/submissions"
            className="inline-flex items-center gap-2 self-start rounded-full bg-accent-50 border border-accent-100 px-4 py-2 text-sm font-semibold text-accent-700 hover:bg-accent-100 transition-colors"
          >
            <FiClock size={15} aria-hidden="true" /> {pending} submission{pending === 1 ? '' : 's'} waiting for review
            <FiArrowRight size={14} aria-hidden="true" />
          </Link>
        ) : (
          <span className="inline-flex items-center gap-2 self-start rounded-full bg-secondary-50 border border-secondary-100 px-4 py-2 text-sm font-semibold text-secondary-700">
            <FiCheckCircle size={15} aria-hidden="true" /> All submissions reviewed
          </span>
        )}
      </header>

      {/* ==================== NUMBERS ==================== */}
      <section aria-label="Listings">
        <h2 className="text-xs font-semibold uppercase tracking-wider text-gray-500 mb-3">Listings</h2>
        <div className="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4">
          <StatCard icon={FiMapPin} label="Destinations" value={stats.total_destinations || 0} accent="primary" to="/admin/destinations" />
          <StatCard icon={FiHome} label="Accommodations" value={stats.total_accommodations || 0} accent="secondary" />
          <StatCard icon={FiCoffee} label="Food & Beverage" value={stats.total_food_beverages || 0} accent="green" />
          <StatCard icon={FiShoppingBag} label="Retail Products" value={stats.total_retail_products || 0} accent="yellow" />
          <StatCard icon={FiActivity} label="Activities" value={stats.total_activities || 0} accent="secondary" />
          <StatCard icon={FiCalendar} label="Events" value={stats.total_events || 0} accent="green" to="/admin/events" />
        </div>
      </section>

      <section aria-label="Operations">
        <h2 className="text-xs font-semibold uppercase tracking-wider text-gray-500 mb-3">Operations</h2>
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <StatCard icon={FiClock} label="Pending Submissions" value={pending} accent="yellow" to="/admin/submissions" />
          <StatCard icon={FiUsers} label="Total Users" value={stats.total_users || 0} accent="secondary" to="/admin/users" />
          <StatCard icon={FiEye} label="Monthly Views" value={stats.monthly_views || 0} accent="green" to="/admin/reports" />
        </div>
      </section>

      {/* ==================== TREND + QUICK ACTIONS ==================== */}
      <div className="grid grid-cols-1 xl:grid-cols-3 gap-6">
        <div className="xl:col-span-2">
          <Panel icon={FiTrendingUp} title="Visitor Trend — Last 7 Days">
            {data?.visitor_trend?.length > 0 ? (
              <VisitorTrendChart data={data.visitor_trend} />
            ) : (
              <p className="text-gray-500">No visitor data yet.</p>
            )}
          </Panel>
        </div>
        <Panel icon={FiArrowRight} title="Quick actions">
          <div className="space-y-2">
            {QUICK_LINKS.map((link) => (
              <Link
                key={link.to}
                to={link.to}
                className="group flex items-center gap-3 rounded-xl border border-gray-100 p-3 hover:border-primary-200 hover:bg-primary-50/50 transition-colors"
              >
                <span className="w-9 h-9 rounded-lg bg-primary-50 text-primary-600 flex items-center justify-center shrink-0 group-hover:bg-primary-500 group-hover:text-white transition-colors">
                  <link.icon size={16} aria-hidden="true" />
                </span>
                <span className="flex-1 text-sm font-medium text-gray-700">{link.label}</span>
                <FiArrowRight size={15} className="text-gray-400 group-hover:text-primary-600 transition-colors" aria-hidden="true" />
              </Link>
            ))}
          </div>
        </Panel>
      </div>

      {/* ==================== QUEUES ==================== */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Panel
          icon={FiClock}
          title="Pending Approvals"
          action={
            <Link to="/admin/submissions" className="text-primary-700 text-sm font-semibold hover:text-primary-800">
              View all
            </Link>
          }
        >
          {data?.pending_submissions?.length === 0 ? (
            <div className="text-center py-10">
              <FiCheckCircle className="mx-auto text-secondary-400 mb-3" size={30} aria-hidden="true" />
              <p className="text-gray-500">No pending submissions.</p>
            </div>
          ) : (
            <ul className="divide-y divide-gray-100">
              {data?.pending_submissions?.map((item) => (
                <li key={item.id} className="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
                  <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-accent-50 text-accent-700 text-sm font-bold">
                    {item.business_name?.charAt(0)?.toUpperCase()}
                  </span>
                  <div className="min-w-0">
                    <p className="font-medium text-gray-900 truncate">{item.business_name}</p>
                    <p className="text-sm text-gray-500 truncate">by {item.user?.name}</p>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Panel>

        <Panel icon={FiEye} title="Recent Activity">
          {data?.recent_activity?.length === 0 ? (
            <div className="text-center py-10">
              <FiEye className="mx-auto text-gray-300 mb-3" size={30} aria-hidden="true" />
              <p className="text-gray-500">No recent activity.</p>
            </div>
          ) : (
            <ul className="space-y-4">
              {data?.recent_activity?.map((activity) => (
                <li key={activity.id} className="flex gap-3">
                  <span className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-primary-400" aria-hidden="true" />
                  <div className="min-w-0">
                    <p className="font-medium text-gray-900">{activity.action}</p>
                    <p className="text-sm text-gray-500">{activity.description}</p>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Panel>
      </div>
    </div>
  )
}
