import { FiArrowUp, FiArrowDown, FiTrash2, FiPlus } from 'react-icons/fi'
import { splitSlot, joinSlot, slotError } from '../../utils/timeSlots'

const TIME_INPUT = 'text-xs text-gray-700 bg-white border border-gray-200 rounded-md px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-primary-200 focus:border-primary-400'

// Timed activities inside a stop: [{ time: "7:30 AM – 8:30 AM", name, description }].
export default function ActivityEditor({ activities, onChange, disabled = false }) {
  const update = (index, patch) => onChange(activities.map((a, i) => (i === index ? { ...a, ...patch } : a)))

  const move = (index, delta) => {
    const next = [...activities]
    const [moved] = next.splice(index, 1)
    next.splice(index + delta, 0, moved)
    onChange(next)
  }

  const add = () => {
    const lastEnd = activities.length ? splitSlot(activities[activities.length - 1].time).end : ''
    onChange([...activities, { time: joinSlot(lastEnd, ''), name: '', description: null }])
  }

  return (
    <div>
      <div className="flex items-center justify-between mb-2">
        <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">Activities</p>
        <button
          type="button"
          onClick={add}
          disabled={disabled || activities.length >= 15}
          className="inline-flex items-center gap-1 text-xs font-medium text-primary-600 hover:text-primary-700 disabled:opacity-40"
        >
          <FiPlus size={13} /> Add activity
        </button>
      </div>

      {activities.length === 0 ? (
        <p className="text-xs text-gray-400 border border-dashed border-gray-200 rounded-lg px-3 py-3 text-center">
          No activities yet.
        </p>
      ) : (
        <ol className="space-y-2">
          {activities.map((activity, index) => {
            const { start, end } = splitSlot(activity.time)
            const error = slotError(activity.time) || (!activity.name.trim() && 'Name the activity')
            return (
              <li key={index} className="rounded-lg border border-gray-200 bg-white p-2.5">
                <div className="flex flex-wrap items-center gap-2">
                  <input
                    type="time"
                    value={start}
                    disabled={disabled}
                    onChange={(e) => update(index, { time: joinSlot(e.target.value, end) })}
                    className={TIME_INPUT}
                    aria-label="Activity start"
                  />
                  <span className="text-xs text-gray-400">to</span>
                  <input
                    type="time"
                    value={end}
                    disabled={disabled}
                    onChange={(e) => update(index, { time: joinSlot(start, e.target.value) })}
                    className={TIME_INPUT}
                    aria-label="Activity end"
                  />
                  <input
                    type="text"
                    value={activity.name}
                    disabled={disabled}
                    maxLength={120}
                    onChange={(e) => update(index, { name: e.target.value })}
                    placeholder="What to do"
                    className="flex-1 min-w-40 text-sm text-gray-800 bg-white border border-gray-200 rounded-md px-2.5 py-1.5 focus:outline-none focus:ring-2 focus:ring-primary-200 focus:border-primary-400"
                  />
                  <div className="flex items-center">
                    <button
                      type="button"
                      onClick={() => move(index, -1)}
                      disabled={disabled || index === 0}
                      className="p-1.5 rounded-md text-gray-400 hover:text-gray-700 hover:bg-gray-100 disabled:opacity-30"
                      title="Move up"
                    >
                      <FiArrowUp size={14} />
                    </button>
                    <button
                      type="button"
                      onClick={() => move(index, 1)}
                      disabled={disabled || index === activities.length - 1}
                      className="p-1.5 rounded-md text-gray-400 hover:text-gray-700 hover:bg-gray-100 disabled:opacity-30"
                      title="Move down"
                    >
                      <FiArrowDown size={14} />
                    </button>
                    <button
                      type="button"
                      onClick={() => onChange(activities.filter((_, i) => i !== index))}
                      disabled={disabled}
                      className="p-1.5 rounded-md text-red-400 hover:text-red-600 hover:bg-red-50 disabled:opacity-30"
                      title="Remove activity"
                    >
                      <FiTrash2 size={14} />
                    </button>
                  </div>
                </div>
                {error && <p className="text-xs text-red-500 mt-1.5">{error}</p>}
              </li>
            )
          })}
        </ol>
      )}
    </div>
  )
}
