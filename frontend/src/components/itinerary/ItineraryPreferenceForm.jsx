import { useState, useMemo, useEffect, useRef } from 'react'
import { differenceInCalendarDays, parseISO, format } from 'date-fns'
import { INTERESTS, INTEREST_CATEGORY_IDS, TRAVEL_PACES, BUDGETS, CUISINE_PREFERENCES, ACCOMMODATION_TYPES, TRANSPORT_PREFERENCES } from '../../utils/constants'
import { MdDirectionsCar, MdTwoWheeler, MdCarRental, MdDirectionsBus, MdAutoAwesome } from 'react-icons/md'
import { getMunicipalities } from '../../api/admin'
import { getDestinations } from '../../api/destinations'
import { getAccommodations } from '../../api/accommodations'
import StayChoice from './StayChoice'
import { stayOptions } from '../../utils/roomTypes'
import { roomLabel } from '../../utils/selections'
import { CONTACT_FOR_RATES } from '../../utils/accommodationOptions'
import { getImageUrl, IMAGE_FALLBACK_SVG, formatPeso } from '../../utils/helpers'
import {
  FiMapPin,
  FiCalendar,
  FiActivity,
  FiHeart,
  FiDollarSign,
  FiSun,
  FiCamera,
  FiCoffee,
  FiCompass,
  FiShoppingBag,
  FiMoon,
  FiStar,
  FiCheckCircle,
  FiCheck,
  FiLoader,
  FiHome,
  FiUsers,
  FiEdit3,
  FiSearch,
  FiX,
  FiClock,
  FiMinus,
  FiPlus,
  FiArrowLeft,
  FiArrowRight,
  FiSliders,
  FiClipboard,
  FiUmbrella,
  FiFlag,
  FiNavigation,
} from 'react-icons/fi'

const MAX_TRIP_DAYS = 30
const MAX_TRAVELERS = 20
const MAX_PREFERRED = 10

const daysLabel = (n) => `${n} ${n === 1 ? 'day' : 'days'}`
const townsLabel = (n) => `${n} ${n === 1 ? 'municipality' : 'municipalities'}`

function todayISO() {
  return format(new Date(), 'yyyy-MM-dd')
}

function computeNumDays(startDate, endDate) {
  if (!startDate || !endDate) return null
  const diff = differenceInCalendarDays(parseISO(endDate), parseISO(startDate)) + 1
  return diff > 0 ? diff : null
}

// "2026-11-02" → "Mon, Nov 2, 2026"; "14:30" → "2:30 PM"
const longDate = (value) => (value ? format(parseISO(value), 'EEE, MMM d, yyyy') : '')
const clockTime = (value) => (value ? format(parseISO(`2000-01-01T${value}`), 'h:mm a') : '')

const labelOf = (list, value) => list.find((item) => item.value === value)?.label ?? value

const TRANSPORT_ICONS = {
  private_car: MdDirectionsCar,
  motorcycle: MdTwoWheeler,
  rented_vehicle: MdCarRental,
  public_transport: MdDirectionsBus,
  no_preference: MdAutoAwesome,
}

const INTEREST_ICONS = {
  nature: FiSun,
  beaches: FiUmbrella,
  culture: FiCamera,
  food: FiCoffee,
  adventure: FiCompass,
  shopping: FiShoppingBag,
  relaxation: FiMoon,
}

const STEPS = [
  { key: 'destinations', title: 'Destinations', heading: 'Where in Sorsogon would you like to go?', description: 'Pick one or more municipalities. Each one gets at least a day of its own, with a stay in each town.', icon: FiMapPin },
  { key: 'details', title: 'Trip Details', heading: 'When are you travelling, and with how many?', description: 'Your dates set the number of days; your arrival time shapes Day 1.', icon: FiCalendar },
  { key: 'preferences', title: 'Preferences', heading: 'What kind of trip would you enjoy?', description: 'Your interests, pace and budget guide which places, meals and stays we recommend.', icon: FiSliders },
  { key: 'review', title: 'Review', heading: 'Review your trip', description: "Check your choices before we build your itinerary. You can edit any section without starting over.", icon: FiClipboard },
]
const REVIEW_STEP = STEPS.length - 1

// Shared look for every selectable option: a clear selected state, never colour alone (a check icon too).
const optionClass = (selected) =>
  `relative border rounded-xl text-left transition-all duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 ${
    selected
      ? 'border-primary-500 bg-primary-50 ring-1 ring-primary-500'
      : 'border-gray-200 bg-white hover:border-primary-200 hover:bg-gray-50'
  }`

function SectionLabel({ icon: Icon, children, aside, htmlFor }) {
  const Tag = htmlFor ? 'label' : 'p'
  return (
    <div className="flex items-center justify-between gap-2 mb-3">
      <Tag htmlFor={htmlFor} className="flex items-center gap-1.5 text-sm font-semibold text-gray-800">
        <Icon size={15} className="text-primary-500" aria-hidden="true" /> {children}
      </Tag>
      {aside}
    </div>
  )
}

function FieldError({ children }) {
  return (
    <p role="alert" className="mt-2 text-sm text-red-600">
      {children}
    </p>
  )
}

function ProgressSteps({ current, canOpen, onOpen }) {
  return (
    <nav aria-label="Planning steps" className="mb-8">
      <ol className="flex items-center">
        {STEPS.map((step, i) => {
          const done = i < current
          const active = i === current
          const Icon = step.icon
          return (
            <li key={step.key} className={`flex items-center ${i < STEPS.length - 1 ? 'flex-1' : ''}`}>
              <button
                type="button"
                onClick={() => onOpen(i)}
                disabled={!canOpen(i)}
                aria-current={active ? 'step' : undefined}
                className="group flex flex-col sm:flex-row items-center gap-1.5 sm:gap-2 disabled:cursor-not-allowed"
              >
                <span
                  className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full border-2 transition-colors ${
                    active
                      ? 'border-primary-500 bg-primary-500 text-white'
                      : done
                        ? 'border-primary-500 bg-primary-50 text-primary-600 group-hover:bg-primary-100'
                        : 'border-gray-200 bg-white text-gray-400'
                  }`}
                >
                  {done ? <FiCheck size={16} aria-hidden="true" /> : <Icon size={15} aria-hidden="true" />}
                </span>
                <span
                  className={`text-xs font-semibold whitespace-nowrap ${active ? 'text-primary-700' : done ? 'text-gray-700' : 'text-gray-400'} ${
                    active ? '' : 'hidden sm:inline'
                  }`}
                >
                  <span className="sr-only">Step {i + 1}: </span>
                  {step.title}
                </span>
              </button>
              {i < STEPS.length - 1 && (
                <span className={`mx-2 sm:mx-3 h-0.5 flex-1 rounded-full ${done ? 'bg-primary-400' : 'bg-gray-200'}`} aria-hidden="true" />
              )}
            </li>
          )
        })}
      </ol>
    </nav>
  )
}

function ReviewSection({ title, icon: Icon, onEdit, children }) {
  return (
    <section className="rounded-xl border border-gray-200 bg-white p-4 sm:p-5">
      <div className="flex items-center justify-between gap-3 mb-3">
        <h4 className="flex items-center gap-2 text-sm font-semibold text-gray-900">
          <span className="flex h-7 w-7 items-center justify-center rounded-full bg-primary-50 text-primary-600">
            <Icon size={14} aria-hidden="true" />
          </span>
          {title}
        </h4>
        <button
          type="button"
          onClick={onEdit}
          className="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-semibold text-primary-600 hover:bg-primary-50 transition-colors"
        >
          <FiEdit3 size={12} aria-hidden="true" /> Edit <span className="sr-only">{title}</span>
        </button>
      </div>
      {children}
    </section>
  )
}

function ReviewRow({ label, children }) {
  return (
    <div className="flex flex-col sm:flex-row sm:items-baseline gap-0.5 sm:gap-4 py-1.5">
      <dt className="text-xs font-medium uppercase tracking-wide text-gray-500 sm:w-36 shrink-0">{label}</dt>
      <dd className="text-sm text-gray-900 min-w-0 break-words">{children}</dd>
    </div>
  )
}

function Chip({ children }) {
  return <span className="inline-flex items-center rounded-full bg-primary-50 px-2.5 py-1 text-xs font-medium text-primary-700">{children}</span>
}

/**
 * The trip planner as guided steps: destinations, trip details, preferences, then a review.
 * Answers persist across steps, and the review submits exactly the payload the single-page form
 * used to (same fields, same validation). onSubmit also gets the answers themselves, and
 * `initialForm` (answers saved before a login) restores them and opens on the review.
 */
export default function ItineraryPreferenceForm({ onSubmit, loading, limitReached = false, initialForm = null }) {
  const [municipalities, setMunicipalities] = useState([])
  const [townsLoaded, setTownsLoaded] = useState(false)
  const [search, setSearch] = useState('')
  const [placeSearch, setPlaceSearch] = useState('')
  // Published destinations of each selected town (town id => list, or 'error'), for the picker.
  const [placesByTown, setPlacesByTown] = useState({})
  const requestedTowns = useRef(new Set())
  // Approved stays of each selected town, with their rooms (town id => list, or 'error').
  const [staysByTown, setStaysByTown] = useState({})
  const requestedStayTowns = useRef(new Set())
  const [step, setStep] = useState(initialForm ? REVIEW_STEP : 0)
  // Steps where Continue was pressed, so their problems are shown (not before the tourist tries).
  const [attempted, setAttempted] = useState({})
  const headingRef = useRef(null)
  const firstRender = useRef(true)
  const [formData, setFormData] = useState({
    municipality_ids: [],
    start_date: '',
    end_date: '',
    // "HH:mm" when the tourist arrives on Day 1; empty lets the planner assume 10:00 AM.
    arrival_time: '',
    // "HH:mm" when the tourist leaves on the last day; empty keeps the last day running through dinner.
    departure_time: '',
    interests: ['nature'],
    travel_pace: 'moderate',
    budget: 'moderate',
    travelers: 1,
    food_preference: 'no_preference',
    accommodation_type: 'no_preference',
    // Whether the planner suggests a stay (null until the tourist answers), and the stay and room
    // they picked themselves (optional; the planner picks when left empty).
    accommodation_needed: null,
    // How the tourist plans to get around; sent with the request for later transportation guidance.
    transport_preference: 'no_preference',
    accommodation_id: null,
    room_id: null,
    additional_preferences: '',
    preferred_destinations: [],
    ...initialForm,
  })
  const set = (fields) => setFormData((prev) => ({ ...prev, ...fields }))

  const numDays = useMemo(
    () => computeNumDays(formData.start_date, formData.end_date),
    [formData.start_date, formData.end_date]
  )
  const tooManyDays = numDays !== null && numDays > MAX_TRIP_DAYS
  const townCount = formData.municipality_ids.length
  // Travelling between municipalities takes most of a day, so each one needs a day of its own.
  const tooManyTowns = numDays !== null && townCount > numDays
  const noInterests = formData.interests.length === 0
  const travelerTotal = Math.min(Math.max(Number(formData.travelers) || 1, 1), MAX_TRAVELERS)
  const stayLists = formData.municipality_ids.map((id) => staysByTown[id])
  const townStays = stayLists.filter(Array.isArray).flat()
  const chosenStay = formData.accommodation_needed ? townStays.find((s) => s.id === formData.accommodation_id) || null : null
  const chosenRoom = chosenStay?.rooms?.find((r) => r.id === formData.room_id) || null
  const chosenOption = chosenRoom ? stayOptions(chosenStay, travelerTotal).find((o) => o.option.id === chosenRoom.id) : null
  const roomProblem =
    chosenOption && !chosenOption.fits
      ? `${chosenStay.name}'s ${roomLabel(chosenRoom)} sleeps up to ${chosenOption.holds} of your ${travelerTotal} travelers. Choose another option.`
      : null
  const departureBeforeArrival =
    numDays === 1 && formData.arrival_time && formData.departure_time && formData.departure_time <= formData.arrival_time

  // What stops each step from being complete (null when it's fine).
  const problems = [
    townCount === 0 ? 'Pick at least one municipality to continue.' : null,
    !formData.start_date || !formData.end_date
      ? 'Choose your arrival and departure dates.'
      : !numDays
        ? 'Your departure date must be on or after your arrival date.'
        : tooManyDays
          ? `Trips can be up to ${MAX_TRIP_DAYS} days; please shorten your date range.`
          : tooManyTowns
            ? 'Add more days or choose fewer municipalities.'
            : departureBeforeArrival
              ? 'For a one-day trip, your departure time must be after your arrival time.'
              : null,
    noInterests
      ? 'Pick at least one interest to continue.'
      : formData.accommodation_needed === null
        ? 'Tell us whether you need accommodation for this trip.'
        : roomProblem,
  ]
  const firstProblemStep = problems.findIndex(Boolean)
  const ready = firstProblemStep === -1

  useEffect(() => {
    getMunicipalities()
      .then(setMunicipalities)
      .catch(() => {})
      .finally(() => setTownsLoaded(true))
  }, [])

  useEffect(() => {
    formData.municipality_ids
      .filter((id) => !requestedTowns.current.has(id))
      .forEach((id) => {
        requestedTowns.current.add(id)
        getDestinations({ municipality_id: id, per_page: 50 })
          .then((data) => setPlacesByTown((prev) => ({ ...prev, [id]: data.data || [] })))
          .catch(() => setPlacesByTown((prev) => ({ ...prev, [id]: 'error' })))
      })
  }, [formData.municipality_ids])

  useEffect(() => {
    formData.municipality_ids
      .filter((id) => !requestedStayTowns.current.has(id))
      .forEach((id) => {
        requestedStayTowns.current.add(id)
        getAccommodations({ municipality_id: id, per_page: 50, with_rooms: 1 })
          .then((data) => setStaysByTown((prev) => ({ ...prev, [id]: data.data || [] })))
          .catch(() => setStaysByTown((prev) => ({ ...prev, [id]: 'error' })))
      })
  }, [formData.municipality_ids])

  // On a step change, bring its heading into view and focus it for keyboard and screen-reader users.
  useEffect(() => {
    if (firstRender.current) {
      firstRender.current = false
      return
    }
    const heading = headingRef.current
    if (!heading) return
    if (heading.getBoundingClientRect().top < 80) heading.scrollIntoView({ behavior: 'smooth', block: 'start' })
    heading.focus({ preventScroll: true })
  }, [step])

  const toggleInterest = (interest) => {
    setFormData((prev) => ({
      ...prev,
      interests: prev.interests.includes(interest)
        ? prev.interests.filter((i) => i !== interest)
        : [...prev.interests, interest],
    }))
  }

  const toggleMunicipality = (id) => {
    setFormData((prev) => ({
      ...prev,
      municipality_ids: prev.municipality_ids.includes(id)
        ? prev.municipality_ids.filter((m) => m !== id)
        : [...prev.municipality_ids, id],
    }))
  }

  const setTravelers = (value) => set({ travelers: value === '' ? '' : Math.min(Math.max(Number(value) || 1, 1), MAX_TRAVELERS) })

  // A step can be opened when every step before it is complete (earlier steps always).
  const canOpen = (i) => i <= step || problems.slice(0, i).every((p) => !p)
  const goTo = (i) => {
    if (canOpen(i)) setStep(i)
  }
  const goNext = () => {
    setAttempted((prev) => ({ ...prev, [step]: true }))
    if (!problems[step]) setStep((s) => Math.min(s + 1, REVIEW_STEP))
  }

  const handleSubmit = (e) => {
    e.preventDefault()
    // Enter in a field on an earlier step moves on instead of generating.
    if (step < REVIEW_STEP) {
      goNext()
      return
    }
    if (!ready) {
      setAttempted({ 0: true, 1: true, 2: true })
      setStep(firstProblemStep)
      return
    }
    const { arrival_time: arrivalTime, departure_time: departureTime, ...rest } = formData
    onSubmit({
      ...rest,
      ...(arrivalTime ? { arrival_time: arrivalTime.slice(0, 5) } : {}),
      ...(departureTime ? { departure_time: departureTime.slice(0, 5) } : {}),
      preferred_destinations: preferred.map((d) => d.id),
      accommodation_needed: Boolean(formData.accommodation_needed),
      accommodation_id: chosenStay?.id ?? null,
      room_id: chosenStay && chosenRoom ? chosenRoom.id : null,
      municipality_ids: formData.municipality_ids.map(Number),
      num_days: numDays,
      travelers: Math.min(Math.max(Number(formData.travelers) || 1, 1), MAX_TRAVELERS),
    }, formData)
  }

  const interestCategories = new Set(formData.interests.flatMap((i) => INTEREST_CATEGORY_IDS[i] || []))
  const townLists = formData.municipality_ids.map((id) => placesByTown[id])
  const placesLoading = townLists.some((list) => list === undefined)
  const placesFailed = townLists.some((list) => list === 'error')
  const matchingPlaces = townLists
    .filter(Array.isArray)
    .flat()
    .filter((d) => interestCategories.has(Number(d.category_id)))
    .sort((a, b) => a.name.localeCompare(b.name))
  const preferred = formData.preferred_destinations
    .map((id) => matchingPlaces.find((d) => d.id === id))
    .filter(Boolean)
  const placeQuery = placeSearch.trim().toLowerCase()
  const shownPlaces = placeQuery
    ? matchingPlaces.filter((d) => `${d.name} ${d.category?.name || ''} ${d.municipality?.name || ''}`.toLowerCase().includes(placeQuery))
    : matchingPlaces
  const togglePreferred = (id) =>
    setFormData((prev) => ({
      ...prev,
      preferred_destinations: prev.preferred_destinations.includes(id)
        ? prev.preferred_destinations.filter((p) => p !== id)
        : [...prev.preferred_destinations, id],
    }))

  const query = search.trim().toLowerCase()
  const shownTowns = query ? municipalities.filter((m) => m.name.toLowerCase().includes(query)) : municipalities
  const selectedTowns = formData.municipality_ids.map((id) => municipalities.find((m) => m.id === id)).filter(Boolean)
  const current = STEPS[step]
  const travelerCount = Number(formData.travelers) || 1

  return (
    <form onSubmit={handleSubmit} noValidate>
      <ProgressSteps current={step} canOpen={canOpen} onOpen={goTo} />

      <div key={step} className="animate-[fadeSlideUp_0.3s_ease-out_both]">
        <header className="mb-6">
          <p className="text-xs font-semibold uppercase tracking-[0.16em] text-secondary-600 mb-1.5">
            Step {step + 1} of {STEPS.length} · {current.title}
          </p>
          <h3 ref={headingRef} tabIndex={-1} className="text-xl sm:text-2xl font-heading font-bold text-gray-900 focus:outline-none scroll-mt-28">
            {current.heading}
          </h3>
          <p className="text-sm text-gray-500 mt-1.5">{current.description}</p>
        </header>

        {/* ==================== STEP 1: DESTINATIONS ==================== */}
        {step === 0 && (
          <div className="space-y-5">
            <div className="relative">
              <FiSearch size={16} className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400" aria-hidden="true" />
              <input
                type="search"
                className="input-field pl-10"
                placeholder="Search municipalities…"
                aria-label="Search municipalities"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
            </div>

            <div className="rounded-xl bg-gray-50 border border-gray-100 px-3.5 py-3">
              <div className="flex items-center justify-between gap-2">
                <p className="text-xs font-semibold text-gray-600">Your destinations</p>
                <span className={`text-xs font-semibold px-2 py-0.5 rounded-full ${townCount ? 'bg-primary-500 text-white' : 'bg-gray-200 text-gray-600'}`}>
                  {townCount} selected
                </span>
              </div>
              {selectedTowns.length > 0 ? (
                <ul className="flex flex-wrap gap-2 mt-2.5">
                  {selectedTowns.map((m) => (
                    <li key={m.id}>
                      <button
                        type="button"
                        onClick={() => toggleMunicipality(m.id)}
                        className="inline-flex items-center gap-1.5 rounded-full bg-white border border-primary-200 pl-3 pr-2 py-1 text-xs font-medium text-primary-700 hover:bg-primary-50 transition-colors"
                      >
                        {m.name} <FiX size={13} aria-hidden="true" />
                        <span className="sr-only">Remove {m.name}</span>
                      </button>
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="text-xs text-gray-500 mt-1.5">Nothing selected yet. Tap a municipality below to add it.</p>
              )}
            </div>

            {!townsLoaded ? (
              <div className="grid grid-cols-2 sm:grid-cols-3 gap-3" aria-hidden="true">
                {Array.from({ length: 6 }, (_, i) => <div key={i} className="skeleton h-16 rounded-xl" />)}
              </div>
            ) : shownTowns.length === 0 ? (
              <p className="rounded-xl border border-dashed border-gray-200 py-8 text-center text-sm text-gray-500">
                {municipalities.length === 0 ? "We couldn't load the municipalities. Please refresh the page." : `No municipality matches "${search}".`}
              </p>
            ) : (
              <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
                {shownTowns.map((m) => {
                  const isSelected = formData.municipality_ids.includes(m.id)
                  return (
                    <button
                      key={m.id}
                      type="button"
                      aria-pressed={isSelected}
                      onClick={() => toggleMunicipality(m.id)}
                      className={`${optionClass(isSelected)} flex items-center gap-3 px-3.5 py-3.5`}
                    >
                      <span
                        className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition-colors ${
                          isSelected ? 'bg-primary-500 text-white' : 'bg-secondary-50 text-secondary-600'
                        }`}
                      >
                        {isSelected ? <FiCheck size={16} aria-hidden="true" /> : <FiMapPin size={16} aria-hidden="true" />}
                      </span>
                      <span className="min-w-0">
                        <span className={`block text-sm font-semibold leading-tight ${isSelected ? 'text-primary-700' : 'text-gray-900'}`}>{m.name}</span>
                        <span className="block text-xs text-gray-500 mt-0.5">{isSelected ? 'Selected' : 'Sorsogon'}</span>
                      </span>
                    </button>
                  )
                })}
              </div>
            )}
            {attempted[0] && problems[0] && <FieldError>{problems[0]}</FieldError>}
          </div>
        )}

        {/* ==================== STEP 2: TRIP DETAILS ==================== */}
        {step === 1 && (
          <div className="space-y-7">
            <div>
              <SectionLabel icon={FiCalendar}>Travel dates</SectionLabel>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div className="rounded-xl border border-gray-200 bg-white p-3.5">
                  <label htmlFor="start_date" className="block text-xs font-semibold text-gray-600 mb-1.5">Arrival date</label>
                  <input
                    id="start_date"
                    type="date"
                    className="input-field"
                    min={todayISO()}
                    value={formData.start_date}
                    onChange={(e) =>
                      set({
                        start_date: e.target.value,
                        end_date: formData.end_date && formData.end_date < e.target.value ? '' : formData.end_date,
                      })
                    }
                    required
                  />
                </div>
                <div className="rounded-xl border border-gray-200 bg-white p-3.5">
                  <label htmlFor="end_date" className="block text-xs font-semibold text-gray-600 mb-1.5">Departure date</label>
                  <input
                    id="end_date"
                    type="date"
                    className="input-field"
                    min={formData.start_date || todayISO()}
                    value={formData.end_date}
                    onChange={(e) => set({ end_date: e.target.value })}
                    required
                  />
                </div>
              </div>

              {numDays !== null && (
                <div
                  className={`mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-xl px-4 py-3 ${
                    tooManyDays ? 'bg-red-50 text-red-700' : 'bg-secondary-50 text-secondary-800'
                  }`}
                  aria-live="polite"
                >
                  <span className="text-lg font-heading font-bold">{daysLabel(numDays)}</span>
                  {!tooManyDays && (
                    <span className="text-sm">
                      {numDays > 1 ? `${numDays - 1} night${numDays > 2 ? 's' : ''} · ` : 'A day trip · '}
                      {longDate(formData.start_date)} – {longDate(formData.end_date)}
                    </span>
                  )}
                  {tooManyDays && <span className="text-sm">Trips can be up to {MAX_TRIP_DAYS} days; please shorten your date range.</span>}
                </div>
              )}

              {tooManyTowns && (
                <div role="alert" className="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                  You've picked {townsLabel(townCount)} for a {numDays}-day trip. Travelling between municipalities takes most of a
                  day, so each one needs at least one day of its own. Please add {townCount - numDays} more{' '}
                  {townCount - numDays === 1 ? 'day' : 'days'} to your travel dates or{' '}
                  <button type="button" onClick={() => setStep(0)} className="font-semibold underline underline-offset-2">
                    remove {townsLabel(townCount - numDays)}
                  </button>
                  .
                </div>
              )}
              {!tooManyTowns && numDays !== null && !tooManyDays && townCount > 1 && (
                <p className="text-xs text-gray-500 mt-2">One municipality per day — {daysLabel(numDays)} for {townsLabel(townCount)}.</p>
              )}
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
              <div>
                <SectionLabel icon={FiClock} htmlFor="arrival_time" aside={<span className="text-xs text-gray-400">Optional</span>}>
                  Arrival time on Day 1
                </SectionLabel>
                <input
                  id="arrival_time"
                  type="time"
                  className="input-field"
                  value={formData.arrival_time}
                  onChange={(e) => set({ arrival_time: e.target.value })}
                />
                <p className="text-xs text-gray-500 mt-1.5">Day 1 starts with your arrival and check-in. Leave blank and we'll plan around a 10:00 AM arrival.</p>
              </div>

              <div>
                <SectionLabel icon={FiFlag} htmlFor="departure_time" aside={<span className="text-xs text-gray-400">Optional</span>}>
                  Departure time on {numDays ? `Day ${numDays}` : 'the last day'}
                </SectionLabel>
                <input
                  id="departure_time"
                  type="time"
                  className="input-field"
                  value={formData.departure_time}
                  onChange={(e) => set({ departure_time: e.target.value })}
                  aria-invalid={departureBeforeArrival || undefined}
                />
                {departureBeforeArrival ? (
                  <p className="text-xs text-red-600 mt-1.5">For a one-day trip, leave after you arrive.</p>
                ) : (
                  <p className="text-xs text-gray-500 mt-1.5">Your last day's plan ends by then. Leave blank if you're not sure and it runs through dinner.</p>
                )}
              </div>

              <div>
                <SectionLabel icon={FiUsers} htmlFor="travelers">Number of travelers</SectionLabel>
                <div className="flex items-stretch gap-2">
                  <button
                    type="button"
                    onClick={() => setTravelers(travelerCount - 1)}
                    disabled={travelerCount <= 1}
                    className="flex w-11 shrink-0 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-700 hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed"
                    aria-label="One traveler fewer"
                  >
                    <FiMinus size={16} aria-hidden="true" />
                  </button>
                  <input
                    id="travelers"
                    type="number"
                    min={1}
                    max={MAX_TRAVELERS}
                    inputMode="numeric"
                    className="input-field text-center"
                    value={formData.travelers}
                    onChange={(e) => set({ travelers: e.target.value })}
                    onBlur={(e) => setTravelers(e.target.value || 1)}
                  />
                  <button
                    type="button"
                    onClick={() => setTravelers(travelerCount + 1)}
                    disabled={travelerCount >= MAX_TRAVELERS}
                    className="flex w-11 shrink-0 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-700 hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed"
                    aria-label="One more traveler"
                  >
                    <FiPlus size={16} aria-hidden="true" />
                  </button>
                </div>
                <p className="text-xs text-gray-500 mt-1.5">Used to estimate the total cost for your group (one room per 2 travelers).</p>
              </div>
            </div>
            {attempted[1] && problems[1] && !tooManyTowns && !tooManyDays && <FieldError>{problems[1]}</FieldError>}
          </div>
        )}

        {/* ==================== STEP 3: PREFERENCES ==================== */}
        {step === 2 && (
          <div className="space-y-8">
            <div>
              <SectionLabel icon={FiHeart} aside={<span className="text-xs text-gray-400">{formData.interests.length} selected · pick any</span>}>
                Interests
              </SectionLabel>
              <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                {INTERESTS.map((interest) => {
                  const Icon = INTEREST_ICONS[interest.value] || FiStar
                  const isSelected = formData.interests.includes(interest.value)
                  return (
                    <button
                      key={interest.value}
                      type="button"
                      aria-pressed={isSelected}
                      onClick={() => toggleInterest(interest.value)}
                      className={`${optionClass(isSelected)} flex flex-col items-center gap-2 px-3 py-4`}
                    >
                      {isSelected && <FiCheckCircle size={14} className="absolute top-2 right-2 text-primary-500" aria-hidden="true" />}
                      <span
                        className={`flex h-10 w-10 items-center justify-center rounded-full ${
                          isSelected ? 'bg-primary-500 text-white' : 'bg-gray-100 text-gray-500'
                        }`}
                      >
                        <Icon size={18} aria-hidden="true" />
                      </span>
                      <span className={`text-sm font-semibold ${isSelected ? 'text-primary-700' : 'text-gray-700'}`}>{interest.label}</span>
                    </button>
                  )
                })}
              </div>
              {(attempted[2] || noInterests) && problems[2] && <FieldError>{problems[2]}</FieldError>}
            </div>

            <div>
              <SectionLabel icon={FiActivity}>Travel pace</SectionLabel>
              <div className="grid grid-cols-1 md:grid-cols-3 gap-3" role="radiogroup" aria-label="Travel pace">
                {TRAVEL_PACES.map((pace) => {
                  const isSelected = formData.travel_pace === pace.value
                  return (
                    <button
                      key={pace.value}
                      type="button"
                      role="radio"
                      aria-checked={isSelected}
                      onClick={() => set({ travel_pace: pace.value })}
                      className={`${optionClass(isSelected)} p-3.5`}
                    >
                      <span className="flex items-center justify-between gap-2">
                        <span className={`text-sm font-semibold ${isSelected ? 'text-primary-700' : 'text-gray-900'}`}>{pace.label}</span>
                        {isSelected && <FiCheckCircle size={14} className="text-primary-500 shrink-0" aria-hidden="true" />}
                      </span>
                      {pace.detail && <span className="block text-xs text-gray-500 mt-1 leading-snug">{pace.detail}</span>}
                    </button>
                  )
                })}
              </div>
            </div>

            <div>
              <SectionLabel icon={FiDollarSign}>Budget</SectionLabel>
              <div className="grid grid-cols-1 md:grid-cols-3 gap-3" role="radiogroup" aria-label="Budget">
                {BUDGETS.map((budget) => {
                  const isSelected = formData.budget === budget.value
                  return (
                    <button
                      key={budget.value}
                      type="button"
                      role="radio"
                      aria-checked={isSelected}
                      onClick={() => set({ budget: budget.value })}
                      className={`${optionClass(isSelected)} p-3.5`}
                    >
                      <span className="flex items-center justify-between gap-2">
                        <span className={`text-sm font-semibold ${isSelected ? 'text-primary-700' : 'text-gray-900'}`}>{budget.label}</span>
                        {isSelected && <FiCheckCircle size={14} className="text-primary-500 shrink-0" aria-hidden="true" />}
                      </span>
                      {budget.range && <span className="block text-xs text-gray-500 mt-1">{budget.range}</span>}
                    </button>
                  )
                })}
              </div>
            </div>

            <div>
              <SectionLabel icon={FiCoffee}>Food & beverage preference</SectionLabel>
              <div className="flex flex-wrap gap-2" role="radiogroup" aria-label="Food and beverage preference">
                {CUISINE_PREFERENCES.map((c) => {
                  const isSelected = formData.food_preference === c.value
                  return (
                    <button
                      key={c.value}
                      type="button"
                      role="radio"
                      aria-checked={isSelected}
                      onClick={() => set({ food_preference: c.value })}
                      className={`inline-flex items-center gap-1.5 rounded-full border px-3.5 py-2 text-sm font-medium transition-colors ${
                        isSelected ? 'border-primary-500 bg-primary-500 text-white' : 'border-gray-200 bg-white text-gray-700 hover:border-primary-200 hover:bg-gray-50'
                      }`}
                    >
                      {isSelected && <FiCheck size={14} aria-hidden="true" />}
                      {c.label}
                    </button>
                  )
                })}
              </div>
            </div>

            <div>
              <SectionLabel icon={FiHome}>Do you need accommodation for this trip?</SectionLabel>
              <StayChoice
                formData={formData}
                set={set}
                travelers={travelerTotal}
                stays={townStays}
                loading={stayLists.some((list) => list === undefined)}
                failed={stayLists.some((list) => list === 'error')}
                townCount={townCount}
                roomProblem={roomProblem}
              />
              {attempted[2] && !noInterests && formData.accommodation_needed === null && <FieldError>Tell us whether you need accommodation for this trip.</FieldError>}
            </div>

            <div>
              <SectionLabel icon={FiNavigation}>Transportation preference</SectionLabel>
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3" role="radiogroup" aria-label="Transportation preference">
                {TRANSPORT_PREFERENCES.map((option) => {
                  const Icon = TRANSPORT_ICONS[option.value] || FiNavigation
                  const isSelected = formData.transport_preference === option.value
                  return (
                    <button
                      key={option.value}
                      type="button"
                      role="radio"
                      aria-checked={isSelected}
                      onClick={() => set({ transport_preference: option.value })}
                      className={`${optionClass(isSelected)} flex items-center gap-3 p-3.5`}
                    >
                      <span
                        className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-full ${
                          isSelected ? 'bg-primary-500 text-white' : 'bg-gray-100 text-gray-500'
                        }`}
                      >
                        <Icon size={20} aria-hidden="true" />
                      </span>
                      <span className="min-w-0 flex-1 pr-4">
                        <span className={`block text-sm font-semibold ${isSelected ? 'text-primary-700' : 'text-gray-900'}`}>{option.label}</span>
                        <span className="block text-xs text-gray-500 mt-0.5 leading-snug">{option.detail}</span>
                      </span>
                      {isSelected && <FiCheckCircle size={14} className="absolute top-2.5 right-2.5 text-primary-500" aria-hidden="true" />}
                    </button>
                  )
                })}
              </div>
              <p className="text-xs text-gray-500 mt-2">We'll use this for travel tips between stops. SOSOGON doesn't book transport or list fares and schedules.</p>
            </div>

            <div>
              <SectionLabel
                icon={FiStar}
                htmlFor="place_search"
                aside={<span className="text-xs text-gray-400">Optional · {preferred.length} of {MAX_PREFERRED}</span>}
              >
                Places you'd love to visit
              </SectionLabel>
              <p className="text-xs text-gray-500 -mt-1.5 mb-3">
                Verified places in your towns that match your interests. We'll include them where they fit your days, opening hours and route.
              </p>

              {preferred.length > 0 && (
                <ul className="flex flex-wrap gap-2 mb-3">
                  {preferred.map((d) => (
                    <li key={d.id}>
                      <button
                        type="button"
                        onClick={() => togglePreferred(d.id)}
                        className="inline-flex items-center gap-1.5 rounded-full bg-primary-500 pl-3 pr-2 py-1 text-xs font-medium text-white hover:bg-primary-600 transition-colors"
                      >
                        {d.name} <FiX size={13} aria-hidden="true" />
                        <span className="sr-only">Remove {d.name}</span>
                      </button>
                    </li>
                  ))}
                </ul>
              )}

              {placesLoading ? (
                <div className="space-y-2" aria-hidden="true">
                  {[0, 1, 2].map((i) => <div key={i} className="skeleton h-14 rounded-xl" />)}
                </div>
              ) : matchingPlaces.length === 0 ? (
                <p className="rounded-xl border border-dashed border-gray-200 px-4 py-5 text-center text-sm text-gray-500">
                  {placesFailed
                    ? "We couldn't load the places in your towns. You can still continue without picking any."
                    : interestCategories.size === 0
                      ? 'Food and Shopping are planned around restaurants and souvenir shops. Add a sightseeing interest to pick places.'
                      : 'No verified places in your towns match the interests you picked.'}
                </p>
              ) : (
                <>
                  <div className="relative mb-2">
                    <FiSearch size={15} className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400" aria-hidden="true" />
                    <input
                      id="place_search"
                      type="search"
                      className="input-field pl-10"
                      placeholder={`Search ${matchingPlaces.length} places…`}
                      value={placeSearch}
                      onChange={(e) => setPlaceSearch(e.target.value)}
                    />
                  </div>
                  <ul className="max-h-72 overflow-y-auto rounded-xl border border-gray-200 divide-y divide-gray-100" aria-label="Places to pick">
                    {shownPlaces.length === 0 && <li className="px-4 py-5 text-center text-sm text-gray-500">No place matches "{placeSearch}".</li>}
                    {shownPlaces.map((d) => {
                      const isSelected = formData.preferred_destinations.includes(d.id)
                      const full = !isSelected && preferred.length >= MAX_PREFERRED
                      const image = d.images?.find((img) => img.is_primary) || d.images?.[0]
                      return (
                        <li key={d.id}>
                          <button
                            type="button"
                            aria-pressed={isSelected}
                            disabled={full}
                            onClick={() => togglePreferred(d.id)}
                            className={`flex w-full items-center gap-3 px-3 py-2.5 text-left transition-colors disabled:opacity-50 disabled:cursor-not-allowed ${
                              isSelected ? 'bg-primary-50' : 'hover:bg-gray-50'
                            }`}
                          >
                            <img src={getImageUrl(image, { thumbnail: true })} alt="" className="h-11 w-11 shrink-0 rounded-lg object-cover bg-gray-100"
                              loading="lazy"
                              onError={(e) => { e.target.onerror = null; e.target.src = IMAGE_FALLBACK_SVG }}
                            />
                            <span className="min-w-0 flex-1">
                              <span className={`block truncate text-sm font-semibold ${isSelected ? 'text-primary-700' : 'text-gray-900'}`}>{d.name}</span>
                              <span className="block truncate text-xs text-gray-500">
                                {[d.category?.name, d.municipality?.name].filter(Boolean).join(' · ')}
                              </span>
                            </span>
                            <span
                              className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-full border ${
                                isSelected ? 'border-primary-500 bg-primary-500 text-white' : 'border-gray-300 text-transparent'
                              }`}
                              aria-hidden="true"
                            >
                              <FiCheck size={13} />
                            </span>
                          </button>
                        </li>
                      )
                    })}
                  </ul>
                  {preferred.length >= MAX_PREFERRED && <p className="text-xs text-gray-500 mt-2">You've picked {MAX_PREFERRED} places, the most we can plan around.</p>}
                </>
              )}
            </div>

            <div>
              <SectionLabel icon={FiEdit3} htmlFor="additional_preferences" aside={<span className="text-xs text-gray-400">Optional</span>}>
                Additional preferences
              </SectionLabel>
              <textarea
                id="additional_preferences"
                className="input-field"
                rows={3}
                maxLength={1000}
                placeholder="Anything else we should know? e.g. traveling with kids, mobility needs, a must-see spot…"
                value={formData.additional_preferences}
                onChange={(e) => set({ additional_preferences: e.target.value })}
              />
            </div>
          </div>
        )}

        {/* ==================== STEP 4: REVIEW ==================== */}
        {step === REVIEW_STEP && (
          <div className="space-y-4">
            <ReviewSection title="Destinations" icon={FiMapPin} onEdit={() => setStep(0)}>
              {selectedTowns.length ? (
                <div className="flex flex-wrap gap-2">
                  {selectedTowns.map((m) => <Chip key={m.id}>{m.name}</Chip>)}
                </div>
              ) : (
                <p className="text-sm text-red-600">No municipality selected.</p>
              )}
            </ReviewSection>

            <ReviewSection title="Trip details" icon={FiCalendar} onEdit={() => setStep(1)}>
              <dl className="divide-y divide-gray-100">
                <ReviewRow label="Arrival">{longDate(formData.start_date) || '—'}</ReviewRow>
                <ReviewRow label="Arrival time">{formData.arrival_time ? clockTime(formData.arrival_time) : 'Not set — Day 1 assumes about 10:00 AM'}</ReviewRow>
                <ReviewRow label="Departure">{longDate(formData.end_date) || '—'}</ReviewRow>
                <ReviewRow label="Departure time">{formData.departure_time ? clockTime(formData.departure_time) : "Not set — the last day runs through dinner"}</ReviewRow>
                <ReviewRow label="Trip length">{numDays ? daysLabel(numDays) : '—'}</ReviewRow>
                <ReviewRow label="Travelers">{travelerCount} traveler{travelerCount > 1 ? 's' : ''}</ReviewRow>
              </dl>
            </ReviewSection>

            <ReviewSection title="Preferences" icon={FiSliders} onEdit={() => setStep(2)}>
              <dl className="divide-y divide-gray-100">
                <ReviewRow label="Interests">
                  {formData.interests.length ? (
                    <span className="flex flex-wrap gap-1.5">{formData.interests.map((i) => <Chip key={i}>{labelOf(INTERESTS, i)}</Chip>)}</span>
                  ) : (
                    <span className="text-red-600">None selected</span>
                  )}
                </ReviewRow>
                <ReviewRow label="Must-see places">
                  {preferred.length ? (
                    <span className="flex flex-wrap gap-1.5">{preferred.map((d) => <Chip key={d.id}>{d.name}</Chip>)}</span>
                  ) : (
                    <span className="text-gray-500">None picked — we'll choose for you</span>
                  )}
                </ReviewRow>
                <ReviewRow label="Travel pace">{labelOf(TRAVEL_PACES, formData.travel_pace)}</ReviewRow>
                <ReviewRow label="Budget">
                  {labelOf(BUDGETS, formData.budget)}
                  {BUDGETS.find((b) => b.value === formData.budget)?.range && (
                    <span className="text-gray-500"> · {BUDGETS.find((b) => b.value === formData.budget).range}</span>
                  )}
                </ReviewRow>
                <ReviewRow label="Food">{labelOf(CUISINE_PREFERENCES, formData.food_preference)}</ReviewRow>
                <ReviewRow label="Accommodation">
                  {formData.accommodation_needed === null ? (
                    <span className="text-red-600">Not answered yet</span>
                  ) : !formData.accommodation_needed ? (
                    "Not needed — no stay or room costs"
                  ) : chosenStay ? (
                    <span>
                      {chosenStay.name}
                      <span className="text-gray-500"> · {chosenStay.municipality?.name}</span>
                      {chosenOption && !roomProblem && (
                        <span className="block text-gray-600">
                          {roomLabel(chosenRoom)} · {chosenOption.perNight ? `${formatPeso(chosenOption.perNight)} a night (indicative)` : CONTACT_FOR_RATES}
                        </span>
                      )}
                      {!chosenOption && <span className="block text-gray-500">Options suggested for your group</span>}
                    </span>
                  ) : (
                    `Needed — we'll pick a stay near your destinations (${labelOf(ACCOMMODATION_TYPES, formData.accommodation_type)})`
                  )}
                </ReviewRow>
                <ReviewRow label="Transportation">
                  {labelOf(TRANSPORT_PREFERENCES, formData.transport_preference)}
                  {formData.transport_preference === 'no_preference' && <span className="text-gray-500"> — let SOSOGON suggest practical options</span>}
                </ReviewRow>
                {formData.additional_preferences.trim() && (
                  <ReviewRow label="Additional notes">
                    <span className="whitespace-pre-line">{formData.additional_preferences.trim()}</span>
                  </ReviewRow>
                )}
              </dl>
            </ReviewSection>

            {!ready && (
              <FieldError>
                {problems[firstProblemStep]}{' '}
                <button type="button" onClick={() => setStep(firstProblemStep)} className="font-semibold underline underline-offset-2">
                  Fix it in {STEPS[firstProblemStep].title}
                </button>
              </FieldError>
            )}
            {limitReached && (
              <p className="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">
                You've reached today's itinerary limit. It resets at midnight (PH time); your answers stay here until then.
              </p>
            )}
          </div>
        )}
      </div>

      {/* ==================== NAVIGATION ==================== */}
      <div className="mt-8 flex items-center justify-between gap-3 border-t border-gray-100 pt-5">
        {step > 0 ? (
          <button type="button" onClick={() => setStep((s) => s - 1)} className="btn-secondary" disabled={loading}>
            <FiArrowLeft size={16} aria-hidden="true" /> Back
          </button>
        ) : (
          <span />
        )}
        {step < REVIEW_STEP ? (
          <button type="button" onClick={goNext} className="btn-primary">
            {step === REVIEW_STEP - 1 ? 'Review trip' : 'Continue'} <FiArrowRight size={16} aria-hidden="true" />
          </button>
        ) : (
          <button
            type="submit"
            className="btn-primary justify-center disabled:opacity-50 disabled:cursor-not-allowed"
            disabled={loading || limitReached || !ready}
          >
            {loading ? (
              <>
                <FiLoader size={16} className="animate-spin" aria-hidden="true" /> Generating Itinerary...
              </>
            ) : (
              <>
                <FiCompass size={16} aria-hidden="true" /> Generate My Itinerary
              </>
            )}
          </button>
        )}
      </div>
    </form>
  )
}
