import { FiChevronLeft, FiChevronRight } from 'react-icons/fi'
import { getMonthMatrix, formatDateKey } from '../../utils/helpers'

const WEEKDAYS = ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN']
const MONTH_NAMES = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
]

export default function EventCalendar({
  year,
  month,
  eventDateKeys,
  selectedDateKey,
  onSelectDate,
  onPrevMonth,
  onNextMonth,
}) {
  const cells = getMonthMatrix(year, month)
  const todayKey = formatDateKey(new Date())

  return (
    <div className="card p-5">
      <h2 className="font-heading font-bold text-2xl text-gray-900 mb-4 uppercase tracking-wide">
        Select a date
      </h2>

      <div className="flex items-center justify-between mb-4">
        <button type="button" onClick={onPrevMonth} aria-label="Previous month" className="p-1.5 rounded hover:bg-gray-100 text-gray-500">
          <FiChevronLeft size={18} />
        </button>
        <span className="font-heading font-bold text-primary-700 uppercase">
          {MONTH_NAMES[month - 1]} {year}
        </span>
        <button type="button" onClick={onNextMonth} aria-label="Next month" className="p-1.5 rounded hover:bg-gray-100 text-gray-500">
          <FiChevronRight size={18} />
        </button>
      </div>

      <div className="grid grid-cols-7 gap-y-1 text-center">
        {WEEKDAYS.map((wd) => (
          <span key={wd} className="text-xs font-semibold text-secondary-600 pb-2">
            {wd}
          </span>
        ))}

        {cells.map((cell) => {
          const hasEvent = eventDateKeys.has(cell.dateKey)
          const isSelected = cell.dateKey === selectedDateKey
          const isToday = cell.dateKey === todayKey

          return (
            <button
              key={cell.dateKey}
              type="button"
              disabled={!cell.inMonth}
              onClick={() => onSelectDate(cell.dateKey)}
              className={[
                'py-2 rounded-lg text-sm font-medium transition-colors',
                !cell.inMonth && 'text-gray-300 cursor-default',
                cell.inMonth && !isSelected && 'text-gray-900 hover:bg-primary-50',
                cell.inMonth && !isSelected && isToday && 'ring-1 ring-primary-400',
                isSelected && 'bg-accent-500 text-white',
              ].filter(Boolean).join(' ')}
            >
              <span className="flex flex-col items-center gap-0.5">
                {cell.day}
                {hasEvent && (
                  <span className={`w-1 h-1 rounded-full ${isSelected ? 'bg-white' : 'bg-red-500'}`} />
                )}
              </span>
            </button>
          )
        })}
      </div>
    </div>
  )
}