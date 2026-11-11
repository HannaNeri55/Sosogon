import { Suspense, lazy, useState } from 'react'
import { Link } from 'react-router-dom'
import { placeFor } from '../../utils/itineraryStops'
import { addDays, format, parseISO } from 'date-fns'
import {
  FiClock,
  FiMapPin,
  FiNavigation,
  FiTrash2,
  FiRotateCcw,
  FiPlus,
  FiThumbsUp,
  FiRepeat,
  FiAlertTriangle,
  FiCoffee,
  FiMoon,
  FiExternalLink,
  FiChevronDown,
  FiChevronUp,
  FiX,
} from 'react-icons/fi'
import { StarDisplay } from '../common/StarRating'
import ActivityEditor from './ActivityEditor'
import SelectionEditor from './SelectionEditor'
import { getImageUrl, toDateOnly, formatPeso, formatPesoRange } from '../../utils/helpers'
import { splitSlot, joinSlot, slotError } from '../../utils/timeSlots'
import { dishOptions, souvenirOptions, picksFrom } from '../../utils/selections'
import { legGuidance, stopCoordinates, transportGuide } from '../../utils/transportGuidance'

// The maps pull in Leaflet, so they load only when a tourist opens directions or navigation.
const RouteMap = lazy(() => import('../maps/RouteMap'))
const NavigationView = lazy(() => import('../maps/NavigationView'))

function dayDateLabel(startDate, dayNumber) {
  const dateOnly = toDateOnly(startDate)
  if (!dateOnly) return null
  try {
    return format(addDays(parseISO(dateOnly), Number(dayNumber) - 1), 'MMM d, yyyy')
  } catch {
    return null
  }
}

// Time slots are ranges like "7:30 AM – 11:30 AM" (older itineraries have a single time).
function slotStart(slot) {
  return (slot || '').split(' – ')[0]
}

function slotEnd(slot) {
  const parts = (slot || '').split(' – ')
  return parts[parts.length - 1]
}

// Badges for stops added in edit mode, before they're saved.
const NEW_STOP_BADGES = {
  destination: 'Destination',
  accommodation: 'Stay',
  food_beverage: 'Food stop',
  retail_shop: 'Souvenir shopping',
}

const FIELD = 'text-sm text-gray-800 bg-white border border-gray-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-200 focus:border-primary-400'

// Older itineraries started a visit's notes with the listing's description, which is shown just
// above them; only text identical to the description's opening sentences is left out.
function visitNotes(stop, listing) {
  const notes = stop.notes || ''
  const description = (listing?.description || '').replace(/\s+/g, ' ').trim()
  if (stop.item_type !== 'destination' || !description) return notes
  let repeated = ''
  for (const sentence of description.split(/(?<=[.!?])\s+/)) {
    const next = repeated ? `${repeated} ${sentence}` : sentence
    if (!notes.startsWith(next)) break
    repeated = next
  }
  return repeated ? notes.slice(repeated.length).trim() : notes
}

// The trip from one stop to the next: directions for how the tourist gets around (a driving
// route map shown right here, or Google Maps for public transport), and the saved distance and
// time, labelled as measured or estimated. Without usable map locations, just the saved
// distance and time, as before.
function TravelLeg({ from, to, transportPreference }) {
  const [showRoute, setShowRoute] = useState(false)
  const leg = legGuidance(from, to, transportPreference)
  if (!leg) {
    // No trip between two stops at the same place (saved as 0.00 km and 0 min).
    const km = Number(to?.distance_from_prev_km) || 0
    const minutes = Number(to?.travel_time_from_prev) || 0
    if (samePlace(from, to) || (km <= 0 && minutes <= 0)) return null
    return (
      <div className="flex items-center gap-1.5 text-xs text-gray-400 mt-2 ml-1">
        <FiNavigation size={11} />
        {km > 0 && <span>{to.distance_from_prev_km} km</span>}
        {minutes > 0 && <span>~{minutes} min to next stop</span>}
      </div>
    )
  }
  const fromName = placeFor(from).listing?.name || 'this stop'
  const toName = placeFor(to).listing?.name || 'the next stop'
  const panelId = `route-${from.id ?? fromName}-${to.id ?? toName}`.replace(/\s+/g, '-')
  return (
    <div className="mt-2 ml-1">
      <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500">
        <FiNavigation size={11} className="text-primary-500 shrink-0" aria-hidden="true" />
        {leg.facts.map((fact) => <span key={fact}>{fact}</span>)}
        {leg.inSiteRoute ? (
          <button
            type="button"
            onClick={() => setShowRoute((open) => !open)}
            aria-expanded={showRoute}
            aria-controls={panelId}
            className="inline-flex items-center gap-1 py-1 font-medium text-primary-600 hover:text-primary-700 hover:underline"
          >
            {showRoute ? <>Hide directions <FiChevronUp size={12} aria-hidden="true" /></> : <>{leg.linkLabel} <FiChevronDown size={12} aria-hidden="true" /></>}
          </button>
        ) : (
          <a
            href={leg.url}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-1 py-1 font-medium text-primary-600 hover:text-primary-700 hover:underline"
            aria-label={`${leg.linkLabel} to ${toName} (opens Google Maps)`}
          >
            {leg.linkLabel} <FiExternalLink size={11} aria-hidden="true" />
          </a>
        )}
      </div>

      {leg.inSiteRoute && showRoute && (
        <div id={panelId} className="mt-2 rounded-xl border border-gray-200 bg-white p-3">
          <div className="mb-2 flex items-start justify-between gap-3">
            <p className="text-sm font-medium text-gray-800">
              {fromName} <span className="text-gray-400">→</span> {toName}
            </p>
            <button
              type="button"
              onClick={() => setShowRoute(false)}
              className="shrink-0 rounded-md p-1 text-gray-500 hover:bg-gray-100 hover:text-gray-700"
              aria-label="Close directions"
            >
              <FiX size={16} aria-hidden="true" />
            </button>
          </div>
          <Suspense fallback={<div className="h-80 rounded-xl bg-gray-100" aria-label="Loading map" />}>
            <RouteMap origin={leg.origin} destination={leg.destination} fromName={fromName} toName={toName} />
          </Suspense>
        </div>
      )}
    </div>
  )
}

/** Two stops at the same listing (e.g. arriving at the stay, then lunch there). */
function samePlace(a, b) {
  if (!a || !b || a.item_type !== b.item_type) return false
  const first = placeFor(a).listing
  const second = placeFor(b).listing
  return Boolean(first?.id) && first.id === second?.id
}

// A lunch break is its own food stop, but it happens during the visit listed before it.
function isLunchBreakDuring(stop, host) {
  if (!host || stop.item_type !== 'food_beverage' || !/^Lunch break/.test(stop.notes || '')) return false
  const inner = splitSlot(stop.time_slot)
  const outer = splitSlot(host.time_slot)
  return Boolean(inner.start && outer.start && inner.start >= outer.start && inner.end <= outer.end)
}

// Rows ({ slot, ... }) in time order; a row without a start time stays after the row it followed.
function byTime(rows) {
  let after = ''
  return rows
    .map((row, index) => {
      after = splitSlot(row.slot).start || after
      return { row, key: after, index }
    })
    .sort((a, b) => (a.key < b.key ? -1 : a.key > b.key ? 1 : a.index - b.index))
    .map(({ row }) => row)
}

function groupStops(stops) {
  const rows = []
  stops.forEach((stop) => {
    const host = rows[rows.length - 1]
    if (host && !host.lunch && isLunchBreakDuring(stop, host.stop)) host.lunch = stop
    else rows.push({ stop, lunch: null })
  })
  return rows
}

// Shown when the planner couldn't follow the tourist's form for this stop.
export function PreferenceNote({ note, className = 'mt-3' }) {
  if (!note) return null
  return (
    <div className={`flex gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 ${className}`}>
      <FiAlertTriangle size={15} className="shrink-0 mt-0.5 text-amber-500" />
      <p className="text-sm text-amber-900 leading-relaxed">{note}</p>
    </div>
  )
}

// Dishes at food stops and souvenirs at shops, which the tourist can adjust in edit mode.
const PICK_KINDS = {
  food_beverage: {
    field: 'dishes',
    title: 'Dishes',
    options: dishOptions,
    addLabel: 'Add dish',
    emptyText: 'No dishes picked — this meal adds nothing to your budget.',
  },
  retail_shop: {
    field: 'souvenirs',
    title: 'Souvenirs',
    options: souvenirOptions,
    addLabel: 'Add souvenir',
    emptyText: 'No souvenirs picked — this stop adds nothing to your budget.',
  },
}

// The recommended (or tourist-picked) dishes or souvenirs at a stop.
function PicksList({ title, options, picks }) {
  if (!picks.length) return null
  const byId = new Map(options.map((o) => [o.id, o]))
  return (
    <div className="mt-3">
      <p className="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1.5">{title}</p>
      <ul className="grid grid-cols-1 sm:grid-cols-3 gap-2">
        {picks.map((pick) => {
          const option = byId.get(pick.id)
          if (!option) return null
          return (
            <li key={pick.id} className="flex items-center gap-2 rounded-lg border border-gray-100 bg-gray-50 p-2">
              <img src={getImageUrl(option.image)} alt={option.name} className="w-10 h-10 rounded-md object-cover shrink-0 bg-gray-200" loading="lazy" />
              <div className="min-w-0">
                <p className="text-sm font-medium text-gray-800 truncate">{option.name}</p>
                <p className="text-xs text-gray-500">
                  {formatPeso(option.price)}
                  {pick.quantity > 1 && ` × ${pick.quantity}`}
                </p>
              </div>
            </li>
          )
        })}
      </ul>
    </div>
  )
}

// repeat: the same place as the stop before (lunch or check-in back at the stay), shown without
// the photo and description it already had. action: a button shown beside the place's name.
function StopDetails({ stop, compact = false, repeat = false, action = null }) {
  const { listing, link, badge } = placeFor(stop)

  return (
    <div className={`flex flex-col sm:flex-row gap-4 ${compact ? '' : 'p-3 sm:p-4 rounded-lg border border-gray-100 hover:border-gray-200 transition-colors'}`}>
      {!repeat && (
        <div className={`${compact ? 'w-full h-28 sm:w-20 sm:h-20' : 'w-full h-32 sm:w-24 sm:h-24'} rounded-lg overflow-hidden shrink-0 bg-gray-100`}>
          <img
            src={getImageUrl(listing?.images?.[0]?.image_path)}
            alt={listing?.name || 'Stop'}
            className="w-full h-full object-cover"
            loading="lazy"
          />
        </div>
      )}

      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-2 mb-1.5">
          <span className="inline-flex items-center gap-1 text-xs font-medium text-primary-600 bg-primary-50 px-2 py-0.5 rounded">
            <FiClock size={11} /> {stop.time_slot}
          </span>
          {badge && <span className="text-xs font-medium text-gray-600 bg-gray-100 px-2 py-0.5 rounded">{badge}</span>}
        </div>

        <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
          {link ? (
            <Link to={link} className="font-heading font-semibold text-gray-900 hover:text-primary-600 transition-colors">
              {listing.name}
            </Link>
          ) : (
            <p className="font-heading font-semibold text-gray-900">{listing?.name || 'Listing no longer available'}</p>
          )}
          {action}
        </div>

        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 mt-1.5">
          {listing?.average_rating > 0 && <StarDisplay rating={listing.average_rating} size={12} />}
          {listing?.municipality?.name && (
            <span className="flex items-center gap-1 text-xs text-gray-500">
              <FiMapPin size={11} /> {listing.municipality.name}
            </span>
          )}
          {stop.duration_minutes > 0 && <span className="text-xs text-gray-500">{stop.duration_minutes} min</span>}
          {stop.cost_note && (
            <span className="flex items-center gap-1 text-xs font-medium text-gray-700">
              {Number(stop.cost_max) > 0 && `${formatPesoRange(stop.cost_min, stop.cost_max)} · `}
              <span className="font-normal text-gray-500">{stop.cost_note}</span>
            </span>
          )}
        </div>

        {listing?.description && !repeat && (
          <p className={`text-sm text-gray-500 mt-2 leading-relaxed ${compact ? 'line-clamp-2' : 'line-clamp-3'}`}>{listing.description}</p>
        )}

        {visitNotes(stop, listing) && <p className="text-sm text-gray-600 mt-2 leading-relaxed">{visitNotes(stop, listing)}</p>}

        {stop.reason && (
          <p className="flex gap-1.5 text-sm text-gray-700 mt-2 leading-relaxed">
            <FiThumbsUp size={13} className="text-primary-500 shrink-0 mt-1" />
            <span>
              <span className="font-medium">Why we recommend it:</span> {stop.reason}
            </span>
          </p>
        )}

        <PreferenceNote note={stop.preference_note} />

        {stop.item_type === 'food_beverage' && (
          <PicksList title="Recommended dishes" options={dishOptions(stop)} picks={picksFrom(stop.dishes)} />
        )}
        {stop.item_type === 'retail_shop' && (
          <PicksList title="Recommended souvenirs" options={souvenirOptions(stop)} picks={picksFrom(stop.souvenirs)} />
        )}

        {stop.activities?.length > 0 && (
          <ol className="mt-3 space-y-2 border-l-2 border-primary-100 pl-3">
            {stop.activities.map((activity, i) => (
              <li key={`${i}-${activity.name}`} className="text-sm">
                <span className="inline-flex items-center gap-1 text-xs font-medium text-primary-600">
                  <FiClock size={10} /> {activity.time}
                </span>
                <p className="font-medium text-gray-800">{activity.name}</p>
                {activity.description && <p className="text-xs text-gray-500 leading-relaxed">{activity.description}</p>}
              </li>
            ))}
          </ol>
        )}
      </div>
    </div>
  )
}

function TimeRangeFields({ slot, onChange, invalid = false }) {
  const { start, end } = splitSlot(slot)
  return (
    <>
      <label className="block">
        <span className="block text-xs font-medium text-gray-500 mb-1">Start</span>
        <input type="time" value={start} onChange={(e) => onChange(joinSlot(e.target.value, end))} className={FIELD} aria-invalid={invalid || undefined} />
      </label>
      <label className="block">
        <span className="block text-xs font-medium text-gray-500 mb-1">End</span>
        <input type="time" value={end} onChange={(e) => onChange(joinSlot(start, e.target.value))} className={FIELD} aria-invalid={invalid || undefined} />
      </label>
    </>
  )
}

/**
 * One stop in edit mode: the place (with a "Change place" button), its time range, notes and
 * timed activities. New (unsaved) stops carry their own values; saved ones read pending edits
 * over the stop's current values.
 */
function StopEditor({ stop, isNew = false, edits = {}, isDeleted = false, onFieldChange, onDelete, onSwap }) {
  const { listing, badge } = isNew ? { listing: stop.preview, badge: NEW_STOP_BADGES[stop.item_type] } : placeFor(stop)
  const swapped = isNew ? null : edits.listing
  const shown = swapped || listing
  const read = (field, fallback) => (isNew ? stop[field] : edits[field]) ?? fallback
  const change = (field, value) => onFieldChange(isNew ? stop.tempId : stop.id, field, value, isNew)

  const slot = read('time_slot', stop.time_slot) || ''
  const slotTouched = isNew ? Boolean(stop.time_slot) : edits.time_slot !== undefined
  const slotProblem = slotTouched ? slotError(slot) : null
  const activities = read('activities', stop.activities || [])
  const showActivities = stop.item_type === 'destination' || (stop.item_type === 'accommodation' && /^Free day/.test(stop.notes || '')) || activities.length > 0
  const imagePath = shown?.images?.[0]?.image_path || shown?.image_path
  const pickKind = PICK_KINDS[stop.item_type]

  return (
    <div
      className={`rounded-xl border bg-white shadow-sm overflow-hidden transition-colors ${
        isDeleted ? 'border-red-200 opacity-70' : swapped || isNew ? 'border-primary-300 ring-1 ring-primary-100' : 'border-gray-200'
      }`}
    >
      <div className="flex flex-wrap sm:flex-nowrap gap-3 sm:gap-4 p-3 sm:p-4">
        <div className="w-16 h-16 sm:w-20 sm:h-20 rounded-lg overflow-hidden shrink-0 bg-gray-100">
          <img src={getImageUrl(imagePath)} alt={shown?.name || 'Stop'} className="w-full h-full object-cover" loading="lazy" />
        </div>
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2 mb-1">
            {badge && <span className="text-xs font-medium text-gray-600 bg-gray-100 px-2 py-0.5 rounded">{badge}</span>}
            {(swapped || isNew) && (
              <span className="text-[11px] font-semibold text-primary-700 bg-primary-50 px-2 py-0.5 rounded-full">
                {isNew ? 'New stop' : 'New place'}
              </span>
            )}
            {isDeleted && <span className="text-[11px] font-semibold text-red-600 bg-red-50 px-2 py-0.5 rounded-full">Will be removed</span>}
          </div>
          <p className={`font-heading font-semibold text-gray-900 truncate ${isDeleted ? 'line-through' : ''}`}>
            {shown?.name || 'Listing no longer available'}
          </p>
          {swapped && <p className="text-xs text-gray-500 truncate">Replaces {listing?.name}</p>}
          {!swapped && shown?.subtitle && <p className="text-xs text-gray-500 truncate">{shown.subtitle}</p>}
        </div>
        {/* Phones: actions get their own full-width row so the place name keeps its width. */}
        <div className="flex w-full gap-2 sm:w-auto sm:flex-col sm:items-end shrink-0">
          {onSwap && (
            <button
              type="button"
              onClick={onSwap}
              disabled={isDeleted}
              className="flex-1 sm:flex-none justify-center inline-flex items-center gap-1.5 px-3 py-2 sm:py-1.5 text-xs font-medium text-primary-600 bg-white border border-primary-200 rounded-lg hover:bg-primary-50 disabled:opacity-40 transition-colors"
            >
              <FiRepeat size={13} /> Change place
            </button>
          )}
          <button
            type="button"
            onClick={onDelete}
            className={`flex-1 sm:flex-none justify-center inline-flex items-center gap-1.5 px-3 py-2 sm:py-1.5 text-xs font-medium rounded-lg border transition-colors ${
              isDeleted ? 'text-gray-600 border-gray-200 hover:bg-gray-50' : 'text-red-600 border-red-200 hover:bg-red-50'
            }`}
          >
            {isDeleted ? <FiRotateCcw size={13} /> : <FiTrash2 size={13} />} {isDeleted ? 'Undo' : 'Remove'}
          </button>
        </div>
      </div>

      {!isDeleted && (
        <div className="border-t border-gray-100 bg-gray-50/70 p-4 space-y-4">
          <div className="grid grid-cols-2 sm:grid-cols-[auto_auto_1fr] gap-3 items-start">
            <TimeRangeFields slot={slot} invalid={!!slotProblem} onChange={(value) => change('time_slot', value)} />
            <label className="block col-span-2 sm:col-span-1">
              <span className="block text-xs font-medium text-gray-500 mb-1">Notes</span>
              <textarea
                value={read('notes', stop.notes || '') || ''}
                onChange={(e) => change('notes', e.target.value)}
                placeholder="Add a note for this stop"
                rows={2}
                maxLength={2000}
                className={`${FIELD} w-full resize-y`}
              />
            </label>
          </div>
          {slotProblem && <p className="field-error -mt-2" role="alert">{slotProblem}</p>}
          {isNew && stop.item_type === 'destination' ? (
            <p className="text-xs text-gray-500 rounded-lg border border-dashed border-gray-200 bg-white px-3 py-2.5">
              Its listed activities are added automatically within this time when you save; you can adjust them afterwards.
            </p>
          ) : (
            showActivities && <ActivityEditor activities={activities} onChange={(next) => change('activities', next)} />
          )}
          {pickKind &&
            (isNew || swapped ? (
              <p className="text-xs text-gray-500 rounded-lg border border-dashed border-gray-200 bg-white px-3 py-2.5">
                {pickKind.title} for the new place will be recommended for your budget when you save; you can adjust them afterwards.
              </p>
            ) : (
              <SelectionEditor
                title={pickKind.title}
                options={pickKind.options(stop)}
                picks={read(pickKind.field, picksFrom(stop[pickKind.field]))}
                onChange={(next) => change(pickKind.field, next)}
                addLabel={pickKind.addLabel}
                emptyText={pickKind.emptyText}
              />
            ))}
        </div>
      )}
    </div>
  )
}

export default function ItineraryDayCard({
  day,
  dayNumber,
  startDate,
  editable = false,
  pendingEdits,
  onFieldChange,
  onDeleteStop,
  onAddStop,
  onSwapStop,
  overnight = null,
  transportPreference = null,
}) {
  const stops = day.stops || []
  const rows = groupStops(stops)
  const dateLabel = dayDateLabel(startDate, dayNumber)
  // Live navigation follows the day's stops in order; only stops with a saved map location count.
  const [navigatingTo, setNavigatingTo] = useState(null)
  const roadTravel = transportGuide(transportPreference).inSiteRoute
  const navStops = rows
    .map(({ stop }) => ({ id: stop.id, name: placeFor(stop).listing?.name || 'This stop', point: stopCoordinates(stop) }))
    .filter((place) => place.point)
  const navIndexFor = (stop) => navStops.findIndex((place) => place.id === stop.id)
  const newItemsForDay = (pendingEdits?.newItems || []).filter((item) => Number(item.day_number) === Number(dayNumber))
  // While editing, stops (saved and new) follow their pending times, as they will after saving.
  const editRows = byTime([
    ...rows.map((row) => ({ ...row, slot: pendingEdits?.editItems?.[row.stop.id]?.time_slot ?? row.stop.time_slot })),
    ...newItemsForDay.map((item) => ({ stop: item, isNew: true, slot: item.time_slot })),
  ])

  const editorFor = (stop) => (
    <StopEditor
      stop={stop}
      edits={pendingEdits?.editItems?.[stop.id]}
      isDeleted={pendingEdits?.deleteItemIds?.has(stop.id)}
      onFieldChange={onFieldChange}
      onDelete={() => onDeleteStop(stop.id)}
      onSwap={() => onSwapStop(stop)}
    />
  )

  return (
    <div className="card p-4 sm:p-8">
      <div className="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div className="flex items-center gap-3 min-w-0">
          <div className="w-10 h-10 rounded-full bg-primary-500 text-white font-heading font-bold text-sm flex items-center justify-center shrink-0">
            {dayNumber}
          </div>
          <div>
            <h3 className="text-xl font-heading font-semibold text-gray-900">
              Day {dayNumber}
              {dateLabel && ` — ${dateLabel}`}
            </h3>
            {stops.length > 0 && (
              <p className="text-sm text-gray-500">
                {stops.length} stop{stops.length !== 1 ? 's' : ''} • {slotStart(stops[0].time_slot)} – {slotEnd(stops[stops.length - 1].time_slot)}
              </p>
            )}
          </div>
        </div>
        {editable && (
          <button
            type="button"
            onClick={() => onAddStop(dayNumber)}
            className="inline-flex items-center gap-1.5 px-3 py-2 sm:py-1.5 text-xs font-medium text-white bg-primary-500 rounded-lg hover:bg-primary-600 transition-colors"
          >
            <FiPlus size={14} /> Add stop
          </button>
        )}
      </div>

      {stops.length === 0 && newItemsForDay.length === 0 ? (
        <p className="text-sm text-gray-400">No stops scheduled for this day.</p>
      ) : editable ? (
        <div className="space-y-4">
          {editRows.map(({ stop, lunch, isNew }) =>
            isNew ? (
              <StopEditor
                key={stop.tempId}
                stop={stop}
                isNew
                onFieldChange={onFieldChange}
                onDelete={() => onDeleteStop(stop.tempId, true)}
              />
            ) : (
              <div key={stop.id} className="space-y-3">
                {editorFor(stop)}
                {lunch && (
                  <div className="ml-4 pl-4 border-l-2 border-dashed border-primary-200">
                    <p className="flex items-center gap-1.5 text-xs font-semibold text-primary-600 mb-2">
                      <FiCoffee size={12} /> Lunch break during this visit
                    </p>
                    {editorFor(lunch)}
                  </div>
                )}
              </div>
            )
          )}
        </div>
      ) : (
        rows.map(({ stop, lunch }, index) => {
          const isLast = index === rows.length - 1
          const next = rows[index + 1]?.stop

          const repeat = samePlace(rows[index - 1]?.stop, stop)

          return (
            <div key={stop.id ?? index} className="flex gap-3 sm:gap-4">
              <div className="flex flex-col items-center">
                <div className="w-9 h-9 rounded-full bg-primary-50 border-2 border-primary-500 text-primary-600 text-xs font-bold flex items-center justify-center shrink-0">
                  {index + 1}
                </div>
                {!isLast && <div className="w-0.5 flex-1 bg-gray-200 my-1" />}
              </div>

              <div className={`flex-1 min-w-0 ${isLast ? 'pb-0' : 'pb-5'}`}>
                <StopDetails
                  stop={stop}
                  repeat={repeat}
                  action={
                    // Directions to this stop, unless the tourist is already there (same place as the stop before).
                    roadTravel && !repeat && stop.id != null && navIndexFor(stop) >= 0 ? (
                      <button
                        type="button"
                        onClick={() => setNavigatingTo(navIndexFor(stop))}
                        className="inline-flex items-center gap-1.5 rounded-full border border-primary-200 bg-primary-50 px-3 py-1 text-xs font-semibold text-primary-700 hover:bg-primary-100"
                        aria-label={`Start navigation to ${placeFor(stop).listing?.name || 'this stop'}`}
                      >
                        <FiNavigation size={12} aria-hidden="true" /> Navigate here
                      </button>
                    ) : null
                  }
                />

                {lunch && (
                  <div className="mt-3 ml-2 sm:ml-6 rounded-lg border border-dashed border-primary-200 bg-primary-50/40 p-3">
                    <p className="flex items-center gap-1.5 text-xs font-semibold text-primary-600 mb-2">
                      <FiCoffee size={12} /> Lunch break during this visit
                    </p>
                    <StopDetails stop={lunch} compact />
                  </div>
                )}

                {!isLast && <TravelLeg from={stop} to={next} transportPreference={transportPreference} />}
              </div>
            </div>
          )
        })
      )}

      {navigatingTo != null && (
        <Suspense fallback={null}>
          <NavigationView stops={navStops} startIndex={navigatingTo} onClose={() => setNavigatingTo(null)} />
        </Suspense>
      )}

      {overnight?.accommodation && (
        <p className="flex items-center gap-1.5 text-sm text-gray-600 mt-6 pt-4 border-t border-gray-100">
          <FiMoon size={14} className="text-primary-500 shrink-0" />
          Overnight at <span className="font-medium text-gray-800">{overnight.accommodation.name}</span>
          {overnight.municipality?.name && <span className="text-gray-500">({overnight.municipality.name})</span>}
        </p>
      )}
    </div>
  )
}
