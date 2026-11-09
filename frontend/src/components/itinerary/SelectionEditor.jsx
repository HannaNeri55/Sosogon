import { useState } from 'react'
import { FiPlus, FiMinus, FiTrash2, FiX } from 'react-icons/fi'
import { getImageUrl, formatPeso } from '../../utils/helpers'
import { picksTotal } from '../../utils/selections'

const MAX_QUANTITY = 50

/**
 * Picks from a list of priced options (dishes, souvenirs or rooms): each pick shows its
 * picture, price and line total, with a quantity stepper unless `showQuantity` is off.
 * options: [{ id, name, price, image, subtitle }]; picks: [{ id, quantity }].
 */
export default function SelectionEditor({ title, options, picks, onChange, showQuantity = true, unit = '', addLabel = 'Add', emptyText }) {
  const [adding, setAdding] = useState(false)
  const byId = new Map(options.map((o) => [o.id, o]))
  const available = options.filter((o) => !picks.some((p) => p.id === o.id))

  const setQuantity = (id, quantity) =>
    onChange(picks.map((p) => (p.id === id ? { ...p, quantity: Math.min(Math.max(quantity, 1), MAX_QUANTITY) } : p)))

  return (
    <div>
      <div className="flex items-center justify-between mb-2">
        <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">{title}</p>
        <span className="text-xs font-semibold text-gray-800">
          {formatPeso(picksTotal(picks, options))}
          {unit && <span className="font-normal text-gray-500"> {unit}</span>}
        </span>
      </div>

      {picks.length === 0 ? (
        <p className="text-xs text-gray-400 border border-dashed border-gray-200 rounded-lg px-3 py-3 text-center bg-white">{emptyText}</p>
      ) : (
        <ul className="space-y-2">
          {picks.map((pick) => {
            const option = byId.get(pick.id)
            if (!option) return null
            return (
              // Phones: name takes the first line, the quantity/price/remove controls wrap below it.
              <li key={pick.id} className="flex flex-wrap sm:flex-nowrap items-center gap-x-3 gap-y-2 rounded-lg border border-gray-200 bg-white p-2">
                <img src={getImageUrl(option.image)} alt={option.name} className="w-11 h-11 rounded-md object-cover shrink-0 bg-gray-100" />
                <div className="min-w-0 flex-1 basis-[calc(100%-3.5rem)] sm:basis-auto">
                  <p className="text-sm font-medium text-gray-900 truncate">{option.name}</p>
                  <p className="text-xs text-gray-500">{option.priceLabel || formatPeso(option.price)}{!option.priceLabel && showQuantity && ' each'}</p>
                </div>
                <div className="flex items-center gap-3 ml-auto">
                  {showQuantity && (
                    <div className="flex items-center rounded-lg border border-gray-200">
                      <button
                        type="button"
                        onClick={() => setQuantity(pick.id, pick.quantity - 1)}
                        disabled={pick.quantity <= 1}
                        className="p-2 sm:p-1.5 text-gray-500 hover:text-gray-800 disabled:opacity-30"
                        aria-label="Fewer"
                      >
                        <FiMinus size={12} />
                      </button>
                      <span className="w-7 text-center text-sm font-medium text-gray-800">{pick.quantity}</span>
                      <button
                        type="button"
                        onClick={() => setQuantity(pick.id, pick.quantity + 1)}
                        disabled={pick.quantity >= MAX_QUANTITY}
                        className="p-2 sm:p-1.5 text-gray-500 hover:text-gray-800 disabled:opacity-30"
                        aria-label="More"
                      >
                        <FiPlus size={12} />
                      </button>
                    </div>
                  )}
                  <span className="w-20 text-right text-sm font-semibold text-gray-800">{option.priceLabel ? 'Not listed' : formatPeso(option.price * pick.quantity)}</span>
                  <button
                    type="button"
                    onClick={() => onChange(picks.filter((p) => p.id !== pick.id))}
                    className="p-2 sm:p-1.5 rounded-md text-red-400 hover:text-red-600 hover:bg-red-50"
                    title="Remove"
                    aria-label="Remove"
                  >
                    <FiTrash2 size={14} />
                  </button>
                </div>
              </li>
            )
          })}
        </ul>
      )}

      <div className="relative mt-2">
        <button
          type="button"
          onClick={() => setAdding((v) => !v)}
          disabled={available.length === 0}
          className="inline-flex items-center gap-1 text-xs font-medium text-primary-600 hover:text-primary-700 disabled:opacity-40"
        >
          {adding ? <FiX size={13} /> : <FiPlus size={13} />} {adding ? 'Close' : available.length === 0 ? 'Nothing else to add' : addLabel}
        </button>
        {adding && available.length > 0 && (
          <ul className="mt-2 max-h-64 overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-sm divide-y divide-gray-100">
            {available.map((option) => (
              <li key={option.id}>
                <button
                  type="button"
                  onClick={() => {
                    onChange([...picks, { id: option.id, quantity: 1 }])
                    setAdding(false)
                  }}
                  className="w-full flex items-center gap-3 p-2 text-left hover:bg-primary-50 transition-colors"
                >
                  <img src={getImageUrl(option.image)} alt={option.name} className="w-10 h-10 rounded-md object-cover shrink-0 bg-gray-100" />
                  <span className="min-w-0 flex-1">
                    <span className="block text-sm font-medium text-gray-900 truncate">{option.name}</span>
                    {option.subtitle && <span className="block text-xs text-gray-500 truncate">{option.subtitle}</span>}
                  </span>
                  <span className="text-sm font-semibold text-gray-800">{option.priceLabel ? 'Not listed' : formatPeso(option.price)}</span>
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  )
}
