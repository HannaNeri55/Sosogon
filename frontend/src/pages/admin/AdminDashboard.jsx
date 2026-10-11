import { Link } from 'react-router-dom'
import { useEffect, useState } from 'react'
import { FiMapPin, FiHome, FiCoffee, FiShoppingBag, FiActivity, FiCalendar, FiClock, FiUsers, FiEye, FiTrendingUp, FiFileText, FiBarChart2 } from 'react-icons/fi'
import { getAdminDashboard } from '../../api/admin'
import StatCard from '../../components/common/StatCard'
import LoadingSpinner from '../../components/common/LoadingSpinner'

function VisitorTrendChart({ data }) {
  const max = Math.max(...data.map((d) => d.views), 1)
  return (
    <div className="flex items-end justify-between gap-2 h-32">
      {data.map((d) => (
        <div key={d.date} className="flex-1 flex flex-col items-center justify-end h-full gap-2">
          <div
            className="w-full max-w-8 bg-primary-500 rounded-t-md transition-all duration-500 hover:bg-primary-600"
            style={{ height: `${Math.max((d.views / max) * 96, 4)}px` }}
            title={`${d.views} views`}
          />
          <span className="text-[10px] text-gray-400 font-medium">
            {new Date(d.date).toLocaleDateString('en-US', { weekday: 'short' })}
          </span>
        </div>
      ))}
    </div>
  )
}

const QUICK_LINKS = [
  { to: '/admin/destinations', icon: FiMapPin, label: 'Manage Destinations' },
  { to: '/admin/activities', icon: FiActivity, label: 'Manage Activities' },
  { to: '/admin/events', icon: FiCalendar, label: 'Manage Events' },
  { to: '/admin/submissions', icon: FiFileText, label: 'Review Submissions' },
  { to: '/admin/users', icon: FiUsers, label: 'Manage Users' },
  { to: '/admin/reports', icon: FiBarChart2, label: 'Reports & Analytics' },
]

export default function AdminDashboard() {
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    getAdminDashboard().then(setData).finally(() => setLoading(false))
  }, [])

  if (loading) return <LoadingSpinner size="lg" />

  const stats = data?.stats || {}

  return (
    <div>
      <div className="bg-gradient-to-br from-primary-800 to-primary-600 rounded-2xl p-8 mb-8">
        <p className="text-primary-100 text-sm font-medium mb-1">Admin Dashboard</p>
        <h1 className="text-3xl font-heading font-bold text-white">Overview</h1>
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
        <StatCard icon={FiMapPin} label="Destinations" value={stats.total_destinations || 0} accent="primary" />
        <StatCard icon={FiHome} label="Accommodations" value={stats.total_accommodations || 0} accent="secondary" />
        <StatCard icon={FiCoffee} label="Food & Beverage" value={stats.total_food_beverages || 0} accent="green" />
        <StatCard icon={FiShoppingBag} label="Retail Products" value={stats.total_retail_products || 0} accent="yellow" />
      </div>
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
        <StatCard icon={FiActivity} label="Activities" value={stats.total_activities || 0} accent="secondary" />
        <StatCard icon={FiCalendar} label="Events" value={stats.total_events || 0} accent="green" />
      </div>
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
        <StatCard icon={FiClock} label="Pending Submissions" value={stats.pending_submissions || 0} accent="yellow" />
        <StatCard icon={FiUsers} label="Total Users" value={stats.total_users || 0} accent="secondary" />
        <StatCard icon={FiEye} label="Monthly Views" value={stats.monthly_views || 0} accent="green" />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-4 gap-4 mb-8">
        {QUICK_LINKS.map((link) => (
          <Link
            key={link.to}
            to={link.to}
            className="group bg-white rounded-xl border border-gray-100 p-5 flex items-center gap-3 hover:border-primary-300 hover:shadow-md transition-all duration-300"
          >
            <div className="w-9 h-9 rounded-lg bg-primary-50 text-primary-600 flex items-center justify-center shrink-0 group-hover:bg-primary-500 group-hover:text-white transition-colors duration-300">
              <link.icon size={16} />
            </div>
            <span className="text-sm font-medium text-gray-700">{link.label}</span>
          </Link>
        ))}
      </div>

      <div className="bg-white rounded-xl p-6 border border-gray-100 mb-8">
        <div className="flex items-center gap-2 mb-6">
          <FiTrendingUp className="text-primary-600" size={20} />
          <h2 className="text-xl font-heading font-semibold">Visitor Trend — Last 7 Days</h2>
        </div>
        {data?.visitor_trend?.length > 0 ? (
          <VisitorTrendChart data={data.visitor_trend} />
        ) : (
          <p className="text-gray-500">No visitor data yet.</p>
        )}
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
        <div className="bg-white rounded-xl p-6 border border-gray-100">
          <div className="flex items-center justify-between mb-4">
            <div className="flex items-center gap-2">
              <FiClock className="text-primary-600" size={18} />
              <h2 className="text-xl font-heading font-semibold">Pending Approvals</h2>
            </div>
            <Link to="/admin/submissions" className="text-primary-600 text-sm font-medium hover:text-primary-700">View all</Link>
          </div>
          {data?.pending_submissions?.length === 0 ? (
            <div className="text-center py-10">
              <FiClock className="mx-auto text-gray-300 mb-3" size={32} />
              <p className="text-gray-500">No pending submissions.</p>
            </div>
          ) : (
            <div className="space-y-3">
              {data?.pending_submissions?.map((item) => (
                <div key={item.id} className="p-4 border border-gray-100 rounded-lg hover:bg-gray-50 transition-colors duration-200">
                  <h3 className="font-medium text-gray-900">{item.business_name}</h3>
                  <p className="text-sm text-gray-500 mt-1">by {item.user?.name}</p>
                </div>
              ))}
            </div>
          )}
        </div>

        <div className="bg-white rounded-xl p-6 border border-gray-100">
          <div className="flex items-center gap-2 mb-4">
            <FiEye className="text-primary-600" size={18} />
            <h2 className="text-xl font-heading font-semibold">Recent Activity</h2>
          </div>
          {data?.recent_activity?.length === 0 ? (
            <div className="text-center py-10">
              <FiEye className="mx-auto text-gray-300 mb-3" size={32} />
              <p className="text-gray-500">No recent activity.</p>
            </div>
          ) : (
            <div className="space-y-3">
              {data?.recent_activity?.map((activity) => (
                <div key={activity.id} className="p-4 border border-gray-100 rounded-lg">
                  <p className="font-medium text-gray-900">{activity.action}</p>
                  <p className="text-sm text-gray-500 mt-1">{activity.description}</p>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  )
}