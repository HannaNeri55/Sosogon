import { useEffect, useRef, useState } from 'react'
import toast from 'react-hot-toast'
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
import { FiEye, FiSearch, FiTrendingUp, FiStar, FiDownload, FiExternalLink, FiFileText, FiShare2 } from 'react-icons/fi'
import { getReportOverview, getReportVisitors, getReportPopular, getReportSearches, exportReportPdf } from '../../api/admin'
import StatCard from '../../components/common/StatCard'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import Modal from '../../components/common/Modal'
import { fileSize, isPdfBlob } from '../../utils/downloadedItineraries'
import { canShareFile, saveBlobAs } from '../../utils/itineraryExport'
import { showApiError } from '../../utils/apiErrors'
import useAdminScope from '../../hooks/useAdminScope'

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, BarElement, Title, Tooltip, Legend)
// Charts use the site's body font instead of Chart.js's default Helvetica.
ChartJS.defaults.font.family = getComputedStyle(document.documentElement).getPropertyValue('--font-body') || 'Inter, sans-serif'

export default function ReportsAnalytics() {
  // A municipal admin's reports cover their municipality (its listings' page views and its
  // destinations). Site searches aren't tied to a municipality, so they're the provincial admins'.
  const scope = useAdminScope()
  const town = scope.municipality?.name
  const [overview, setOverview] = useState(null)
  const [visitorData, setVisitorData] = useState([])
  const [popularData, setPopularData] = useState([])
  const [searchData, setSearchData] = useState([])
  const [loading, setLoading] = useState(true)
  const [exporting, setExporting] = useState(false)
  // The generated PDF, shown in a dialog to open, save or share.
  const [report, setReport] = useState(null)
  const reportUrl = useRef(null)

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

  // A phone's browser reads a file link some time after it is opened (its download manager or
  // PDF viewer), so the link stays valid for a while after the dialog closes.
  const releaseUrl = (url) => url && setTimeout(() => URL.revokeObjectURL(url), 5 * 60 * 1000)
  useEffect(() => () => releaseUrl(reportUrl.current), [])

  const handleExport = async () => {
    setExporting(true)
    try {
      const blob = await exportReportPdf()
      if (!(await isPdfBlob(blob))) {
        toast.error('The report could not be created. Please try again.')
        return
      }
      releaseUrl(reportUrl.current)
      const url = URL.createObjectURL(blob)
      reportUrl.current = url
      setReport({ blob, url, filename: `reports-analytics-${new Date().toISOString().slice(0, 10)}.pdf` })
    } catch (error) {
      showApiError(error, 'Failed to export the report')
    } finally {
      setExporting(false)
    }
  }

  // Opens the PDF in the browser's or phone's PDF viewer; when a new tab is refused (e.g. an
  // in-app browser), the file is saved to the device instead.
  const openReport = () => {
    if (!window.open(report.url, '_blank')) {
      saveBlobAs(report.blob, report.filename)
      toast('Your browser saved the PDF instead. Find it in your Files app or Downloads.', { duration: 6000 })
    }
  }

  const reportFile = report ? new File([report.blob], report.filename, { type: 'application/pdf' }) : null
  const shareReport = async () => {
    try {
      await navigator.share({ files: [reportFile], title: 'Reports and Analytics' })
    } catch (error) {
      if (error?.name !== 'AbortError') toast.error('Could not share the PDF. Use Download instead.')
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
          <p className="mt-2 text-sm text-gray-500">
            {scope.isMunicipal ? `${town}: page views of its destination, listing and event pages, and its destinations.` : 'The whole province.'}
          </p>
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

      {report && (
        <Modal
          title="Report ready"
          subtitle={scope.isMunicipal ? `${town}, last 30 days` : 'Whole province, last 30 days'}
          size="xl"
          onClose={() => setReport(null)}
          footer={
            <>
              {canShareFile(reportFile) && (
                <button type="button" onClick={shareReport} className="inline-flex min-h-11 flex-1 items-center justify-center gap-1.5 rounded-lg border border-primary-200 bg-white px-4 text-sm font-medium text-primary-700 hover:bg-primary-50 sm:flex-none">
                  <FiShare2 size={16} aria-hidden="true" /> Share
                </button>
              )}
              <button type="button" onClick={() => saveBlobAs(report.blob, report.filename)} className="inline-flex min-h-11 flex-1 items-center justify-center gap-1.5 rounded-lg border border-primary-200 bg-white px-4 text-sm font-medium text-primary-700 hover:bg-primary-50 sm:flex-none">
                <FiDownload size={16} aria-hidden="true" /> Download
              </button>
              <button type="button" onClick={openReport} className="btn-primary min-h-11 basis-full justify-center sm:basis-auto">
                <FiExternalLink size={16} aria-hidden="true" /> Open PDF
              </button>
            </>
          }
        >
          <div className="flex items-center gap-3 rounded-xl border border-gray-200 bg-gray-50 p-4">
            <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-600">
              <FiFileText size={20} aria-hidden="true" />
            </span>
            <div className="min-w-0">
              <p className="font-medium text-gray-900 wrap-anywhere">{report.filename}</p>
              <p className="text-xs text-gray-500">PDF · {fileSize(report.blob.size)}</p>
            </div>
          </div>
          {/* Phone browsers don't show PDFs inside a page; Open PDF uses the phone's viewer there. */}
          <iframe src={report.url} title="Report preview" className="mt-4 hidden h-[60vh] w-full rounded-xl border border-gray-200 lg:block" />
          <p className="mt-4 text-sm text-gray-500 lg:hidden">Press Open PDF to view the report, or Download to keep it on this device.</p>
        </Modal>
      )}

      <div className={`grid grid-cols-1 gap-4 mb-8 ${scope.isProvincial ? 'sm:grid-cols-2' : ''}`}>
        <StatCard icon={FiEye} label={scope.isMunicipal ? `Views of ${town} Pages (Last 30 Days)` : 'Views (Last 30 Days)'} value={overview?.monthly_views || 0} accent="primary" />
        {overview?.monthly_searches != null && (
          <StatCard icon={FiSearch} label="Searches (Last 30 Days)" value={overview.monthly_searches} accent="secondary" />
        )}
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
          {popularData.length === 0 ? (
            <p className="text-gray-500">No published destinations{scope.isMunicipal ? ` in ${town}` : ''} yet.</p>
          ) : (
            <Bar data={popularChartData} />
          )}
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
          {overview?.monthly_searches == null ? (
            <p className="text-gray-500">
              Site searches aren't tied to a municipality, so they're reported to the provincial administrator only.
            </p>
          ) : searchData.length === 0 ? (
            <p className="text-gray-500">No one has searched the site in the last 30 days.</p>
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