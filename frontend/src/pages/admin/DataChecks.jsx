import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { FiCheckCircle, FiEdit2, FiExternalLink, FiSearch, FiUser } from 'react-icons/fi'
import { getAdminDataChecks } from '../../api/admin'
import Pagination from '../../components/common/Pagination'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import ManagedBy from '../../components/admin/ManagedBy'
import { Badge } from '../../components/admin/reviews/ReviewContent'

const SEVERITY_STYLE = {
  high: 'border-red-200 bg-red-50 text-red-700',
  medium: 'border-amber-200 bg-amber-50 text-amber-800',
  low: 'border-gray-200 bg-gray-100 text-gray-700',
}
const PUBLIC_PATH = { destination: 'destinations', accommodation: 'accommodations', food_beverage: 'food-beverage', retail_shop: 'shops' }
const PAGE_SIZE = 15

/**
 * Data Checks: problems in public listings that make trips worse or mislead tourists (no opening
 * hours the trip planner can read, activities without a length, typos in names, placeholder
 * contacts…), grouped by listing, most serious first. Destinations open in Manage Destinations;
 * businesses are fixed by their owners. A municipal admin sees their own town's listings.
 */
export default function DataChecks() {
  const [data, setData] = useState(null)
  const [error, setError] = useState(false)
  const [severity, setSeverity] = useState('high')
  const [type, setType] = useState('')
  const [town, setTown] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)

  const load = () => {
    setError(false)
    getAdminDataChecks().then(setData).catch(() => setError(true))
  }
  // eslint-disable-next-line react-hooks/set-state-in-effect
  useEffect(load, [])

  const issues = data?.issues || []
  const towns = [...new Set(issues.map((i) => i.municipality).filter(Boolean))].sort()
  const matching = issues.filter((i) => (!type || i.type === type) && (!town || i.municipality === town)
    && (!search.trim() || i.name.toLowerCase().includes(search.trim().toLowerCase())))
  const countOf = (key) => (key ? matching.filter((i) => i.severity === key).length : matching.length)

  // One card per listing, with its problems at the chosen level (all levels for "All").
  const groups = new Map()
  matching.filter((i) => !severity || i.severity === severity).forEach((issue) => {
    const key = `${issue.type}-${issue.id}`
    if (!groups.has(key)) groups.set(key, { ...issue, problems: [] })
    groups.get(key).problems.push(issue)
  })
  const listings = [...groups.values()]
  const lastPage = Math.max(1, Math.ceil(listings.length / PAGE_SIZE))
  const currentPage = Math.min(page, lastPage)
  const pageItems = listings.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE)

  const tabs = [{ key: '', label: 'All' }, ...Object.entries(data?.severities || {}).map(([key, label]) => ({ key, label }))]
  const filter = (setter) => (e) => { setter(e.target.value); setPage(1) }

  return (
    <div>
      <div className="mb-6">
        <p className="text-primary-600 font-medium mb-2">Admin</p>
        <h1 className="text-2xl sm:text-3xl font-heading font-bold text-gray-900">Data Checks</h1>
        <p className="text-gray-600 mt-2">
          Missing or wrong information in published listings. Fixing these makes trips better and helps tourists trust what they read.
          Destinations are fixed here by the Tourism Office; businesses are fixed by their owners.
        </p>
      </div>

      <div className="mb-4 flex flex-wrap gap-2" role="tablist" aria-label="How urgent">
        {tabs.map(({ key, label }) => {
          const active = severity === key
          return (
            <button
              key={key || 'all'}
              type="button"
              role="tab"
              aria-selected={active}
              onClick={() => { setSeverity(key); setPage(1) }}
              className={`rounded-full border px-4 py-1.5 text-sm font-medium transition-colors ${active ? 'border-primary-600 bg-primary-600 text-white' : 'border-gray-200 bg-white text-gray-700 hover:border-primary-300'}`}
            >
              {label} <span className={active ? 'text-primary-100' : 'text-gray-400'}>({countOf(key)})</span>
            </button>
          )
        })}
      </div>

      <div className="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-[minmax(0,12rem)_minmax(0,12rem)_minmax(0,1fr)]">
        <label className="sr-only" htmlFor="checks-type">Listing type</label>
        <select id="checks-type" value={type} onChange={filter(setType)} className="input-field">
          <option value="">All listing types</option>
          {Object.entries(data?.types || {}).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
        </select>
        <label className="sr-only" htmlFor="checks-town">Municipality</label>
        <select id="checks-town" value={town} onChange={filter(setTown)} className="input-field">
          <option value="">All municipalities</option>
          {towns.map((name) => <option key={name} value={name}>{name}</option>)}
        </select>
        <div className="relative">
          <FiSearch className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={16} aria-hidden="true" />
          <label className="sr-only" htmlFor="checks-search">Search by name</label>
          <input id="checks-search" type="search" value={search} onChange={filter(setSearch)} maxLength={100} className="input-field pl-9" placeholder="Search by listing name" />
        </div>
      </div>

      {!data && !error ? (
        <LoadingSpinner size="lg" />
      ) : error ? (
        <div className="bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) p-8 text-center">
          <p className="text-gray-600 mb-4">We couldn&apos;t load the data checks.</p>
          <button type="button" onClick={load} className="btn-secondary">Try again</button>
        </div>
      ) : listings.length === 0 ? (
        <div className="bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) p-8 text-center">
          <FiCheckCircle className="mx-auto mb-3 text-green-600" size={32} aria-hidden="true" />
          <p className="text-gray-700 font-medium">Nothing to fix here.</p>
          <p className="text-gray-500 text-sm mt-1">No listing matches these filters with a problem at this level.</p>
        </div>
      ) : (
        <>
          <ul className="space-y-4">
            {pageItems.map((listing) => (
              <li key={`${listing.type}-${listing.id}`} className="bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) p-4 sm:p-5">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div className="min-w-0">
                    <h2 className="font-semibold text-gray-900 break-words">{listing.name}</h2>
                    <p className="text-sm text-gray-500">{data.types[listing.type]}{listing.municipality ? ` · ${listing.municipality}` : ''}</p>
                  </div>
                  <ListingAction listing={listing} />
                </div>
                <ul className="mt-3 space-y-2">
                  {listing.problems.map((problem) => (
                    <li key={problem.code} className="flex flex-col gap-1 sm:flex-row sm:items-start sm:gap-3">
                      <Badge className={`${SEVERITY_STYLE[problem.severity]} w-fit shrink-0`}>{data.severities[problem.severity]}</Badge>
                      <p className="text-sm text-gray-700">{problem.message}</p>
                    </li>
                  ))}
                </ul>
              </li>
            ))}
          </ul>
          <Pagination currentPage={currentPage} lastPage={lastPage} onPageChange={setPage} />
        </>
      )}
    </div>
  )
}

/** Where to fix a listing: its edit form for an admin who manages it, else who does. */
function ListingAction({ listing }) {
  const publicPage = (
    <a href={`/${PUBLIC_PATH[listing.type]}/${listing.id}`} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-sm text-primary-700 hover:underline">
      <FiExternalLink size={14} aria-hidden="true" /> View page
    </a>
  )
  if (listing.type === 'destination' && listing.can_fix) {
    return (
      <div className="flex flex-wrap items-center gap-3">
        {publicPage}
        <Link to={`/admin/destinations?municipality=${listing.municipality_id}&edit=${listing.id}`} className="btn-secondary inline-flex items-center gap-1.5 text-sm">
          <FiEdit2 size={14} aria-hidden="true" /> Fix it
        </Link>
      </div>
    )
  }

  return (
    <div className="flex flex-col items-start gap-1 sm:items-end">
      {publicPage}
      {listing.type === 'destination'
        ? <ManagedBy office={listing.managed_by} />
        : <p className="flex items-center gap-1.5 text-xs text-gray-500"><FiUser size={12} aria-hidden="true" /> The owner updates this listing</p>}
    </div>
  )
}
