import { Link } from 'react-router-dom'
import { FiDollarSign, FiMail, FiMapPin, FiMoon, FiPhone, FiRepeat } from 'react-icons/fi'
import SelectionEditor from './SelectionEditor'
import { PreferenceNote } from './ItineraryDayCard'
import { getImageUrl, formatPeso, formatPesoRange } from '../../utils/helpers'
import { roomLabel, roomOptions, picksFrom, stayRooms, stayNights, roomsSummary } from '../../utils/selections'
import { stayCostIsEstimate, stayCostUnknown } from '../../utils/estimates'
import { accommodationTypeLabel, CONTACT_FOR_RATES, optionName } from '../../utils/accommodationOptions'

// "Night after Day 1", "Nights after Days 1 and 2", "Nights after Days 1, 2 and 3"
function nightsLabel(days) {
  if (!days?.length) return 'No night planned here'
  if (days.length === 1) return `Night after Day ${days[0]}`
  return `Nights after Days ${days.slice(0, -1).join(', ')} and ${days[days.length - 1]}`
}

/**
 * One town's suggested stay: the accommodation, how to contact it, which nights are spent there,
 * why it was picked, its options and known cost. SOSOGON books nothing: rates are confirmed with
 * the property. In edit mode the stay can be replaced (within its town) and its options picked;
 * `pending` holds those unsaved edits ({ accommodation, rooms }).
 */
export default function StayCard({ stay, itinerary, pending = {}, editMode, onChangeStay, onRoomsChange }) {
  const accommodation = stay.accommodation
  const town = stay.municipality?.name || accommodation?.municipality?.name
  const nights = stayNights(stay)
  const rooms = stayRooms(stay, itinerary)
  const cost = formatPesoRange(stay.cost_min, stay.cost_max)
  // Saved earlier from a typical range for its price level; editing the trip replaces it.
  const costEstimated = stayCostIsEstimate(stay, rooms)
  const costUnknown = stayCostUnknown(stay)
  const travelers = Number(itinerary.travelers) || 1
  const changed = Boolean(pending.accommodation)

  return (
    <div className={`rounded-xl border p-4 sm:p-5 ${changed ? 'border-primary-300 ring-1 ring-primary-100' : 'border-gray-100'} bg-white`}>
      <div className="flex flex-wrap items-center justify-between gap-2 mb-4">
        <div className="flex flex-wrap items-center gap-2">
          {town && (
            <span className="inline-flex items-center gap-1 text-xs font-semibold text-primary-700 bg-primary-50 px-2.5 py-1 rounded-full">
              <FiMapPin size={11} /> {town}
            </span>
          )}
          <span className="inline-flex items-center gap-1 text-xs font-medium text-gray-600 bg-gray-100 px-2.5 py-1 rounded-full">
            <FiMoon size={11} /> {nightsLabel(stay.night_days)}
          </span>
        </div>
        {editMode && (
          <button
            type="button"
            onClick={onChangeStay}
            className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-primary-600 bg-white border border-primary-200 rounded-lg hover:bg-primary-50 transition-colors"
          >
            <FiRepeat size={13} /> {accommodation ? 'Change stay' : 'Choose a stay'}
          </button>
        )}
      </div>

      {changed ? (
        <div className="flex gap-4 items-center">
          <div className="w-28 h-20 rounded-lg overflow-hidden shrink-0 bg-gray-100">
            <img src={getImageUrl(pending.accommodation.image_path)} alt={pending.accommodation.name} className="w-full h-full object-cover" />
          </div>
          <div className="min-w-0">
            <span className="text-[11px] font-semibold text-primary-700 bg-primary-50 px-2 py-0.5 rounded-full">New stay</span>
            <p className="text-lg font-heading font-semibold text-gray-900 mt-1">{pending.accommodation.name}</p>
            {pending.accommodation.subtitle && <p className="text-xs text-gray-500">{pending.accommodation.subtitle}</p>}
            {accommodation && <p className="text-xs text-gray-500">Replaces {accommodation.name}</p>}
          </div>
        </div>
      ) : accommodation ? (
        <div className="flex flex-col sm:flex-row gap-4">
          <div className="w-full h-40 sm:w-40 sm:h-28 rounded-lg overflow-hidden shrink-0 bg-gray-100">
            <img src={getImageUrl(accommodation.images?.[0]?.image_path)} alt={accommodation.name} className="w-full h-full object-cover" loading="lazy" />
          </div>
          <div className="min-w-0">
            <Link
              to={`/accommodations/${accommodation.id}`}
              className="text-lg font-heading font-semibold text-gray-900 hover:text-primary-600 transition-colors"
            >
              {accommodation.name}
            </Link>
            <div className="flex flex-wrap items-center gap-2 mt-1.5">
              {accommodation.type && <span className="text-xs font-medium text-gray-600 bg-gray-100 px-2 py-0.5 rounded">{accommodationTypeLabel(accommodation)}</span>}
              {accommodation.price_range && (
                <span className="text-xs font-medium text-gray-600 bg-gray-100 px-2 py-0.5 rounded capitalize">{accommodation.price_range}</span>
              )}
            </div>
            {accommodation.address && <p className="text-sm text-gray-500 mt-2">{accommodation.address}</p>}
            {(accommodation.contact_number || accommodation.email) && (
              <p className="flex flex-wrap gap-x-4 gap-y-1 text-sm text-gray-600 mt-1">
                {accommodation.contact_number && <span className="inline-flex items-center gap-1.5"><FiPhone size={12} aria-hidden="true" /> {accommodation.contact_number}</span>}
                {accommodation.email && <span className="inline-flex items-center gap-1.5 wrap-anywhere"><FiMail size={12} aria-hidden="true" /> {accommodation.email}</span>}
              </p>
            )}
            <p className="text-sm text-gray-600 mt-2">
              {stay.reason || "Your base in this town, in the middle of the places you'll visit there."}
            </p>
            <PreferenceNote note={stay.note} />
          </div>
        </div>
      ) : (
        <p className="text-sm text-gray-500">No verified accommodation for this town yet.</p>
      )}

      {accommodation && nights > 0 && (
        <div className="mt-5 pt-5 border-t border-gray-100">
          {changed ? (
            <p className="text-xs text-gray-500 rounded-lg border border-dashed border-gray-200 px-3 py-2.5">
              Options at {pending.accommodation.name} will be suggested for your group and budget when you save; you can adjust them afterwards.
            </p>
          ) : editMode ? (
            <>
              <SelectionEditor
                title={`Options for ${itinerary.travelers || 1} traveler${Number(itinerary.travelers) > 1 ? 's' : ''}`}
                options={roomOptions(stay, itinerary)}
                picks={pending.rooms ?? picksFrom(rooms)}
                onChange={onRoomsChange}
                showQuantity={false}
                unit="per night"
                addLabel="Add option"
                emptyText={`No option picked. ${CONTACT_FOR_RATES}`}
              />
              <p className="text-xs text-gray-500 mt-2">
                Costed for {nights} night{nights > 1 ? 's' : ''} where a rate is listed. Confirm capacity and rates with the property.
              </p>
            </>
          ) : (
            <>
              <p className="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">Suggested options</p>
              {rooms.length > 0 ? (
                <ul className="grid grid-cols-1 sm:grid-cols-3 gap-2">
                  {rooms.map((room) => (
                    <li key={room.id} className="flex items-center gap-2 rounded-lg border border-gray-100 bg-gray-50 p-2">
                      <img
                        src={getImageUrl(room.images?.[0]?.thumbnail_path || room.images?.[0]?.image_path)}
                        alt={optionName(room)}
                        className="w-10 h-10 rounded-md object-cover shrink-0 bg-gray-200"
                        loading="lazy"
                      />
                      <div className="min-w-0">
                        <p className="text-sm font-medium text-gray-800 truncate">{roomLabel(room)}</p>
                        <p className="text-xs text-gray-500">{Number(room.price) > 0 ? `${formatPeso(room.price)} / night (indicative)` : 'Rate not listed'}</p>
                      </div>
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="text-sm text-gray-500">No option picked.</p>
              )}
              {costUnknown ? (
                <p className="flex items-center gap-1.5 text-sm font-medium text-gray-800 mt-3">
                  <FiDollarSign size={13} className="text-primary-500" aria-hidden="true" /> {CONTACT_FOR_RATES}
                  <span className="text-xs font-normal text-gray-500">· Not included in the known costs</span>
                </p>
              ) : cost && (
                <p className="flex items-center gap-1.5 text-sm font-medium text-gray-800 mt-3">
                  <FiDollarSign size={13} className="text-primary-500" /> {costEstimated && 'Est. '}{cost}
                  <span className="text-xs font-normal text-gray-500">
                    · {rooms.length ? `${roomsSummary(rooms, travelers)}, ` : ''}
                    {nights} night{nights > 1 ? 's' : ''}
                    {costEstimated ? ' · saved earlier from a typical rate; confirm with the property' : ' · indicative; confirm with the property'}
                  </span>
                </p>
              )}
            </>
          )}
        </div>
      )}
    </div>
  )
}
