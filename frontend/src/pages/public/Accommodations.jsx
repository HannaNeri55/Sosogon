// frontend/src/pages/public/Accommodations.jsx
import usePageTitle from '../../hooks/usePageTitle'
import { useEffect, useState } from 'react'
import { getAccommodations } from '../../api/accommodations'
import ListingCard from '../../components/cards/ListingCard'
import ListingLayout from '../../components/listing/ListingLayout'
import FilterChips from '../../components/listing/FilterChips'
import useUrlFilters from '../../hooks/useUrlFilters'
import useListingScroll from '../../hooks/useListingScroll'
import { accommodationTypeLabel } from '../../utils/accommodationOptions'

const TYPES = ['Hotel', 'Resort', 'Beach Resort', 'Inn', 'Guesthouse', 'Homestay', 'Villa', 'Cottage']
const PRICE_RANGES = [
  { value: 'budget', label: 'Budget' },
  { value: 'moderate', label: 'Moderate' },
  { value: 'luxury', label: 'Luxury' },
]

export default function Accommodations() {
  usePageTitle('Accommodations')
  const [items, setItems] = useState([])
  const [pagination, setPagination] = useState({ current_page: 1, last_page: 1, total: 0 })
  const [loading, setLoading] = useState(true)
  // A failed load shows an error with Try again (attempt re-runs it), not "nothing found".
  const [loadFailed, setLoadFailed] = useState(false)
  const [attempt, setAttempt] = useState(0)
  const [filters, setFilters] = useUrlFilters({
    q: '',
    type: '',
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
        const data = await getAccommodations(filters)
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
      <ListingLayout
        eyebrow="Where to stay"
        title="Accommodations"
        intro="Resorts, hotels, inns and homestays — find a place to stay near the places you want to see."
        filters={filters}
        updateFilters={updateFilters}
        hasActiveFilters={[filters.q, filters.type, filters.price_range, filters.municipality_id].some(Boolean)}
        onClearAll={() => setFilters((prev) => ({ sort: prev.sort, dir: prev.dir }))}
        extraFilters={
          <>
            <FilterChips
              label="Type"
              allLabel="All Types"
              options={TYPES.map((t) => ({ value: t, label: t }))}
              value={filters.type}
              onChange={(type) => updateFilters({ type })}
            />
            <FilterChips
              label="Price Range"
              allLabel="Any Price"
              options={PRICE_RANGES}
              value={filters.price_range}
              onChange={(price_range) => updateFilters({ price_range })}
            />
          </>
        }
        loading={loading}
        loadFailed={loadFailed}
        onRetry={() => setAttempt((n) => n + 1)}
        items={items}
        noun={{ one: 'accommodation', many: 'accommodations' }}
        pagination={pagination}
        onPageChange={(page) => setFilters((prev) => ({ ...prev, page }))}
        renderItem={(item) => <ListingCard key={item.id} item={item} basePath="/accommodations" typeLabel={accommodationTypeLabel(item)} />}
      />
    </div>
  )
}