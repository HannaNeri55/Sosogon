import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { FiFilter, FiMapPin } from 'react-icons/fi'
import { getRetailProducts } from '../../api/retailProducts'
import { StarDisplay } from '../../components/common/StarRating'
import { getImageUrl, IMAGE_FALLBACK_SVG } from '../../utils/helpers'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import Pagination from '../../components/common/Pagination'
import PageHero from '../../components/common/PageHero'
import LocationFilterSidebar from '../../components/common/LocationFilterSidebar'
import useUrlFilters from '../../hooks/useUrlFilters'
import useListingScroll from '../../hooks/useListingScroll'

const PRODUCT_TYPES = ['Souvenir', 'Local Food Product', 'Handicraft', 'Clothing']

export default function RetailProducts() {
  const [items, setItems] = useState([])
  const [pagination, setPagination] = useState({ current_page: 1, last_page: 1, total: 0 })
  const [loading, setLoading] = useState(true)
  const [showFilters, setShowFilters] = useState(false)
  const [filters, setFilters] = useUrlFilters({
    q: '',
    product_type: '',
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
        const data = await getRetailProducts(filters)
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

  useListingScroll(!loading)


  const updateFilters = (patch) => setFilters((prev) => ({ ...prev, ...patch, page: 1 }))
  const resultCount = pagination.total ?? items.length

  return (
    <div className="min-h-screen bg-gray-50">
      <PageHero
        eyebrow="Shop Local"
        title="Retail Products"
        subtitle="Pili delicacies, abaca handicrafts, and other pasalubong made right here in Sorsogon."
        image="/images/products-hero.jpg"
      />

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
            <div className="flex flex-wrap items-center gap-2 mb-6">
              <button
                type="button"
                onClick={() => updateFilters({ product_type: '' })}
                className={`px-3.5 py-1.5 rounded-full text-sm font-medium border transition-colors ${
                  !filters.product_type
                    ? 'bg-primary-500 text-white border-primary-500'
                    : 'bg-white text-gray-600 border-gray-200 hover:border-primary-300'
                }`}
              >
                All Types
              </button>
              {PRODUCT_TYPES.map((t) => (
                <button
                  key={t}
                  type="button"
                  onClick={() => updateFilters({ product_type: t })}
                  className={`px-3.5 py-1.5 rounded-full text-sm font-medium border transition-colors ${
                    filters.product_type === t
                      ? 'bg-primary-500 text-white border-primary-500'
                      : 'bg-white text-gray-600 border-gray-200 hover:border-primary-300'
                  }`}
                >
                  {t}
                </button>
              ))}
            </div>

            {loading && items.length === 0 ? (
              <LoadingSpinner size="lg" />
            ) : items.length === 0 ? (
              <div className="text-center py-20 bg-white rounded-xl border border-gray-100">
                <p className="text-gray-500">No products found for these filters.</p>
              </div>
            ) : (
              <>
                <p className="text-sm text-gray-500 mb-4">
                  {resultCount} product{resultCount === 1 ? '' : 's'} found
                </p>
                <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-6">
                  {items.map((item) => {
                    const image = item.images?.[0]
                    const location = item.municipality?.name || ''
                    return (
                      <Link key={item.id} to={`/products/${item.id}`} className="card group block">
                        <div className="aspect-4/3 overflow-hidden">
                          <img
                            src={getImageUrl(image, { thumbnail: true })}
                            alt={item.name}
                            className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
                            loading="lazy"
                            onError={(e) => { e.target.onerror = null; e.target.src = IMAGE_FALLBACK_SVG }}
                          />
                        </div>
                        <div className="p-4">
                          <div className="flex items-center justify-between gap-2 mb-2">
                            <span className="text-xs font-medium text-primary-600 bg-primary-50 px-2 py-0.5 rounded">
                              {item.product_type}
                            </span>
                            <StarDisplay rating={item.average_rating} size={14} />
                          </div>
                          <h3 className="font-heading font-semibold text-gray-900 mb-1 group-hover:text-primary-600 transition-colors">
                            {item.name}
                          </h3>
                          {location && (
                            <p className="flex items-center gap-1 text-xs text-gray-400 mb-2">
                              <FiMapPin size={11} className="shrink-0" />
                              {location}
                            </p>
                          )}
                          <p className="text-sm text-gray-500 line-clamp-2">
                            {item.description?.substring(0, 100)}...
                          </p>
                        </div>
                      </Link>
                    )
                  })}
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
