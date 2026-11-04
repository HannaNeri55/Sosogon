import { useEffect, useState } from 'react'
import { getDestinations } from '../../api/destinations'
import { getCategories } from '../../api/admin'
import DestinationCard from '../../components/cards/DestinationCard'
import HeroSlideshow from '../../components/common/HeroSlideshow'
import ListingLayout from '../../components/listing/ListingLayout'
import FilterChips from '../../components/listing/FilterChips'
import useUrlFilters from '../../hooks/useUrlFilters'
import useListingScroll from '../../hooks/useListingScroll'

export default function ExploreDestinations() {
  const [destinations, setDestinations] = useState([])
  const [pagination, setPagination] = useState({ current_page: 1, last_page: 1, total: 0 })
  const [categories, setCategories] = useState([])
  const [loading, setLoading] = useState(true)
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
      try {
        const data = await getDestinations(filters)
        if (ignore) return
        setDestinations(data.data || [])
        setPagination({ current_page: data.current_page, last_page: data.last_page, total: data.total })
      } catch (e) {
        if (!ignore) console.error(e)
      } finally {
        if (!ignore) setLoading(false)
      }
    }

    loadDestinations()
    return () => {
      ignore = true
    }
  }, [filters])

  useListingScroll(!loading)

  const updateFilters = (patch) => setFilters((prev) => ({ ...prev, ...patch, page: 1 }))

  return (
    <div className="min-h-screen bg-gray-50">
      <HeroSlideshow />

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
        items={destinations}
        noun={{ one: 'destination', many: 'destinations' }}
        pagination={pagination}
        onPageChange={(page) => setFilters((prev) => ({ ...prev, page }))}
        renderItem={(destination) => <DestinationCard key={destination.id} destination={destination} />}
      />
    </div>
  )
}