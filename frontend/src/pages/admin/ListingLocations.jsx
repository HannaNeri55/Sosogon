// frontend/src/pages/admin/ListingLocations.jsx
import { useCallback, useEffect, useState } from 'react'
import toast from 'react-hot-toast'
import { FiAlertTriangle, FiCheckCircle, FiExternalLink, FiMapPin, FiSearch } from 'react-icons/fi'
import { getListingLocations, updateListingLocation } from '../../api/admin'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import Pagination from '../../components/common/Pagination'
import Modal from '../../components/common/Modal'
import MapLocationPicker from '../../components/maps/MapLocationPicker'
import { googleMapsPlaceUrl, toCoordinates } from '../../components/maps/mapsConfig'
import { showApiError } from '../../utils/apiErrors'

const TYPES = [
  { value: 'destination', label: 'Destinations', publicPath: '/destinations' },
  { value: 'accommodation', label: 'Accommodations', publicPath: '/accommodations' },
  { value: 'food_beverage', label: 'Food & Beverage', publicPath: '/food-beverage' },
  { value: 'retail_shop', label: 'Shops', publicPath: '/shops' },
]

/** Quick sanity check of saved coordinates (not a verification of the exact spot). */
function locationCheck(item) {
  const point = toCoordinates(item.latitude, item.longitude)
  if (!point) return { tone: 'red', label: 'Missing' }
  if (point.lat < 12.0 || point.lat > 13.4 || point.lng < 123.4 || point.lng > 124.4) return { tone: 'amber', label: 'Outside Sorsogon' }
  return { tone: 'green', label: 'Set' }
}

function EditLocationModal({ type, item, onClose, onSaved }) {
  const [form, setForm] = useState({
    address: item.address || '',
    latitude: item.latitude ?? '',
    longitude: item.longitude ?? '',
  })
  const [busy, setBusy] = useState(false)
  const [errors, setErrors] = useState({})
  const point = toCoordinates(form.latitude, form.longitude)

  const save = async () => {
    if (!point) {
      setErrors({ location: 'Choose the location on the map or enter valid coordinates.' })
      return
    }
    setErrors({})
    setBusy(true)
    try {
      const data = await updateListingLocation(type, item.id, {
        address: form.address.trim() || null,
        latitude: point.lat,
        longitude: point.lng,
      })
      toast.success(data.message)
      onSaved()
    } catch (error) {
      const serverErrors = error.response?.status === 422 && error.response.data?.errors
      if (serverErrors) {
        setErrors({ address: serverErrors.address?.[0], location: (serverErrors.latitude ?? serverErrors.longitude)?.[0] })
      } else {
        showApiError(error, 'The location could not be saved.')
      }
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      size="lg"
      busy={busy}
      onClose={onClose}
      title="Set exact location"
      subtitle={`${item.name}${item.municipality?.name ? ` · ${item.municipality.name}` : ''}`}
      footer={
        <>
          <button type="button" onClick={onClose} disabled={busy} className="btn-secondary">Cancel</button>
          <button type="button" onClick={save} disabled={busy} className="btn-primary">{busy ? 'Saving…' : 'Save location'}</button>
        </>
      }
    >
      <div className="space-y-4">
        <label className="block">
          <span className="block text-sm font-medium text-gray-700 mb-1">Complete address</span>
          <input name="address" className="input-field" maxLength={500} value={form.address} aria-invalid={!!errors.address} onChange={(e) => { setForm((prev) => ({ ...prev, address: e.target.value })); setErrors((prev) => ({ ...prev, address: undefined })) }} />
          {errors.address && <span className="field-error block" role="alert">{errors.address}</span>}
        </label>
        <MapLocationPicker
          required
          latitude={form.latitude}
          longitude={form.longitude}
          searchHint={`${item.name}${item.municipality?.name ? `, ${item.municipality.name}` : ''}`}
          onChange={({ latitude, longitude }) => {
            setForm((prev) => ({ ...prev, latitude, longitude }))
            setErrors((prev) => ({ ...prev, location: undefined }))
          }}
          onAddressFound={(found) => setForm((prev) => (prev.address ? prev : { ...prev, address: found }))}
        />
        {errors.location && <p className="field-error" role="alert">{errors.location}</p>}
      </div>
    </Modal>
  )
}

/**
 * Exact map locations of every destination, accommodation, food place and shop, with a
 * quick check of each saved pin and a map picker to correct it.
 */
export default function ListingLocations() {
  const [type, setType] = useState('accommodation')
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [page, setPage] = useState(1)
  const [result, setResult] = useState(null)
  const [loading, setLoading] = useState(true)
  const [editing, setEditing] = useState(null)

  useEffect(() => {
    const timer = setTimeout(() => { setQuery(search.trim()); setPage(1) }, 350)
    return () => clearTimeout(timer)
  }, [search])

  const load = useCallback(async () => {
    setLoading(true)
    try {
      setResult(await getListingLocations(type, { q: query || undefined, page, per_page: 50 }))
    } finally {
      setLoading(false)
    }
  }, [type, query, page])

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load()
  }, [load])

  const closeModal = useCallback(() => setEditing(null), [])
  const publicPath = TYPES.find((t) => t.value === type)?.publicPath

  return (
    <div>
      <div className="mb-6">
        <p className="text-xs font-semibold uppercase tracking-wider text-secondary-500 mb-2">Content</p>
        <h1 className="text-2xl sm:text-3xl font-heading font-bold text-gray-900">Listing Locations</h1>
        <p className="mt-1 text-sm text-gray-600">
          The exact pin each listing shows on its map and uses for Get Directions. Search the place, adjust the pin, and save.
        </p>
      </div>

      <div className="mb-5 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div role="tablist" className="inline-flex flex-wrap rounded-full bg-white p-1 border border-gray-200 shadow-sm self-start">
          {TYPES.map((t) => (
            <button
              key={t.value}
              type="button"
              role="tab"
              aria-selected={type === t.value}
              onClick={() => { setType(t.value); setPage(1) }}
              className={`px-4 py-1.5 rounded-full text-sm font-semibold transition-colors ${type === t.value ? 'bg-primary-500 text-white' : 'text-gray-600 hover:text-primary-700'}`}
            >
              {t.label}
            </button>
          ))}
        </div>
        <div className="relative w-full lg:max-w-xs">
          <FiSearch className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={16} />
          <input className="input-field pl-9" placeholder="Search by name…" value={search} onChange={(e) => setSearch(e.target.value)} />
        </div>
      </div>

      {loading && !result ? (
        <LoadingSpinner size="lg" />
      ) : (
        <>
          <div className={`bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) overflow-hidden transition-opacity ${loading ? 'opacity-60' : ''}`}>
            <div className="overflow-x-auto">
              <table className="table-cards w-full min-w-[52rem] text-sm">
                <thead className="bg-gray-50/80 border-b border-gray-200">
                  <tr>
                    {['Listing', 'Address', 'Coordinates', 'Check', ''].map((heading, i) => (
                      <th key={i} className={`${i === 4 ? 'text-right' : 'text-left'} px-5 py-3 text-xs font-semibold uppercase tracking-wider text-gray-500`}>{heading}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {result?.data?.length === 0 && (
                    <tr><td colSpan={5} className="px-5 py-10 text-center text-gray-500">No listings found.</td></tr>
                  )}
                  {result?.data?.map((item) => {
                    const check = locationCheck(item)
                    const point = toCoordinates(item.latitude, item.longitude)
                    return (
                      <tr key={item.id} className="border-b border-gray-100 last:border-b-0 align-top hover:bg-primary-50/40">
                        <td className="px-5 py-3">
                          <a href={`${publicPath}/${item.id}`} target="_blank" rel="noopener noreferrer" className="font-medium text-gray-900 hover:text-primary-700">{item.name}</a>
                          <p className="text-xs text-gray-500">{item.municipality?.name || '—'} · {item.status}</p>
                        </td>
                        <td data-label="Address" className="px-5 py-3 text-gray-600 max-w-xs">{item.address || '—'}</td>
                        <td data-label="Coordinates" className="px-5 py-3 whitespace-nowrap text-gray-700 tabular-nums">
                          {point ? (
                            <a href={googleMapsPlaceUrl(point)} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 hover:text-primary-700">
                              {point.lat.toFixed(5)}, {point.lng.toFixed(5)} <FiExternalLink size={12} aria-hidden="true" />
                            </a>
                          ) : '—'}
                        </td>
                        <td data-label="Check" className="px-5 py-3">
                          <span className={`inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold ${
                            check.tone === 'green' ? 'bg-green-50 text-green-700' : check.tone === 'amber' ? 'bg-amber-50 text-amber-800' : 'bg-red-50 text-red-700'
                          }`}>
                            {check.tone === 'green' ? <FiCheckCircle size={12} aria-hidden="true" /> : <FiAlertTriangle size={12} aria-hidden="true" />}
                            {check.label}
                          </span>
                        </td>
                        <td data-actions className="px-5 py-3 text-right">
                          <button type="button" onClick={() => setEditing(item)} className="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 px-3 py-1.5 text-sm font-semibold text-gray-700 hover:border-primary-300 hover:text-primary-700">
                            <FiMapPin size={14} aria-hidden="true" /> Set location
                          </button>
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          </div>
          {result && <Pagination currentPage={result.current_page} lastPage={result.last_page} onPageChange={setPage} />}
        </>
      )}

      {editing && (
        <EditLocationModal type={type} item={editing} onClose={closeModal} onSaved={() => { setEditing(null); load() }} />
      )}
    </div>
  )
}
