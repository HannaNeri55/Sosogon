import { useEffect, useState } from 'react'
import { Line, Bar } from 'react-chartjs-2'
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  BarElement,
  Title,
  Tooltip,
  Legend,
} from 'chart.js'
import { FiEye, FiSearch, FiTrendingUp, FiStar, FiDownload } from 'react-icons/fi'
import { getReportOverview, getReportVisitors, getReportPopular, getReportSearches, exportReportPdf } from '../../api/admin'
import StatCard from '../../components/common/StatCard'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import { showApiError } from '../../utils/apiErrors'

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, BarElement, Title, Tooltip, Legend)
// Charts use the site's body font instead of Chart.js's default Helvetica.
ChartJS.defaults.font.family = getComputedStyle(document.documentElement).getPropertyValue('--font-body') || 'Inter, sans-serif'

export default function ReportsAnalytics() {
  const [overview, setOverview] = useState(null)
  const [visitorData, setVisitorData] = useState([])
  const [popularData, setPopularData] = useState([])
  const [searchData, setSearchData] = useState([])
  const [loading, setLoading] = useState(true)
  const [exporting, setExporting] = useState(false)

  useEffect(() => {
    Promise.all([getReportOverview(), getReportVisitors(30), getReportPopular(10), getReportSearches()])
      .then(([overviewData, visitors, popular, searches]) => {
        setOverview(overviewData)
        setVisitorData(visitors || [])
        setPopularData(popular || [])
        setSearchData(searches || [])
      })
      .finally(() => setLoading(false))
  }, [])

  const handleExport = async () => {
    setExporting(true)
    try {
      const blob = await exportReportPdf()
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = `reports-analytics-${new Date().toISOString().slice(0, 10)}.pdf`
      document.body.appendChild(link)
      link.click()
      link.remove()
      URL.revokeObjectURL(url)
    } catch (error) {
      showApiError(error, 'Failed to export the report')
    } finally {
      setExporting(false)
    }
  }

  if (loading) return <LoadingSpinner size="lg" />

  const visitorChartData = {
    labels: visitorData.map((d) => new Date(d.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })),
    datasets: [
      {
        label: 'Page Views',
        data: visitorData.map((d) => d.total_views),
        borderColor: '#2E6B4E',
        backgroundColor: 'rgba(46, 107, 78, 0.1)',
        tension: 0.3,
      },
    ],
  }

  const popularChartData = {
    labels: popularData.map((d) => d.name),
    datasets: [
      {
        label: 'Views',
        data: popularData.map((d) => d.view_count),
        backgroundColor: '#0F7A94',
      },
    ],
  }

  return (
    <div>
      <div className="mb-8 flex flex-wrap items-end justify-between gap-4">
        <div>
          <p className="text-primary-600 font-medium mb-2">Admin</p>
          <h1 className="text-2xl sm:text-3xl font-heading font-bold text-gray-900">Reports and Analytics</h1>
        </div>
        <button
          type="button"
          onClick={handleExport}
          disabled={exporting}
          className="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg bg-primary-600 text-white font-medium hover:bg-primary-700 disabled:opacity-60 disabled:cursor-not-allowed transition-colors"
        >
          <FiDownload size={18} />
          {exporting ? 'Exporting...' : 'Export PDF'}
        </button>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-8">
        <StatCard icon={FiEye} label="Views (Last 30 Days)" value={overview?.monthly_views || 0} accent="primary" />
        <StatCard icon={FiSearch} label="Searches (Last 30 Days)" value={overview?.monthly_searches || 0} accent="secondary" />
      </div>

      <div className="grid grid-cols-1 gap-8">
        <div className="bg-white rounded-xl p-6 border border-gray-100">
          <div className="flex items-center gap-2 mb-6">
            <FiTrendingUp className="text-primary-600" size={20} />
            <h2 className="text-xl font-heading font-semibold">Visitor Trend (Last 30 Days)</h2>
          </div>
          <Line data={visitorChartData} />
        </div>

        <div className="bg-white rounded-xl p-6 border border-gray-100">
          <h2 className="text-xl font-heading font-semibold mb-6">Popular Destinations</h2>
          <Bar data={popularChartData} />
          <div className="mt-6 space-y-2">
            {popularData.map((d, i) => (
              <div key={d.id} className="flex items-center justify-between py-2 border-t border-gray-50 first:border-t-0">
                <span className="text-sm text-gray-700"><span className="text-gray-400 mr-2">#{i + 1}</span>{d.name}</span>
                <div className="flex items-center gap-4 text-sm">
                  <span className="flex items-center gap-1 text-gray-500"><FiStar size={13} className="text-yellow-500" />{d.average_rating} ({d.total_reviews})</span>
                  <span className="flex items-center gap-1 text-gray-500"><FiEye size={13} />{d.view_count}</span>
                </div>
              </div>
            ))}
          </div>
        </div>

        <div className="bg-white rounded-xl p-6 border border-gray-100">
          <h2 className="text-xl font-heading font-semibold mb-6">Popular Searches</h2>
          {searchData.length === 0 ? (
            <p className="text-gray-500">No search data available.</p>
          ) : (
            <div className="space-y-3">
              {searchData.map((item, idx) => (
                <div key={idx} className="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                  <span className="text-gray-900 font-medium">{item.query}</span>
                  <span className="text-primary-600 font-semibold">{item.count}</span>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  )
}