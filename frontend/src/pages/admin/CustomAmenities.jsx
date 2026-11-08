import { useCallback, useEffect, useState } from 'react'
import toast from 'react-hot-toast'
import { getCustomAmenities, promoteAmenity } from '../../api/admin'
import LoadingSpinner from '../../components/common/LoadingSpinner'

const SCOPES = [
  { value: 'property', label: 'Property' },
  { value: 'unit', label: 'Room or unit' },
  { value: 'both', label: 'Both' },
]

/**
 * Amenities owners typed themselves. Promoting one only adds it to the checkboxes on the owner
 * forms: no listing changes, and owners keep whatever wording they saved.
 */
export default function CustomAmenities() {
  const [entries, setEntries] = useState([])
  const [loading, setLoading] = useState(true)
  const [failed, setFailed] = useState(false)
  const [scopes, setScopes] = useState({})
  const [acting, setActing] = useState(null)

  const load = useCallback(() => {
    return getCustomAmenities()
      .then((data) => {
        setEntries(data)
        setFailed(false)
      })
      .catch(() => setFailed(true))
      .finally(() => setLoading(false))
  }, [])

  useEffect(() => {
    load()
  }, [load])

  const promote = async (entry) => {
    setActing(entry.name)
    try {
      await promoteAmenity(entry.name, scopes[entry.name] || (entry.property_uses && !entry.unit_uses ? 'property' : entry.unit_uses && !entry.property_uses ? 'unit' : 'both'))
      toast.success(`"${entry.name}" added to the suggestions.`)
      await load()
    } catch (err) {
      toast.error(err.response?.data?.message || 'Could not add this suggestion.')
    } finally {
      setActing(null)
    }
  }

  if (loading) return <LoadingSpinner size="lg" />

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl sm:text-3xl font-heading font-bold text-gray-900">Custom Amenities</h1>
        <p className="text-sm text-gray-500 mt-1">
          Entries owners typed that aren't among the suggested amenities. Adding one to the suggestions offers it to every owner; no listing changes.
        </p>
      </div>

      {failed ? (
        <p role="alert" className="rounded-xl bg-red-50 p-4 text-sm text-red-800">We couldn't load the custom amenities. Please try again.</p>
      ) : entries.length === 0 ? (
        <p className="rounded-xl border border-dashed border-gray-200 bg-white p-8 text-center text-sm text-gray-500">No custom amenities to review.</p>
      ) : (
        <ul className="space-y-3">
          {entries.map((entry) => {
            const scope = scopes[entry.name] || (entry.property_uses && !entry.unit_uses ? 'property' : entry.unit_uses && !entry.property_uses ? 'unit' : 'both')
            return (
              <li key={entry.name} className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gray-200/70 bg-white p-4 shadow-(--shadow-card)">
                <div className="min-w-0">
                  <p className="font-medium text-gray-900 wrap-anywhere">{entry.name}</p>
                  <p className="text-xs text-gray-500">
                    {entry.property_uses} on properties · {entry.unit_uses} on rooms or units · {entry.listings.slice(0, 4).join(', ')}
                    {entry.listings.length > 4 && ` and ${entry.listings.length - 4} more`}
                  </p>
                </div>
                <div className="flex items-center gap-2">
                  <label htmlFor={`scope-${entry.name}`} className="sr-only">Offer it for</label>
                  <select id={`scope-${entry.name}`} className="input-field py-1.5 text-sm" value={scope} onChange={(e) => setScopes({ ...scopes, [entry.name]: e.target.value })}>
                    {SCOPES.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
                  </select>
                  <button type="button" onClick={() => promote(entry)} disabled={Boolean(acting)} className="min-h-10 shrink-0 rounded-lg bg-primary-600 px-3 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50">
                    {acting === entry.name ? 'Adding…' : 'Add to suggestions'}
                  </button>
                </div>
              </li>
            )
          })}
        </ul>
      )}
    </div>
  )
}
