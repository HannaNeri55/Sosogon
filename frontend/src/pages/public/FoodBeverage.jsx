// frontend/src/pages/public/FoodBeverage.jsx
import usePageTitle from '../../hooks/usePageTitle'
import { useEffect, useState } from 'react'
import { FiSearch } from 'react-icons/fi'
import { getFoodBeverages } from '../../api/foodBeverages'
import ListingCard from '../../components/cards/ListingCard'
import HeroSlideshow from '../../components/common/HeroSlideshow'
import ListingLayout from '../../components/listing/ListingLayout'
import FilterChips from '../../components/listing/FilterChips'
import useUrlFilters from '../../hooks/useUrlFilters'
import useListingScroll from '../../hooks/useListingScroll'

const PRICE_RANGES = [
  { value: 'budget', label: 'Budget' },
  { value: 'moderate', label: 'Moderate' },
  { value: 'luxury', label: 'Luxury' },
]

export default function FoodBeverage() {
  usePageTitle('Food & Beverage')
  const [items, setItems] = useState([])
  const [pagination, setPagination] = useState({ current_page: 1, last_page: 1, total: 0 })
  const [loading, setLoading] = useState(true)
  // A failed load shows an error with Try again (attempt re-runs it), not "nothing found".
  const [loadFailed, setLoadFailed] = useState(false)
  const [attempt, setAttempt] = useState(0)
  const [filters, setFilters] = useUrlFilters({
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
      setLoadFailed(false)
      try {
        const data = await getFoodBeverages(filters)
        if (ignore) return
        setItems(data.data || [])
        setPagination({ current_page: data.current_page, last_page: data.last_page, total: data.total })
      } catch {
        if (!ignore) setLoadFailed(true)
      } finally {
        if (!ignore) setLoading(false)
      }
    }

    loadItems()
    return () => {
      ignore = true
    }
  }, [filters, attempt])

  useListingScroll(!loading)

  const updateFilters = (patch) => setFilters((prev) => ({ ...prev, ...patch, page: 1 }))

  return (
    <div className="min-h-screen bg-gray-50">
      <HeroSlideshow />

      <ListingLayout
        eyebrow="Taste"
        title="Food & Beverage"
        intro="Local delicacies, fresh seafood, cafés and family restaurants from around the province."
        filters={filters}
        updateFilters={updateFilters}
        hasActiveFilters={[filters.q, filters.cuisine_type, filters.price_range, filters.municipality_id].some(Boolean)}
        onClearAll={() => setFilters((prev) => ({ sort: prev.sort, dir: prev.dir }))}
        extraFilters={
          <div className="flex flex-col sm:flex-row gap-4 sm:items-start">
            <label className="flex-1 block">
              <span className="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Cuisine</span>
              <span className="relative block">
                <FiSearch className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={16} aria-hidden="true" />
                <input
                  className="input-field pl-9"
                  placeholder="e.g. Seafood, Cafe, Filipino"
                  value={filters.cuisine_type}
                  onChange={(e) => updateFilters({ cuisine_type: e.target.value })}
                />
              </span>
            </label>
            <div className="flex-1">
              <FilterChips
                label="Price Range"
                allLabel="Any"
                options={PRICE_RANGES}
                value={filters.price_range}
                onChange={(price_range) => updateFilters({ price_range })}
              />
            </div>
          </div>
        }
        loading={loading}
        loadFailed={loadFailed}
        onRetry={() => setAttempt((n) => n + 1)}
        items={items}
        noun={{ one: 'place', many: 'places' }}
        pagination={pagination}
        onPageChange={(page) => setFilters((prev) => ({ ...prev, page }))}
        renderItem={(item) => <ListingCard key={item.id} item={item} basePath="/food-beverage" typeLabel={item.cuisine_type || 'Restaurant'} />}
      />
    </div>
  )
}