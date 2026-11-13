import { useEffect, useMemo, useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { getItinerary, deleteItinerary, updateItinerary } from '../../api/itineraries'
import ItineraryDayCard from '../../components/itinerary/ItineraryDayCard'
import PlacePickerModal from '../../components/itinerary/PlacePickerModal'
import ItineraryActions from '../../components/itinerary/ItineraryActions'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import BackButton from '../../components/layout/BackButton'
import toast from 'react-hot-toast'
import {
  FiCalendar,
  FiActivity,
  FiNavigation,
  FiHome,
  FiTrash2,
  FiEdit3,
  FiSave,
  FiX,
} from 'react-icons/fi'
import { TRAVEL_PACES, BUDGETS, INTERESTS } from '../../utils/constants'
import { formatTripCost, formatPeso, formatPesoRange } from '../../utils/helpers'
import { slotError, overlapProblem, firstFreeSlot } from '../../utils/timeSlots'
import { dishOptions, souvenirOptions, roomOptions, picksTotal, itineraryStays, stayNights, stayRooms, roomsSummary } from '../../utils/selections'
import { costNoteIsEstimate, stayCostIsEstimate, stayCostUnknown } from '../../utils/estimates'
import { CONTACT_FOR_RATES } from '../../utils/accommodationOptions'
import StayCard from '../../components/itinerary/StayCard'
import TripReminders from '../../components/itinerary/TripReminders'
import { transportGuide } from '../../utils/transportGuidance'
import { refreshOfflineCopy, removeOfflineCopy } from '../../utils/offlineItineraries'
import { showApiError } from '../../utils/apiErrors'
import { confirmDialog } from '../../utils/dialogs'
import { focusFirstInvalid } from '../../utils/formErrors'

// The itinerary_items column holding the listing for each kind of stop.
const LISTING_COLUMNS = {
  destination: 'destination_id',
  accommodation: 'accommodation_id',
  food_beverage: 'food_beverage_id',
  retail_shop: 'retail_shop_id',
}

function labelFor(list, value) {
  const match = list.find((item) => item.value === value)
  if (match) return match.label
  return value ? value.charAt(0).toUpperCase() + value.slice(1) : ''
}

// stays: { [stay id, or 'main' for an older itinerary's single stay]: { accommodation?, rooms? } }
function emptyPendingEdits() {
  return { editItems: {}, deleteItemIds: new Set(), newItems: [], stays: {} }
}

const stayKey = (stay) => stay.id ?? 'main'

// What the trip costs by kind of expense, from each stop's cost and the stays.
// Each row has its lowest (min) and highest (value) amount; they differ for a typical range.
function budgetBreakdown(itinerary) {
  const stopsOf = (type) => (itinerary.items || []).filter((i) => i.item_type === type)
  const sumOf = (type, field = 'cost_max') => stopsOf(type).reduce((sum, i) => sum + (Number(i[field]) || 0), 0)
  const row = (label, detail, type) => ({ label, detail, min: sumOf(type, 'cost_min'), value: sumOf(type) })
  const planned = itineraryStays(itinerary).filter((stay) => stayNights(stay) > 0)
  const nights = planned.reduce((sum, stay) => sum + stayNights(stay), 0)
  const unknown = planned.filter(stayCostUnknown)
  const rooms = itinerary.rooms || []
  // Shops with souvenirs picked, and shops costed from typical souvenir spending instead.
  const shops = stopsOf('retail_shop')
  const picked = shops.filter((s) => s.souvenirs?.length > 0).length
  const souvenirDetail = picked === shops.length
    ? 'Recommended souvenirs per shop'
    : picked === 0
      ? 'Typical souvenir spending, for the whole group (estimate)'
      : 'Recommended souvenirs per shop, typical spending (estimate) where none are picked'
  return [
    {
      label: planned.length > 1 ? 'Stays' : 'Stay',
      detail: rooms.length
        ? `${planned.length > 1 ? `${planned.length} stays · ` : ''}${roomsSummary(rooms, Number(itinerary.travelers) || 1)} · ${nights} night${nights > 1 ? 's' : ''}`
        : itineraryStays(itinerary).length === 0
          ? 'Not needed — no stay suggested'
          : unknown.length > 0
            ? CONTACT_FOR_RATES
            : 'No nights planned',
      min: Number(itinerary.accommodation_cost_min) || 0,
      value: Number(itinerary.accommodation_cost_max) || 0,
      unknown: unknown.length > 0,
    },
    row('Entrance fees', 'All destinations, for the whole group', 'destination'),
    row('Meals', 'Recommended dishes for every meal, for the whole group', 'food_beverage'),
    row('Souvenirs', souvenirDetail, 'retail_shop'),
    // SOSOGON lists no fares or fuel prices, so transport is never guessed.
    {
      label: 'Transport',
      detail: itinerary.transport_preference === 'public_transport'
        ? 'Jeepney, van and tricycle fares, paid per person: confirm locally'
        : 'Fuel, rental or fares are not listed on SOSOGON: confirm locally',
      min: 0,
      value: 0,
      note: 'Not estimated',
    },
  ]
}

// What can't be saved in a stop's pending time range or activities, or null.
function editProblem({ time_slot, activities }, timeRequired) {
  if ((timeRequired || time_slot) && time_slot !== undefined && slotError(time_slot)) return slotError(time_slot)
  const badActivity = activities?.find((a) => !a.name.trim() || slotError(a.time))
  if (badActivity) return `Activity "${badActivity.name || 'unnamed'}": ${badActivity.name.trim() ? slotError(badActivity.time) : 'needs a name'}`
  return null
}

// Whether any part of the total is an estimate: a stop priced from a typical range or with an
// estimated menu price, or a stay priced from its price level.
function includesEstimates(itinerary) {
  return (itinerary.items || []).some((i) => Number(i.cost_max) > 0 && costNoteIsEstimate(i.cost_note))
    || itineraryStays(itinerary).some((stay) => stayNights(stay) > 0 && stayCostIsEstimate(stay, stayRooms(stay, itinerary)))
}

function BudgetCard({ itinerary }) {
  if (itinerary.estimated_cost_max == null) return null
  const rows = budgetBreakdown(itinerary)
  const estimated = includesEstimates(itinerary)
  const unknownStays = itineraryStays(itinerary).filter(stayCostUnknown)
  const budget = BUDGETS.find((b) => b.value === itinerary.budget)
  const travelers = Number(itinerary.travelers) || 1

  return (
    <div className="bg-white rounded-xl p-4 sm:p-6 border border-gray-100 mb-6">
      <div className="flex flex-wrap items-start justify-between gap-3 mb-4">
        <p className="flex items-center gap-1.5 text-primary-600 font-medium">
          Trip Budget
        </p>
        {budget && (
          <span className="text-xs font-medium text-gray-600 bg-gray-100 px-2.5 py-1 rounded-full">
            {budget.label} preference{budget.range && ` · ${budget.range}`}
          </span>
        )}
      </div>
      <dl className="divide-y divide-gray-100">
        {rows.map((row) => (
          <div key={row.label} className="flex items-center justify-between gap-4 py-2.5">
            <dt className="min-w-0">
              <p className="text-sm font-medium text-gray-800">{row.label}</p>
              <p className="text-xs text-gray-500 truncate">{row.detail}</p>
            </dt>
            <dd className="text-sm font-semibold text-gray-800 shrink-0">{row.note ?? (row.unknown && !row.value ? 'Not listed' : formatPesoRange(row.min, row.value))}</dd>
          </div>
        ))}
        <div className="flex items-center justify-between gap-4 pt-3">
          <dt className="text-sm font-semibold text-gray-900">
            {unknownStays.length > 0 ? 'Known costs' : estimated ? 'Est. total' : 'Total'} <span className="font-normal text-gray-500">for {travelers} traveler{travelers > 1 ? 's' : ''}{estimated && ', includes estimated prices'}</span>
          </dt>
          <dd className="text-lg font-heading font-bold text-primary-600">
            {formatPesoRange(itinerary.estimated_cost_min, itinerary.estimated_cost_max)}
          </dd>
        </div>
        {travelers > 1 && Number(itinerary.estimated_cost_max) > 0 && (
          <p className="text-right text-xs text-gray-500 pt-1">
            About {formatPesoRange(Number(itinerary.estimated_cost_min) / travelers, Number(itinerary.estimated_cost_max) / travelers)} per person, rooms shared
          </p>
        )}
      </dl>
      {unknownStays.length > 0 && (
        <p className="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">
          Not included, rates not listed: {unknownStays.map((stay) => stay.accommodation.name).join(', ')}. {CONTACT_FOR_RATES}
        </p>
      )}
      <p className="mt-2 text-xs text-gray-500">Transport is not included. Room costs are per room for the nights shown; entrance fees, meals and souvenirs are for the whole group. Listed room rates are indicative; SOSOGON takes no bookings, so confirm rates and availability with each property.</p>
    </div>
  )
}

export default function ItineraryView() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [itinerary, setItinerary] = useState(null)
  const [loading, setLoading] = useState(true)
  const [editMode, setEditMode] = useState(false)
  const [pendingEdits, setPendingEdits] = useState(emptyPendingEdits())
  // Why the last Save was refused, kept with the edits it was about: any change hides it.
  const [saveProblem, setSaveProblem] = useState(null)
  const shownSaveProblem = saveProblem?.edits === pendingEdits ? saveProblem.message : null
  const [saving, setSaving] = useState(false)
  // null, or what the place picker is for: { kind: 'add', dayNumber } | { kind: 'swap', itemId, itemType, currentName } | { kind: 'stay' }
  const [picker, setPicker] = useState(null)

  useEffect(() => {
    getItinerary(id)
      .then(setItinerary)
      .catch(() => setItinerary(null))
      .finally(() => setLoading(false))
  }, [id])

  // Every destination, food place and shop the itinerary will have after saving; none may repeat.
  const usedPlaces = useMemo(() => {
    const used = new Set()
    itinerary?.items?.forEach((item) => {
      if (item.item_type === 'accommodation' || pendingEdits.deleteItemIds.has(item.id)) return
      used.add(`${item.item_type}:${pendingEdits.editItems[item.id]?.listing_id ?? item[LISTING_COLUMNS[item.item_type]]}`)
    })
    pendingEdits.newItems.forEach((item) => {
      if (item.item_type !== 'accommodation') used.add(`${item.item_type}:${item.listing_id}`)
    })
    return used
  }, [itinerary, pendingEdits])

  const stays = itinerary ? itineraryStays(itinerary) : []
  const pendingStayEdits = Object.values(pendingEdits.stays)
  const changeCount =
    Object.keys(pendingEdits.editItems).length +
    pendingEdits.deleteItemIds.size +
    pendingEdits.newItems.length +
    pendingStayEdits.filter((edit) => edit.accommodation).length +
    pendingStayEdits.filter((edit) => edit.rooms && !edit.accommodation).length

  // The saved total adjusted for pending dish, souvenir, room and removal edits; new places
  // (swaps, added stops, a new stay) are priced by the server when saving.
  const liveEstimate = useMemo(() => {
    if (!itinerary) return 0
    const itemsById = new Map((itinerary.items || []).map((i) => [i.id, i]))
    let total = Number(itinerary.estimated_cost_max) || 0
    pendingEdits.deleteItemIds.forEach((id) => {
      total -= Number(itemsById.get(id)?.cost_max) || 0
    })
    Object.entries(pendingEdits.editItems).forEach(([itemId, fields]) => {
      const stop = itemsById.get(Number(itemId))
      if (!stop || pendingEdits.deleteItemIds.has(stop.id) || fields.listing_id) return
      if (fields.dishes) total += picksTotal(fields.dishes, dishOptions(stop)) - (Number(stop.cost_max) || 0)
      if (fields.souvenirs) total += picksTotal(fields.souvenirs, souvenirOptions(stop)) - (Number(stop.cost_max) || 0)
    })
    itineraryStays(itinerary).forEach((stay) => {
      const edit = pendingEdits.stays[stayKey(stay)]
      if (edit?.rooms && !edit.accommodation) {
        total += picksTotal(edit.rooms, roomOptions(stay, itinerary)) * stayNights(stay) - (Number(stay.cost_max) || 0)
      }
    })
    return total
  }, [itinerary, pendingEdits])
  const pricedOnSave =
    Object.values(pendingEdits.editItems).some((f) => f.listing_id) ||
    pendingEdits.newItems.length > 0 ||
    pendingStayEdits.some((edit) => edit.accommodation)

  const editStay = (key, fields) =>
    setPendingEdits((prev) => ({ ...prev, stays: { ...prev.stays, [key]: { ...prev.stays[key], ...fields } } }))

  const dayHasVisit = (dayNumber) => (itinerary?.items || []).some((i) => i.day_number === dayNumber && i.destination)

  // The town a day is spent in: where its last visit is, else where that night is spent.
  const dayTown = (dayNumber) => {
    const visits = (itinerary?.items || []).filter((i) => i.day_number === dayNumber && i.destination)
    const lastVisit = visits.sort((a, b) => a.sequence - b.sequence).at(-1)
    return (
      lastVisit?.destination.municipality_id ??
      stays.find((stay) => stay.night_days?.includes(dayNumber))?.municipality_id ??
      itinerary?.municipalities?.[0]?.id ??
      null
    )
  }

  // A day's stops as they'll be after saving: { name, type, slot }, with pending times and new stops.
  const dayStops = (dayNumber, edits = pendingEdits) => [
    ...(itinerary?.items || [])
      .filter((item) => item.day_number === dayNumber && !edits.deleteItemIds.has(item.id))
      .map((item) => {
        const listing = item.destination || item.food_beverage || item.retail_shop || item.accommodation
        return {
          name: edits.editItems[item.id]?.listing?.name || listing?.name || 'A stop',
          type: item.item_type,
          slot: edits.editItems[item.id]?.time_slot ?? item.time_slot,
        }
      }),
    ...edits.newItems
      .filter((item) => item.day_number === dayNumber)
      .map((item) => ({ name: item.preview?.name || 'A new stop', type: item.item_type, slot: item.time_slot })),
  ]

  const handleDelete = async () => {
    if (!(await confirmDialog({ title: 'Delete this itinerary?', message: 'It will be removed from your saved itineraries. This cannot be undone.', confirmLabel: 'Delete', danger: true }))) return
    try {
      await deleteItinerary(id)
      removeOfflineCopy(id).catch(() => {})
      toast.success('Itinerary deleted')
      navigate('/my-itineraries')
    } catch (error) {
      showApiError(error, 'Failed to delete itinerary')
    }
  }

  const toggleEditMode = () => {
    if (editMode) setPendingEdits(emptyPendingEdits())
    setEditMode((v) => !v)
  }

  const handleFieldChange = (itemId, field, value, isNew = false) => {
    if (isNew) {
      setPendingEdits((prev) => ({
        ...prev,
        newItems: prev.newItems.map((item) => (item.tempId === itemId ? { ...item, [field]: value } : item)),
      }))
      return
    }
    setPendingEdits((prev) => ({
      ...prev,
      editItems: { ...prev.editItems, [itemId]: { ...prev.editItems[itemId], [field]: value } },
    }))
  }

  const handleDeleteStop = (itemId, isNew = false) => {
    if (isNew) {
      setPendingEdits((prev) => ({
        ...prev,
        newItems: prev.newItems.filter((item) => item.tempId !== itemId),
      }))
      return
    }
    setPendingEdits((prev) => {
      const next = new Set(prev.deleteItemIds)
      next.has(itemId) ? next.delete(itemId) : next.add(itemId)
      return { ...prev, deleteItemIds: next }
    })
  }

  const handleSwapStop = (stop) => {
    const listing = stop.destination || stop.food_beverage || stop.retail_shop || stop.accommodation
    setPicker({
      kind: 'swap',
      itemId: stop.id,
      itemType: stop.item_type,
      currentName: listing?.name,
      municipalityId: listing?.municipality_id ?? dayTown(stop.day_number),
      // A day is spent in one municipality, so a replacement comes from the same town.
      lockTown: true,
    })
  }

  const handlePick = (place, itemType) => {
    const preview = { name: place.name, image_path: place.image_path, subtitle: place.subtitle }

    if (picker.kind === 'stay') {
      editStay(picker.stayKey, { accommodation: { id: place.id, ...preview } })
    } else if (picker.kind === 'swap') {
      setPendingEdits((prev) => ({
        ...prev,
        editItems: { ...prev.editItems, [picker.itemId]: { ...prev.editItems[picker.itemId], listing_id: place.id, listing: preview } },
      }))
    } else {
      const dayNumber = picker.dayNumber
      setPendingEdits((prev) => {
        const stopsInDay = (itinerary.items || []).filter((i) => i.day_number === dayNumber).length
        const newInDay = prev.newItems.filter((i) => i.day_number === dayNumber).length
        return {
          ...prev,
          newItems: [
            ...prev.newItems,
            {
              tempId: `new-${Date.now()}-${itemType}-${place.id}`,
              day_number: dayNumber,
              item_type: itemType,
              listing_id: place.id,
              preview,
              // Destinations are placed by time: start from the day's first free 2 hours, if any.
              time_slot: itemType === 'destination' ? firstFreeSlot(dayStops(dayNumber, prev)) : '',
              notes: '',
              activities: [],
              sequence: stopsInDay + newInDay + 1,
            },
          ],
        }
      })
    }
    setPicker(null)
  }

  const handleSaveChanges = async () => {
    const itemsById = new Map((itinerary.items || []).map((i) => [i.id, i]))
    const named = (problem, name) => problem && `${name}: ${problem}`
    // Days whose stops move in time; a new destination always needs a start and end time.
    const touchedDays = new Set(pendingEdits.newItems.map((item) => item.day_number))
    Object.entries(pendingEdits.editItems).forEach(([itemId, fields]) => {
      if (fields.time_slot !== undefined || fields.listing_id) touchedDays.add(itemsById.get(Number(itemId))?.day_number)
    })
    const problem =
      Object.entries(pendingEdits.editItems)
        .filter(([itemId]) => !pendingEdits.deleteItemIds.has(Number(itemId)))
        .map(([, fields]) => editProblem(fields, fields.time_slot !== undefined))
        .find(Boolean) ||
      pendingEdits.newItems
        .map((item) => named(editProblem(item, item.item_type === 'destination'), item.preview?.name || 'New stop'))
        .find(Boolean) ||
      [...touchedDays].map((dayNumber) => overlapProblem(dayStops(dayNumber), dayNumber)).find(Boolean)
    if (problem) {
      setSaveProblem({ message: problem, edits: pendingEdits })
      focusFirstInvalid()
      return
    }

    setSaving(true)
    try {
      const payload = {
        edit_items: Object.entries(pendingEdits.editItems).map(([itemId, { listing, ...fields }]) => ({
          id: Number(itemId),
          ...fields,
        })),
        delete_item_ids: Array.from(pendingEdits.deleteItemIds),
        new_items: pendingEdits.newItems.map(({ tempId, preview, ...rest }) => rest),
      }
      Object.entries(pendingEdits.stays).forEach(([key, edit]) => {
        const fields = {
          ...(edit.accommodation && { accommodation_id: edit.accommodation.id }),
          ...(edit.rooms && !edit.accommodation && { room_ids: edit.rooms.map((r) => r.id) }),
        }
        // An older itinerary's single stay is edited as the itinerary's accommodation.
        if (key === 'main') Object.assign(payload, fields)
        else payload.stays = [...(payload.stays || []), { id: Number(key), ...fields }]
      })
      const res = await updateItinerary(id, payload)
      setItinerary(res.itinerary)
      refreshOfflineCopy(res.itinerary).catch(() => {})
      setPendingEdits(emptyPendingEdits())
      setEditMode(false)
      toast.success('Itinerary updated')
    } catch (e) {
      showApiError(e, 'Failed to save changes')
    } finally {
      setSaving(false)
    }
  }

  if (loading) return <LoadingSpinner size="lg" />
  if (!itinerary) return <div className="p-6 sm:p-8 text-center text-gray-500">Itinerary not found</div>

  const grouped = {}
  itinerary.items?.forEach((item) => {
    if (!grouped[item.day_number]) grouped[item.day_number] = { stops: [] }
    grouped[item.day_number].stops.push(item)
  })
  const dayNumbers = Object.keys(grouped)
    .map(Number)
    .concat(pendingEdits.newItems.map((i) => i.day_number))
    .filter((v, i, arr) => arr.indexOf(v) === i)
    .sort((a, b) => a - b)
  dayNumbers.forEach((n) => {
    if (!grouped[n]) grouped[n] = { stops: [] }
  })


  const stats = [
    { icon: <FiCalendar size={18} />, label: `${itinerary.num_days} Day${itinerary.num_days > 1 ? 's' : ''}` },
    { icon: <FiActivity size={18} />, label: `${labelFor(TRAVEL_PACES, itinerary.travel_pace)} pace` },
    { label: formatTripCost(itinerary, labelFor(BUDGETS, itinerary.budget)) },
    { icon: <FiNavigation size={18} />, label: `${itinerary.total_distance_km} km total` },
  ]

  return (
    <div className={`bg-gray-50 min-h-screen ${editMode ? 'pb-28' : ''}`}>
      <div className="max-w-6xl mx-auto px-4 sm:px-6 pt-6 sm:pt-8 pb-12">
        <BackButton to="/my-itineraries" label="Back to My Itineraries" className="mb-4" />

        <div className="bg-white rounded-xl p-5 sm:p-8 border border-gray-100 mb-8">
          <div className="flex flex-col lg:flex-row lg:items-start justify-between gap-6">
            <div className="min-w-0">
              <p className="text-primary-600 font-medium mb-2">Generated Itinerary</p>
              <h1 className="text-3xl sm:text-4xl font-heading font-bold text-gray-900 mb-3">{itinerary.title}</h1>
              {itinerary.interests?.length > 0 && (
                <div className="flex flex-wrap gap-2">
                  {itinerary.interests.map((interest) => (
                    <span key={interest} className="text-xs font-medium text-accent-600 bg-accent-50 px-2.5 py-1 rounded-full">
                      {labelFor(INTERESTS, interest)}
                    </span>
                  ))}
                </div>
              )}
            </div>

            <div className="flex gap-2 shrink-0">
              {editMode ? (
                <span className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium text-primary-700 bg-primary-50 rounded-lg">
                  <FiEdit3 size={15} /> Editing — change places, times, notes and activities below
                </span>
              ) : (
                <>
                  <button
                    onClick={toggleEditMode}
                    className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium text-primary-600 bg-white border border-primary-200 rounded-lg hover:bg-primary-50 transition-colors"
                  >
                    <FiEdit3 size={15} /> Edit Itinerary
                  </button>
                  <button
                    onClick={handleDelete}
                    className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium text-red-600 bg-white border border-red-200 rounded-lg hover:bg-red-50 transition-colors"
                  >
                    <FiTrash2 size={15} /> Delete
                  </button>
                </>
              )}
            </div>
          </div>

          <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 mt-6 pt-6 border-t border-gray-100">
            {stats.map((stat, i) => (
              <div key={i} className="flex items-center gap-2.5">
                {stat.icon && <span className="text-primary-500 shrink-0">{stat.icon}</span>}
                <span className="text-sm font-medium text-gray-700">{stat.label}</span>
              </div>
            ))}
          </div>

          {!editMode && (
            <ItineraryActions
              itinerary={itinerary}
              onSaved={(savedAt) => setItinerary((current) => ({ ...current, saved_at: savedAt }))}
            />
          )}
        </div>

        {(stays.length > 0 || editMode || itinerary.accommodation_note) && (
          <div className="bg-white rounded-xl p-4 sm:p-6 border border-gray-100 mb-6">
            <p className="flex items-center gap-1.5 text-primary-600 font-medium mb-1">
              <FiHome size={16} /> {stays.length > 1 ? 'Your Stays' : 'Your Stay'}
            </p>
            <p className="text-sm text-gray-500 mb-4">
              {stays.length > 1
                ? 'One stay per town, in the middle of the places you visit there. Each night is spent in the town where that day ends.'
                : stays.length === 1
                  ? "You're free to stay somewhere else — this one just keeps every day's travel short."
                  : 'No stay is suggested in this plan.'}
            </p>
            {stays.length === 0 ? (
              <p className="text-sm text-gray-500">{itinerary.accommodation_note || 'No accommodation is set for this trip yet.'}</p>
            ) : (
              <div className="space-y-4">
                {stays.map((stay) => (
                  <StayCard
                    key={stayKey(stay)}
                    stay={stay}
                    itinerary={itinerary}
                    pending={pendingEdits.stays[stayKey(stay)]}
                    editMode={editMode}
                    onChangeStay={() =>
                      setPicker({
                        kind: 'stay',
                        stayKey: stayKey(stay),
                        currentStayId: pendingEdits.stays[stayKey(stay)]?.accommodation?.id ?? stay.accommodation_id,
                        municipalityId: stay.municipality_id ?? null,
                        lockTown: Boolean(stay.municipality_id),
                      })
                    }
                    onRoomsChange={(rooms) => editStay(stayKey(stay), { rooms })}
                  />
                ))}
              </div>
            )}
          </div>
        )}
        <BudgetCard itinerary={itinerary} />

        {/* How directions between stops work for the way the tourist gets around. */}
        <div className="bg-white rounded-xl p-4 sm:p-5 border border-gray-100 mb-6">
          <p className="flex items-center gap-1.5 text-primary-600 font-medium">
            <FiNavigation size={16} /> Getting around · {transportGuide(itinerary.transport_preference).label}
          </p>
          <p className="text-sm text-gray-600 mt-1.5 leading-relaxed">{transportGuide(itinerary.transport_preference).overview}</p>
        </div>

        <TripReminders itinerary={itinerary} />

        <div className="space-y-6">
          {dayNumbers.map((dayNumber) => (
            <ItineraryDayCard
              key={dayNumber}
              day={grouped[dayNumber]}
              dayNumber={dayNumber}
              startDate={itinerary.start_date}
              editable={editMode}
              pendingEdits={pendingEdits}
              onFieldChange={handleFieldChange}
              onDeleteStop={handleDeleteStop}
              onAddStop={(dayNumber) =>
                setPicker({ kind: 'add', dayNumber, municipalityId: dayTown(dayNumber), lockTown: dayHasVisit(dayNumber) })
              }
              onSwapStop={handleSwapStop}
              overnight={stays.find((stay) => stay.night_days?.includes(dayNumber))}
              transportPreference={itinerary.transport_preference}
              backup={itinerary.backup_options?.[dayNumber]}
            />
          ))}
        </div>
      </div>

      {editMode && (
        <div className="fixed inset-x-0 bottom-0 z-40 bg-white/95 backdrop-blur border-t border-gray-200 shadow-[0_-4px_16px_rgba(0,0,0,0.06)]">
          <div className="max-w-6xl mx-auto px-4 sm:px-6 py-3 flex items-center justify-between gap-3">
            <div className="min-w-0">
              {/* Phones: just the change count, so the buttons keep their room. */}
              <p className="hidden sm:block text-sm font-semibold text-gray-900">Editing itinerary</p>
              <p className="text-sm sm:text-xs text-gray-600 sm:text-gray-500 leading-tight">
                {changeCount === 0 ? 'No changes yet' : `${changeCount} unsaved change${changeCount !== 1 ? 's' : ''}`}
              </p>
              {shownSaveProblem && <p className="field-error mt-1 max-w-md" role="alert">{shownSaveProblem}</p>}
            </div>
            <div className="hidden sm:block text-right ml-auto mr-2">
              <p className="text-xs text-gray-500">Estimated budget</p>
              <p className="text-base font-heading font-bold text-primary-600">
                {formatPeso(liveEstimate)}
                {pricedOnSave && <span className="ml-1 text-xs font-normal text-gray-500">+ new places priced on save</span>}
              </p>
            </div>
            <div className="flex gap-2 shrink-0">
              <button
                onClick={toggleEditMode}
                disabled={saving}
                className="inline-flex items-center gap-1.5 px-3 sm:px-4 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors"
              >
                <FiX size={15} /> Discard
              </button>
              <button
                onClick={handleSaveChanges}
                disabled={saving || changeCount === 0}
                className="inline-flex items-center gap-1.5 px-3 sm:px-4 py-2 text-sm font-medium text-white bg-primary-600 rounded-lg hover:bg-primary-700 transition-colors disabled:opacity-50"
              >
                <FiSave size={15} /> {saving ? 'Saving...' : 'Save changes'}
              </button>
            </div>
          </div>
        </div>
      )}

      {picker && (
        <PlacePickerModal
          itineraryId={id}
          mode={picker}
          usedPlaces={usedPlaces}
          stayId={picker.currentStayId}
          onClose={() => setPicker(null)}
          onPick={handlePick}
        />
      )}
    </div>
  )
}
