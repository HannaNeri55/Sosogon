// frontend/src/pages/public/FoodBeverage.jsx
import { useEffect, useState } from 'react'
import { FiFilter, FiSearch } from 'react-icons/fi'
import { getFoodBeverages } from '../../api/foodBeverages'
import ListingCard from '../../components/cards/ListingCard'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import Pagination from '../../components/common/Pagination'
import HeroSlideshow from '../../components/common/HeroSlideshow'
import LocationFilterSidebar from '../../components/common/LocationFilterSidebar'

const PRICE_RANGES = [
  { value: 'budget', label: 'Budget' },
  { value: 'moderate', label: 'Moderate' },
  { value: 'luxury', label: 'Luxury' },
]

export default function FoodBeverage() {
  const [items, setItems] = useState([])
  const [pagination, setPagination] = useState({ current_page: 1, last_page: 1, total: 0 })
  const [loading, setLoading] = useState(true)
  const [showFilters, setShowFilters] = useState(false)
  const [filters, setFilters] = useState({
    q: '',
    cuisine_type: '',
    price_range: '',
    municipality_id: '',
    sort: 'name',
    dir: 'asc',
    page: 1,
  })

  useEffect(() => {
    let ignore = false

    async function loadItems() {
      setLoading(true)
      try {
        const data = await getFoodBeverages(filters)
        if (ignore) return
        setItems(data.data || [])
        setPagination({ current_page: data.current_page, last_page: data.last_page, total: data.total })
      } finally {
        if (!ignore) setLoading(false)
      }
    }

    loadItems()
    return () => {
      ignore = true
    }
  }, [filters])

  const updateFilters = (patch) => setFilters((prev) => ({ ...prev, ...patch, page: 1 }))
  const resultCount = pagination.total ?? items.length

  return (
    <div className="min-h-screen bg-gray-50">
      <HeroSlideshow />

      <div className="max-w-7xl mx-auto px-4 sm:px-6 py-10 lg:py-12">
        <button
          type="button"
          onClick={() => setShowFilters((v) => !v)}
          className="lg:hidden mb-4 inline-flex items-center gap-2 text-sm font-medium text-primary-600 border border-primary-200 rounded-lg px-4 py-2"
        >
          <FiFilter size={14} /> {showFilters ? 'Hide filters' : 'Filter by location'}
        </button>

        <div className="flex flex-col lg:flex-row gap-8">
          <aside
            className={`lg:w-72 shrink-0 bg-white rounded-xl border border-gray-100 p-5 h-fit ${
              showFilters ? 'block' : 'hidden'
            } lg:block`}
          >
            <LocationFilterSidebar
              municipalityId={filters.municipality_id}
              onSelectMunicipality={(id) => updateFilters({ municipality_id: id })}
              onClearLocation={() => updateFilters({ municipality_id: '' })}
              search={filters.q}
              onSearchChange={(q) => updateFilters({ q })}
              sort={filters.sort}
              onSortChange={(sort, dir) => updateFilters({ sort, dir })}
            />
          </aside>

          <div className="flex-1 min-w-0">
            <div className="bg-white rounded-xl border border-gray-100 p-4 mb-6 flex flex-col sm:flex-row gap-4 sm:items-end">
              <div className="flex-1">
                <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Cuisine</p>
                <div className="relative">
                  <FiSearch className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={16} />
                  <input
                    className="input-field pl-9"
                    placeholder="e.g. Seafood, Cafe, Filipino"
                    value={filters.cuisine_type}
                    onChange={(e) => updateFilters({ cuisine_type: e.target.value })}
                  />
                </div>
              </div>

              <div className="flex-1">
                <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Price Range</p>
                <div className="flex flex-wrap gap-2">
                  <button
                    type="button"
                    onClick={() => updateFilters({ price_range: '' })}
                    className={`px-3.5 py-1.5 rounded-full text-sm font-medium border transition-colors ${
                      !filters.price_range
                        ? 'bg-primary-500 text-white border-primary-500'
                        : 'bg-white text-gray-600 border-gray-200 hover:border-primary-300'
                    }`}
                  >
                    Any
                  </button>
                  {PRICE_RANGES.map((p) => (
                    <button
                      key={p.value}
                      type="button"
                      onClick={() => updateFilters({ price_range: p.value })}
                      className={`px-3.5 py-1.5 rounded-full text-sm font-medium border transition-colors ${
                        filters.price_range === p.value
                          ? 'bg-primary-500 text-white border-primary-500'
                          : 'bg-white text-gray-600 border-gray-200 hover:border-primary-300'
                      }`}
                    >
                      {p.label}
                    </button>
                  ))}
                </div>
              </div>
            </div>

            {loading && items.length === 0 ? (
              <LoadingSpinner size="lg" />
            ) : items.length === 0 ? (
              <div className="text-center py-20 bg-white rounded-xl border border-gray-100">
                <p className="text-gray-500">No food or beverage listings found for these filters.</p>
              </div>
            ) : (
              <>
                <p className="text-sm text-gray-500 mb-4">
                  {resultCount} place{resultCount === 1 ? '' : 's'} found
                </p>
                <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-6">
                  {items.map((item) => (
                    <ListingCard key={item.id} item={item} basePath="/food-beverage" typeLabel={item.cuisine_type || 'Restaurant'} />
                  ))}
                </div>
                <Pagination
                  currentPage={pagination.current_page}
                  lastPage={pagination.last_page}
                  onPageChange={(page) => setFilters((prev) => ({ ...prev, page }))}
                />
              </>
            )}
          </div>
        </div>
      </div>
    </div>
  )
}