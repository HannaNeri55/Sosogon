import { FiCoffee } from 'react-icons/fi'

const MEAL_LABELS = { breakfast: 'Breakfast', lunch: 'Lunch', merienda: 'Merienda', dinner: 'Dinner' }

/** What a listing offers to eat on site, and which meals: shown only when the listing says. */
export default function DiningInfo({ details, meals = [] }) {
  const shown = (meals || []).filter((meal) => MEAL_LABELS[meal])
  return (
    <div>
      {details && <p className="text-gray-700 leading-relaxed">{details}</p>}
      {shown.length > 0 && (
        <div className="mt-4 flex flex-wrap items-center gap-2">
          <span className="inline-flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500">
            <FiCoffee size={13} aria-hidden="true" /> Meals
          </span>
          {shown.map((meal) => (
            <span key={meal} className="rounded-full bg-secondary-50 px-3 py-1 text-sm font-medium text-secondary-700">
              {MEAL_LABELS[meal]}
            </span>
          ))}
        </div>
      )}
    </div>
  )
}
