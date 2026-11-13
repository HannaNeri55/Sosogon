import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { FiSearch, FiShoppingBag } from 'react-icons/fi'
import { searchAll } from '../../api/destinations'
import ListingCard from '../../components/cards/ListingCard'
import EmptyState from '../../components/common/EmptyState'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import PageContainer from '../../components/layout/PageContainer'
import PageHeader from '../../components/layout/PageHeader'
import usePageTitle from '../../hooks/usePageTitle'
import { accommodationTypeLabel } from '../../utils/accommodationOptions'

// Result groups in the order shown, each linking to its own detail pages.
const SECTIONS = [
  { key: 'destinations', title: 'Destinations', basePath: '/destinations', label: (item) => item.category?.name },
  { key: 'accommodations', title: 'Accommodations', basePath: '/accommodations', label: accommodationTypeLabel },
  { key: 'food_beverages', title: 'Food & Beverage', basePath: '/food-beverage', label: (item) => item.cuisine_type || 'Restaurant' },
  { key: 'retail_shops', title: 'Shops', basePath: '/shops', label: () => 'Shop' },
]

/** Site-wide search from the navbar: every kind of listing matching ?q=, grouped by kind. */
export default function SearchResults() {
  const [searchParams] = useSearchParams()
  const query = (searchParams.get('q') || '').trim()
  // The results and the query they answer, so a new query shows the spinner, not old results.
  const [found, setFound] = useState({ query: null, results: null, failed: false })

  usePageTitle(query ? `Search: ${query}` : 'Search')

  useEffect(() => {
    if (query.length < 2) return undefined

    let active = true
    searchAll(query)
      .then((data) => { if (active) setFound({ query, results: data.results ?? {}, failed: false }) })
      .catch(() => { if (active) setFound({ query, results: {}, failed: true }) })

    return () => { active = false }
  }, [query])

  const loading = query.length >= 2 && found.query !== query
  const results = found.query === query ? found.results : null
  const products = results?.retail_products ?? []
  const total = results ? SECTIONS.reduce((sum, s) => sum + (results[s.key]?.length ?? 0), 0) + products.length : 0

  return (
    <div className="bg-gray-50 min-h-screen pb-16">
      <PageContainer>
        <PageHeader
          title={query ? `Results for “${query}”` : 'Search'}
          subtitle={results && !found.failed ? `${total} ${total === 1 ? 'result' : 'results'}` : null}
        />

        {query.length < 2 ? (
          <EmptyState icon={FiSearch} title="Search Sorsogon">
            Type at least 2 letters in the search box to find destinations, places to stay, food and shops.
          </EmptyState>
        ) : loading ? (
          <LoadingSpinner />
        ) : found.failed ? (
          <EmptyState icon={FiSearch} title="Search is unavailable right now">
            Please try again in a moment.
          </EmptyState>
        ) : total === 0 ? (
          <EmptyState icon={FiSearch} title="No matches">
            Nothing matched “{query}”. Try a shorter or different word, such as a town or a place name.
          </EmptyState>
        ) : (
          <div className="space-y-10">
            {SECTIONS.map(({ key, title, basePath, label }) => {
              const items = results[key] ?? []
              if (!items.length) return null
              return (
                <section key={key}>
                  <h2 className="mb-4 font-heading text-xl font-semibold text-gray-900">
                    {title} <span className="text-sm font-normal text-gray-500">({items.length})</span>
                  </h2>
                  <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    {items.map((item) => (
                      <ListingCard key={item.id} item={item} basePath={basePath} typeLabel={label(item)} />
                    ))}
                  </div>
                </section>
              )
            })}

            {products.length > 0 && (
              <section>
                <h2 className="mb-4 font-heading text-xl font-semibold text-gray-900">
                  Products <span className="text-sm font-normal text-gray-500">({products.length})</span>
                </h2>
                <ul className="divide-y divide-gray-100 rounded-xl border border-gray-100 bg-white">
                  {products.map((product) => (
                    <li key={product.id}>
                      <Link
                        to={product.retail_shop_id ? `/shops/${product.retail_shop_id}` : '/shops'}
                        className="flex items-center gap-3 px-4 py-3 hover:bg-gray-50"
                      >
                        <FiShoppingBag className="shrink-0 text-primary-600" aria-hidden="true" />
                        <span className="min-w-0 flex-1">
                          <span className="block font-medium text-gray-900">{product.name}</span>
                          <span className="block text-xs text-gray-500">
                            {[product.product_type, product.municipality?.name].filter(Boolean).join(' · ')}
                          </span>
                        </span>
                        <span className="text-sm text-primary-700">View shop</span>
                      </Link>
                    </li>
                  ))}
                </ul>
              </section>
            )}
          </div>
        )}
      </PageContainer>
    </div>
  )
}
