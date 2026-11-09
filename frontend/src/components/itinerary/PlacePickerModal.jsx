import { useEffect, useState } from 'react'
import { FiX, FiSearch, FiMapPin, FiHome, FiCoffee, FiShoppingBag, FiCheck, FiNavigation } from 'react-icons/fi'
import { getEligibleListingsForItinerary } from '../../api/itineraries'
import { getImageUrl } from '../../utils/helpers'
import LoadingSpinner from '../common/LoadingSpinner'

// One group per kind of stop; `key` is the eligible-listings response key.
const PLACE_GROUPS = [
  { key: 'destinations', itemType: 'destination', title: 'Destinations', icon: FiMapPin },
  { key: 'accommodations', itemType: 'accommodation', title: 'Accommodations', icon: FiHome },
  { key: 'food_beverages', itemType: 'food_beverage', title: 'Food & Beverage', icon: FiCoffee },
  { key: 'shops', itemType: 'retail_shop', title: 'Souvenir Shops', icon: FiShoppingBag },
]

function PlaceCard({ place, taken, isStay, onPick }) {
  const disabled = taken || isStay
  return (
    <button
      type="button"
      disabled={disabled}
      onClick={onPick}
      className={`group w-full text-left rounded-xl border bg-white overflow-hidden transition-all ${
        disabled
          ? 'border-gray-100 opacity-60 cursor-not-allowed'
          : 'border-gray-200 hover:border-primary-400 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-primary-300'
      }`}
    >
      <div className="relative h-28 bg-gray-100">
        <img src={getImageUrl(place.image_path)} alt={place.name} className="w-full h-full object-cover" loading="lazy" />
        {place.km !== null && (
          <span className="absolute bottom-2 left-2 inline-flex items-center gap-1 text-[11px] font-medium text-white bg-black/55 backdrop-blur-sm px-2 py-0.5 rounded-full">
            <FiNavigation size={10} /> {place.km} km from your stay there
          </span>
        )}
        {disabled && (
          <span className="absolute top-2 right-2 inline-flex items-center gap-1 text-[11px] font-semibold text-gray-700 bg-white/90 px-2 py-0.5 rounded-full">
            <FiCheck size={11} /> {isStay ? 'Your stay' : 'In your itinerary'}
          </span>
        )}
      </div>
      <div className="p-3">
        <p className="text-sm font-semibold text-gray-900 truncate group-hover:text-primary-600">{place.name}</p>
        {place.subtitle && <p className="text-xs text-gray-500 truncate">{place.subtitle}</p>}
        {place.description && <p className="text-xs text-gray-500 mt-1.5 line-clamp-2">{place.description}</p>}
      </div>
    </button>
  )
}

/**
 * Picks a place for the itinerary, as image cards from one of the itinerary's municipalities
 * at a time (a tab per town when there are several).
 * mode: { kind: 'add', dayNumber } shows every kind of place in columns;
 *       { kind: 'swap', itemType, currentName } or { kind: 'stay' } shows one kind.
 *       municipalityId opens that town's tab; lockTown (a stay) keeps the picker on it.
 * usedPlaces: Set of "type:id" already in the itinerary (the stay excluded), shown disabled.
 * stayId: the accommodation being replaced, when changing a stay.
 */
export default function PlacePickerModal({ itineraryId, mode, usedPlaces, stayId, onClose, onPick }) {
  const [listings, setListings] = useState({})
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [townId, setTownId] = useState(mode.municipalityId ?? null)

  useEffect(() => {
    getEligibleListingsForItinerary(itineraryId)
      .then(setListings)
      .catch(() => setListings({}))
      .finally(() => setLoading(false))
  }, [itineraryId])

  const singleType = mode.kind === 'stay' ? 'accommodation' : mode.kind === 'swap' ? mode.itemType : null
  const groups = singleType ? PLACE_GROUPS.filter((g) => g.itemType === singleType) : PLACE_GROUPS
  const query = search.trim().toLowerCase()
  const towns = listings.municipalities || []
  // The town shown: the requested one, else the trip's first.
  const activeTown = towns.find((t) => t.id === townId) || towns[0] || null
  const townName = activeTown?.name || 'this municipality'
  const rowsOf = (group) =>
    (listings[group.key] || []).filter(
      (p) => (!activeTown || p.municipality_id === activeTown.id) && p.name.toLowerCase().includes(query)
    )

  const title =
    mode.kind === 'add' ? `Add a stop to Day ${mode.dayNumber}` : mode.kind === 'stay' ? `Change your stay in ${townName}` : `Replace ${mode.currentName || 'this stop'}`
  const subtitle = `Verified places in ${townName}, nearest your stay there first. Places already in your trip can't be picked twice.`

  const renderCards = (group, rows, gridClass) => (
    <div className={gridClass}>
      {rows.map((place) => (
        <PlaceCard
          key={place.id}
          place={place}
          taken={group.itemType !== 'accommodation' && usedPlaces.has(`${group.itemType}:${place.id}`)}
          isStay={mode.kind === 'stay' && place.id === stayId}
          onPick={() => onPick(place, group.itemType)}
        />
      ))}
    </div>
  )

  return (
    <div className="fixed inset-0 bg-gray-900/50 backdrop-blur-sm flex items-center justify-center p-4 z-50" onClick={onClose}>
      <div
        className={`bg-gray-50 rounded-2xl shadow-2xl w-full ${singleType ? 'max-w-4xl' : 'max-w-7xl'} max-h-[90vh] flex flex-col overflow-hidden`}
        onClick={(e) => e.stopPropagation()}
      >
        <div className="bg-white border-b border-gray-100 px-6 pt-5 pb-4">
          <div className="flex items-start justify-between gap-4">
            <div>
              <h2 className="text-lg font-heading font-semibold text-gray-900">{title}</h2>
              <p className="text-sm text-gray-500 mt-0.5">{subtitle}</p>
            </div>
            <button onClick={onClose} className="-m-1 p-2.5 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100" aria-label="Close">
              <FiX size={20} />
            </button>
          </div>
          {towns.length > 1 && !mode.lockTown && (
            <div className="flex flex-wrap gap-2 mt-4" role="tablist" aria-label="Municipality">
              {towns.map((town) => {
                const active = town.id === activeTown?.id
                return (
                  <button
                    key={town.id}
                    type="button"
                    role="tab"
                    aria-selected={active}
                    onClick={() => setTownId(town.id)}
                    className={`px-3 py-1.5 rounded-full text-xs font-medium border transition-colors ${
                      active ? 'bg-primary-500 text-white border-primary-500' : 'bg-white text-gray-600 border-gray-200 hover:border-primary-300'
                    }`}
                  >
                    {town.name}
                  </button>
                )
              })}
            </div>
          )}
          <div className="relative mt-4">
            <FiSearch className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={16} />
            <input
              type="text"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search places..."
              className="input-field pl-9"
              autoFocus
            />
          </div>
        </div>

        <div className="flex-1 min-h-0 overflow-y-auto p-6">
          {loading ? (
            <LoadingSpinner size="md" />
          ) : singleType ? (
            (() => {
              const group = groups[0]
              const rows = rowsOf(group)
              return rows.length === 0 ? (
                <p className="text-sm text-gray-400 text-center py-12">
                  {query ? 'No matches.' : `No verified ${group.title.toLowerCase()} in ${townName}.`}
                </p>
              ) : (
                renderCards(group, rows, 'grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4')
              )
            })()
          ) : (
            <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-5">
              {groups.map((group) => {
                const Icon = group.icon
                const rows = rowsOf(group)
                return (
                  <section key={group.key} className="min-w-0">
                    <div className="flex items-center justify-between mb-3">
                      <h3 className="flex items-center gap-1.5 text-sm font-semibold text-gray-800">
                        <span className="w-7 h-7 rounded-lg bg-primary-50 text-primary-600 flex items-center justify-center">
                          <Icon size={14} />
                        </span>
                        {group.title}
                      </h3>
                      <span className="text-xs font-medium text-gray-400 bg-white border border-gray-200 px-2 py-0.5 rounded-full">
                        {rows.length}
                      </span>
                    </div>
                    {rows.length === 0 ? (
                      <p className="text-xs text-gray-400 text-center py-8 border border-dashed border-gray-200 rounded-xl bg-white">
                        {query ? 'No matches.' : `None in ${townName}.`}
                      </p>
                    ) : (
                      renderCards(group, rows, 'space-y-3')
                    )}
                  </section>
                )
              })}
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
