import { useEffect, useMemo, useState } from 'react'
import { getEventsCalendar, getAnnouncements } from '../../api/events'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import EventCalendar from '../../components/calendar/EventCalendar'
import EventCard from '../../components/events/EventCard'
import HeroSlideshow from '../../components/common/HeroSlideshow'
import { eventCoversDate, formatDateKey } from '../../utils/helpers'

const today = new Date()

export default function Events() {
  const [activeTab, setActiveTab] = useState('events')
  const [announcements, setAnnouncements] = useState([])
  const [loading, setLoading] = useState(true)

  const [year, setYear] = useState(today.getFullYear())
  const [month, setMonth] = useState(today.getMonth() + 1)
  const [monthEvents, setMonthEvents] = useState([])
  const [calendarLoading, setCalendarLoading] = useState(true)
  const [selectedDateKey, setSelectedDateKey] = useState(formatDateKey(today))

  useEffect(() => {
    getAnnouncements()
      .then((data) => setAnnouncements(data.data || []))
      .finally(() => setLoading(false))
  }, [])

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setCalendarLoading(true)
    getEventsCalendar({ month, year })
      .then((events) => {
        setMonthEvents(events)
        const stillValid = events.some((e) => eventCoversDate(e, selectedDateKey))
        if (!stillValid) {
          setSelectedDateKey(events[0] ? events[0].start_date.slice(0, 10) : null)
        }
      })
      .finally(() => setCalendarLoading(false))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [month, year])

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
    setMonth(m)
    setYear(y)
  }

  if (loading) return <LoadingSpinner size="lg" />

  return (
    <div className="min-h-screen bg-gray-50">
      <HeroSlideshow />

      <div className="max-w-6xl mx-auto px-4 sm:px-6 py-12">
        <div className="flex gap-3 mb-8">
          <button onClick={() => setActiveTab('events')} className={`px-5 py-2.5 rounded-lg font-medium ${activeTab === 'events' ? 'bg-primary-500 text-white' : 'bg-white border border-gray-200'}`}>
            Events
          </button>
          <button onClick={() => setActiveTab('announcements')} className={`px-5 py-2.5 rounded-lg font-medium ${activeTab === 'announcements' ? 'bg-primary-500 text-white' : 'bg-white border border-gray-200'}`}>
            Announcements
          </button>
        </div>

        {activeTab === 'events' ? (
          <div className="grid md:grid-cols-[340px_1fr] gap-8">
            <EventCalendar
              year={year}
              month={month}
              eventDateKeys={eventDateKeys}
              selectedDateKey={selectedDateKey}
              onSelectDate={setSelectedDateKey}
              onPrevMonth={() => goToMonth(-1)}
              onNextMonth={() => goToMonth(1)}
            />

            <div>
              <h2 className="font-heading font-bold text-2xl text-gray-900 mb-4">Events this month</h2>
              {calendarLoading ? (
                <LoadingSpinner />
              ) : monthEvents.length === 0 ? (
                <p className="text-gray-500">No festivals this month.</p>
              ) : (
                <div className="grid sm:grid-cols-2 gap-5">
                  {monthEvents.map((event) => (
                    <div
                      key={event.id}
                      className={selectedDateKey && eventCoversDate(event, selectedDateKey) ? 'ring-2 ring-accent-500 rounded-xl' : ''}
                    >
                      <EventCard event={event} />
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>
        ) : (
          <div className="space-y-6">
            {announcements.map((announcement) => (
              <div key={announcement.id} className="card p-6">
                {announcement.is_pinned && <span className="inline-block text-xs bg-accent-100 text-accent-700 px-2 py-1 rounded mb-3">Pinned</span>}
                <h3 className="text-2xl font-heading font-semibold text-gray-900 mb-2">{announcement.title}</h3>
                <p className="text-sm text-gray-500 mb-3">{new Date(announcement.created_at).toLocaleDateString()}</p>
                <p className="text-gray-600">{announcement.content}</p>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  )
}