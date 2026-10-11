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
          className="focus:outline-none"
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