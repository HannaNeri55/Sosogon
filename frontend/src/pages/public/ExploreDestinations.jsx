import usePageTitle from '../../hooks/usePageTitle'
import { useEffect, useState } from 'react'
import { getDestinations } from '../../api/destinations'
import { getCategories } from '../../api/admin'
import DestinationCard from '../../components/cards/DestinationCard'
import ListingLayout from '../../components/listing/ListingLayout'
import FilterChips from '../../components/listing/FilterChips'
import useUrlFilters from '../../hooks/useUrlFilters'
import useListingScroll from '../../hooks/useListingScroll'

export default function ExploreDestinations() {
  usePageTitle('Destinations')
  const [destinations, setDestinations] = useState([])
  const [pagination, setPagination] = useState({ current_page: 1, last_page: 1, total: 0 })
  const [categories, setCategories] = useState([])
  const [loading, setLoading] = useState(true)
  // A failed load shows an error with Try again (attempt re-runs it), not "nothing found".
  const [loadFailed, setLoadFailed] = useState(false)
  const [attempt, setAttempt] = useState(0)
  const [filters, setFilters] = useUrlFilters({
    q: '',
    category_id: '',
    municipality_id: '',
    sort: 'name',
    dir: 'asc',
    page: 1,
  })

  useEffect(() => {
    getCategories('destination').then(setCategories).catch(() => {})
  }, [])

  useEffect(() => {

    let ignore = false

    async function loadDestinations() {
      setLoading(true)
      setLoadFailed(false)
      try {
        const data = await getDestinations(filters)
        if (ignore) return
        setDestinations(data.data || [])
        setPagination({ current_page: data.current_page, last_page: data.last_page, total: data.total })
      } catch {
        if (!ignore) setLoadFailed(true)
      } finally {
        if (!ignore) setLoading(false)
      }
    }

    loadDestinations()
    return () => {
      ignore = true
    }
  }, [filters, attempt])

  useListingScroll(!loading)

  const updateFilters = (patch) => setFilters((prev) => ({ ...prev, ...patch, page: 1 }))

  return (
    <div className="min-h-screen bg-gray-50">
      <ListingLayout
        eyebrow="Explore"
        title="Destinations"
        intro="Beaches, springs, heritage sites and natural wonders across the province of Sorsogon."
        filters={filters}
        updateFilters={updateFilters}
        hasActiveFilters={[filters.q, filters.category_id, filters.municipality_id].some(Boolean)}
        onClearAll={() => setFilters((prev) => ({ sort: prev.sort, dir: prev.dir }))}
        extraFilters={
          categories.length > 0 && (
            <FilterChips
              label="Category"
              allLabel="All Categories"
              options={categories.map((c) => ({ value: c.id, label: c.name }))}
              value={filters.category_id}
              onChange={(category_id) => updateFilters({ category_id })}
            />
          )
        }
        loading={loading}
        loadFailed={loadFailed}
        onRetry={() => setAttempt((n) => n + 1)}
        items={destinations}
        noun={{ one: 'destination', many: 'destinations' }}
        pagination={pagination}
        onPageChange={(page) => setFilters((prev) => ({ ...prev, page }))}
        renderItem={(destination) => <DestinationCard key={destination.id} destination={destination} />}
      />
    </div>
  )
}