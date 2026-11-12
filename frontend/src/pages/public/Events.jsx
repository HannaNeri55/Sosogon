import usePageTitle from '../../hooks/usePageTitle'
import { useEffect, useMemo, useState } from 'react'
import { FiBell, FiCalendar } from 'react-icons/fi'
import { getEventsCalendar, getAnnouncements } from '../../api/events'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import EmptyState from '../../components/common/EmptyState'
import LoadError from '../../components/common/LoadError'
import SkeletonGrid from '../../components/common/SkeletonCard'
import EventCalendar from '../../components/calendar/EventCalendar'
import EventCard from '../../components/events/EventCard'
import AnnouncementBoard from '../../components/events/AnnouncementBoard'
import { eventCoversDate, formatDateKey } from '../../utils/helpers'
import useUrlFilters from '../../hooks/useUrlFilters'
import useListingScroll from '../../hooks/useListingScroll'

const today = new Date()

export default function Events() {
  usePageTitle('Events')
  // Tab and calendar month live in the URL, so "Back to Events" returns to the same view.
  const [view, setView] = useUrlFilters({ tab: 'events', year: today.getFullYear(), month: today.getMonth() + 1 })
  const { tab: activeTab, year, month } = view
  const setActiveTab = (tab) => setView((prev) => ({ ...prev, tab }))
  const [announcements, setAnnouncements] = useState([])
  const [loading, setLoading] = useState(true)
  // Failed loads show an error with Try again (the attempt counters re-run them), not "no events".
  const [announcementsFailed, setAnnouncementsFailed] = useState(false)
  const [announcementsAttempt, setAnnouncementsAttempt] = useState(0)
  const [calendarFailed, setCalendarFailed] = useState(false)
  const [calendarAttempt, setCalendarAttempt] = useState(0)

  const [monthEvents, setMonthEvents] = useState([])
  const [calendarLoading, setCalendarLoading] = useState(true)
  const [selectedDateKey, setSelectedDateKey] = useState(formatDateKey(today))

  useEffect(() => {
    getAnnouncements()
      .then((data) => {
        setAnnouncements(data.data || [])
        setAnnouncementsFailed(false)
      })
      .catch(() => setAnnouncementsFailed(true))
      .finally(() => setLoading(false))
  }, [announcementsAttempt])

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setCalendarLoading(true)
    getEventsCalendar({ month, year })
      .then((events) => {
        setCalendarFailed(false)
        setMonthEvents(events)
        const stillValid = events.some((e) => eventCoversDate(e, selectedDateKey))
        if (!stillValid) {
          setSelectedDateKey(events[0] ? events[0].start_date.slice(0, 10) : null)
        }
      })
      .catch(() => setCalendarFailed(true))
      .finally(() => setCalendarLoading(false))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [month, year, calendarAttempt])

  const eventDateKeys = useMemo(() => {
    const keys = new Set()
    monthEvents.forEach((event) => {
      const cursor = new Date(event.start_date.slice(0, 10))
      const end = new Date((event.end_date || event.start_date).slice(0, 10))
      while (cursor <= end) {
        keys.add(formatDateKey(cursor))
        cursor.setDate(cursor.getDate() + 1)
      }
    })
    return keys
  }, [monthEvents])

  function goToMonth(delta) {
    let m = month + delta
    let y = year
    if (m < 1) { m = 12; y -= 1 }
    if (m > 12) { m = 1; y += 1 }
    setView((prev) => ({ ...prev, month: m, year: y }))
  }

  useListingScroll(!loading && !calendarLoading)

  if (loading) return <LoadingSpinner size="lg" />

  return (
    <div className="min-h-screen bg-gray-50">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 py-10 lg:py-14">
        <header className="mb-8 flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
          <div className="max-w-3xl">
            <p className="text-xs font-semibold uppercase tracking-[0.18em] text-secondary-500 mb-2">Happenings</p>
            <h1 className="text-3xl sm:text-4xl font-heading font-bold text-gray-900">Events &amp; Announcements</h1>
            <p className="mt-3 text-gray-600 leading-relaxed">
              Festivals, fiestas and community activities across Sorsogon, plus the latest news from the tourism office.
            </p>
          </div>
          <div role="tablist" aria-label="Events or announcements" className="inline-flex shrink-0 self-start rounded-full bg-white p-1 border border-gray-200 shadow-sm">
            {[
              { key: 'events', label: 'Events' },
              { key: 'announcements', label: 'Announcements' },
            ].map((tab) => (
              <button
                key={tab.key}
                type="button"
                role="tab"
                aria-selected={activeTab === tab.key}
                onClick={() => setActiveTab(tab.key)}
                className={`px-5 py-2 rounded-full text-sm font-semibold transition-colors ${
                  activeTab === tab.key ? 'bg-primary-500 text-white shadow-sm' : 'text-gray-600 hover:text-primary-700'
                }`}
              >
                {tab.label}
              </button>
            ))}
          </div>
        </header>

        {activeTab === 'events' ? (
          <div className="grid lg:grid-cols-[340px_1fr] gap-8 items-start">
            <div className="lg:sticky lg:top-24">
              <EventCalendar
                year={year}
                month={month}
                eventDateKeys={eventDateKeys}
                selectedDateKey={selectedDateKey}
                onSelectDate={setSelectedDateKey}
                onPrevMonth={() => goToMonth(-1)}
                onNextMonth={() => goToMonth(1)}
              />
            </div>

            <div>
              <div className="flex flex-wrap items-baseline justify-between gap-2 mb-5">
                <h3 className="font-heading font-bold text-2xl text-gray-900">
                  {new Date(year, month - 1, 1).toLocaleDateString('en-US', { month: 'long', year: 'numeric' })}
                </h3>
                {!calendarLoading && !calendarFailed && monthEvents.length > 0 && (
                  <p className="text-sm text-gray-600">
                    <span className="font-semibold text-gray-900">{monthEvents.length}</span> event{monthEvents.length === 1 ? '' : 's'} this month
                  </p>
                )}
              </div>
              {calendarLoading ? (
                <SkeletonGrid count={2} className="grid sm:grid-cols-2 gap-5" />
              ) : calendarFailed ? (
                <LoadError what="events" onRetry={() => setCalendarAttempt((n) => n + 1)} />
              ) : monthEvents.length === 0 ? (
                <EmptyState icon={FiCalendar} title="No events this month">
                  Use the arrows on the calendar to look at other months.
                </EmptyState>
              ) : (
                <div className="grid sm:grid-cols-2 gap-5">
                  {monthEvents.map((event) => (
                    <div
                      key={event.id}
                      className={`rounded-(--radius-card) transition-shadow ${
                        selectedDateKey && eventCoversDate(event, selectedDateKey) ? 'ring-2 ring-accent-400 ring-offset-2 ring-offset-gray-50' : ''
                      }`}
                    >
                      <EventCard event={event} />
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>
        ) : announcementsFailed ? (
          <LoadError what="announcements" onRetry={() => setAnnouncementsAttempt((n) => n + 1)} />
        ) : announcements.length === 0 ? (
          <EmptyState icon={FiBell} title="No announcements yet">
            News and advisories from the tourism office will appear here.
          </EmptyState>
        ) : (
          <AnnouncementBoard announcements={announcements} />
        )}
      </div>
    </div>
  )
}