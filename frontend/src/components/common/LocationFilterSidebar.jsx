// frontend/src/components/common/LocationFilterSidebar.jsx
import { useEffect, useState } from 'react'
import { FiSearch, FiX } from 'react-icons/fi'
import { getMunicipalities } from '../../api/admin'

const SORT_OPTIONS = [
  { key: 'name', label: 'Name', dir: 'asc' },
  { key: 'created_at', label: 'Newest', dir: 'desc' },
]

export default function LocationFilterSidebar({
  municipalityId,
  onSelectMunicipality,
  onClearLocation,
  search,
  onSearchChange,
  sort,
  onSortChange,
}) {
  const [municipalities, setMunicipalities] = useState([])

  useEffect(() => {
    getMunicipalities().then(setMunicipalities).catch(() => {})
  }, [])

  return (
    <div className="lg:sticky lg:top-24 space-y-7">
      {/* Location search */}
      <div className="flex items-center gap-2">
        <div className="relative flex-1">
          <FiSearch className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={16} />
          <input
            type="text"
            value={search}
            onChange={(e) => onSearchChange(e.target.value)}
            placeholder="Enter a Location"
            className="input-field pl-9"
          />
        </div>
        {search && (
          <button
            type="button"
            onClick={() => onSearchChange('')}
            aria-label="Clear search"
            className="shrink-0 w-10 h-10 flex items-center justify-center rounded-lg bg-red-500 text-white hover:bg-red-600 transition-colors focus-visible:ring-2 focus-visible:ring-red-400"
          >
            <FiX size={18} />
          </button>
        )}
      </div>

      {/* Municipality */}
      <div>
        <div className="flex items-center justify-between mb-4">
          <h3 className="text-xs font-semibold tracking-wider text-gray-500 uppercase">
            Municipality
          </h3>
          {municipalityId && (
            <button
              type="button"
              onClick={onClearLocation}
              className="text-xs text-primary-600 hover:text-primary-700 font-medium"
            >
              Clear
            </button>
          )}
        </div>

        <div className="grid grid-cols-2 gap-2">
          {municipalities.map((m) => {
            const isSelected = String(municipalityId) === String(m.id)
            return (
              <button
                key={m.id}
                type="button"
                onClick={() => onSelectMunicipality(isSelected ? '' : m.id)}
                className={`px-3 py-1.5 rounded-full text-sm font-medium border truncate transition-colors focus-visible:ring-2 focus-visible:ring-primary-500 ${
                  isSelected
                    ? 'bg-primary-500 text-white border-primary-500'
                    : 'bg-white text-gray-600 border-gray-200 hover:border-primary-300'
                }`}
                title={m.name}
              >
                {m.name}
              </button>
            )
          })}
        </div>
      </div>

      {/* Sort By */}
      <div>
        <h3 className="text-xs font-semibold tracking-wider text-gray-500 uppercase mb-4">Sort By</h3>
        <div className="flex gap-2">
          {SORT_OPTIONS.map((opt) => (
            <button
              key={opt.key}
              type="button"
              onClick={() => onSortChange(opt.key, opt.dir)}
              className={`flex-1 px-3 py-2.5 rounded-lg text-sm font-semibold border transition-colors focus-visible:ring-2 focus-visible:ring-primary-500 ${
                sort === opt.key
                  ? 'bg-primary-500 text-white border-primary-500'
                  : 'bg-white text-gray-700 border-gray-200 hover:border-primary-300'
              }`}
            >
              {opt.label}
            </button>
          ))}
        </div>
      </div>
    </div>
  )
}
