import { useEffect, useState } from 'react'
import { FiFilter } from 'react-icons/fi'
import { getDestinations } from '../../api/destinations'
import { getCategories } from '../../api/admin'
import DestinationCard from '../../components/cards/DestinationCard'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import Pagination from '../../components/common/Pagination'
import HeroSlideshow from '../../components/common/HeroSlideshow'
import LocationFilterSidebar from '../../components/common/LocationFilterSidebar'

export default function ExploreDestinations() {
  const [destinations, setDestinations] = useState([])
  const [pagination, setPagination] = useState({ current_page: 1, last_page: 1, total: 0 })
  const [categories, setCategories] = useState([])
  const [loading, setLoading] = useState(true)
  const [showFilters, setShowFilters] = useState(false)
  const [filters, setFilters] = useState({
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

  const updateFilters = (patch) => setFilters((prev) => ({ ...prev, ...patch, page: 1 }))

  const resultCount = pagination.total ?? destinations.length

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
            {/* Category filter - kept from the existing filter bar; the
                reference site's screenshots don't show one, but it's a
                working feature so it stays, just relocated. */}
            {/* Phones: one swipeable row instead of many wrapped rows. */}
            <div className="flex items-center gap-2 mb-6 overflow-x-auto -mx-4 px-4 pb-1 sm:flex-wrap sm:overflow-visible sm:mx-0 sm:px-0 sm:pb-0">
              <button
                type="button"
                onClick={() => updateFilters({ category_id: '' })}
                className={`shrink-0 whitespace-nowrap px-3.5 py-2 sm:py-1.5 rounded-full text-sm font-medium border transition-colors ${
                  !filters.category_id
                    ? 'bg-primary-500 text-white border-primary-500'
                    : 'bg-white text-gray-600 border-gray-200 hover:border-primary-300'
                }`}
              >
                All Categories
              </button>
              {categories.map((c) => (
                <button
                  key={c.id}
                  type="button"
                  onClick={() => updateFilters({ category_id: c.id })}
                  className={`shrink-0 whitespace-nowrap px-3.5 py-2 sm:py-1.5 rounded-full text-sm font-medium border transition-colors ${
                    String(filters.category_id) === String(c.id)
                      ? 'bg-primary-500 text-white border-primary-500'
                      : 'bg-white text-gray-600 border-gray-200 hover:border-primary-300'
                  }`}
                >
                  {c.name}
                </button>
              ))}
            </div>

            {loading && destinations.length === 0 ? (
              <LoadingSpinner size="lg" />
            ) : destinations.length === 0 ? (
              <div className="text-center py-20 bg-white rounded-xl border border-gray-100">
                <p className="text-gray-500">No destinations found for these filters.</p>
              </div>
            ) : (
              <>
                <p className="text-sm text-gray-500 mb-4">
                  {resultCount} destination{resultCount === 1 ? '' : 's'} found
                </p>
                <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-6">
                  {destinations.map((destination) => (
                    <DestinationCard key={destination.id} destination={destination} />
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