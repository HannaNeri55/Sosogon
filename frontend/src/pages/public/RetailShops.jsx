import usePageTitle from '../../hooks/usePageTitle'
import { useEffect, useState } from 'react'
import { getRetailShops } from '../../api/retailShops'
import ListingCard from '../../components/cards/ListingCard'
import ListingLayout from '../../components/listing/ListingLayout'
import useUrlFilters from '../../hooks/useUrlFilters'
import useListingScroll from '../../hooks/useListingScroll'

export default function RetailShops() {
  usePageTitle('Shops')
  const [items, setItems] = useState([])
  const [pagination, setPagination] = useState({ current_page: 1, last_page: 1, total: 0 })
  const [loading, setLoading] = useState(true)
  // A failed load shows an error with Try again (attempt re-runs it), not "nothing found".
  const [loadFailed, setLoadFailed] = useState(false)
  const [attempt, setAttempt] = useState(0)
  const [filters, setFilters] = useUrlFilters({
    q: '',
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
        const data = await getRetailShops(filters)
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
        eyebrow="Shop local"
        title="Pasalubong Shops"
        intro="Pili treats, abaca crafts and other local products to bring home from Sorsogon."
        filters={filters}
        updateFilters={updateFilters}
        hasActiveFilters={[filters.q, filters.municipality_id].some(Boolean)}
        onClearAll={() => setFilters((prev) => ({ sort: prev.sort, dir: prev.dir }))}
        loading={loading}
        loadFailed={loadFailed}
        onRetry={() => setAttempt((n) => n + 1)}
        items={items}
        noun={{ one: 'shop', many: 'shops' }}
        pagination={pagination}
        onPageChange={(page) => setFilters((prev) => ({ ...prev, page }))}
        renderItem={(item) => <ListingCard key={item.id} item={item} basePath="/shops" typeLabel="Pasalubong Shop" />}
      />
    </div>
  )
}
