import { FiStar } from 'react-icons/fi'

export function StarDisplay({ rating, size = 16 }) {
  return (
    <div className="flex items-center gap-0.5">
      {[1, 2, 3, 4, 5].map((star) => (
        <FiStar
          key={star}
          size={size}
          className={star <= Math.round(rating) ? 'fill-accent-500 text-accent-500' : 'text-gray-300'}
        />
      ))}
    </div>
  )
}

export function StarInput({ value, onChange }) {
  return (
    <div className="flex items-center gap-1">
      {[1, 2, 3, 4, 5].map((star) => (
        <button
          key={star}
          type="button"
          onClick={() => onChange(star)}
          aria-label={`${star} star${star > 1 ? 's' : ''}`}
          aria-pressed={star === value}
          className="p-1.5 rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
        >
          <FiStar
            size={24}
            className={`cursor-pointer transition-colors ${
              star <= value ? 'fill-accent-500 text-accent-500' : 'text-gray-300 hover:text-accent-400'
            }`}
          />
        </button>
      ))}
    </div>
  )
}