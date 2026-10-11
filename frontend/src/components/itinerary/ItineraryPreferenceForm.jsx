import { useState, useMemo, useEffect } from 'react'
import { differenceInCalendarDays, parseISO, format } from 'date-fns'
import { INTERESTS, TRAVEL_PACES, BUDGETS, CUISINE_PREFERENCES, ACCOMMODATION_TYPES } from '../../utils/constants'
import { getMunicipalities } from '../../api/admin'
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
  FiLoader,
  FiHome,
  FiUsers,
  FiEdit3,
} from 'react-icons/fi'

const MAX_TRIP_DAYS = 30
const MAX_TRAVELERS = 20

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

const INTEREST_ICONS = {
  nature: FiSun,
  culture: FiCamera,
  food: FiCoffee,
  adventure: FiCompass,
  shopping: FiShoppingBag,
  relaxation: FiMoon,
}

export default function ItineraryPreferenceForm({ onSubmit, loading, limitReached = false }) {
  const [municipalities, setMunicipalities] = useState([])
  const [formData, setFormData] = useState({
    municipality_ids: [],
    start_date: '',
    end_date: '',
    interests: ['nature'],
    travel_pace: 'moderate',
    budget: 'moderate',
    travelers: 1,
    food_preference: 'no_preference',
    accommodation_type: 'no_preference',
    additional_preferences: '',
    preferred_destinations: [],
  })

  const numDays = useMemo(
    () => computeNumDays(formData.start_date, formData.end_date),
    [formData.start_date, formData.end_date]
  )
  const tooManyDays = numDays !== null && numDays > MAX_TRIP_DAYS
  const townCount = formData.municipality_ids.length
  // Travelling between municipalities takes most of a day, so each one needs a day of its own.
  const tooManyTowns = numDays !== null && townCount > numDays

  useEffect(() => {
    getMunicipalities().then(setMunicipalities).catch(() => {})
  }, [])

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

  const handleSubmit = (e) => {
    e.preventDefault()
    if (townCount === 0 || tooManyTowns || formData.interests.length === 0 || !numDays || tooManyDays) return
    onSubmit({
      ...formData,
      municipality_ids: formData.municipality_ids.map(Number),
      num_days: numDays,
      travelers: Math.min(Math.max(Number(formData.travelers) || 1, 1), MAX_TRAVELERS),
    })
  }

  const noInterests = formData.interests.length === 0

  return (
    <form onSubmit={handleSubmit} className="space-y-8">
      <div>
        <div className="flex items-center justify-between mb-3">
          <label className="flex items-center gap-1.5 text-sm font-medium text-gray-700">
            <FiMapPin size={14} className="text-primary-500" /> Where do you want to go
          </label>
          <span className="text-xs text-gray-400">{townCount} selected</span>
        </div>
        <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
          {municipalities.map((m) => {
            const isSelected = formData.municipality_ids.includes(m.id)
            return (
              <label
                key={m.id}
                className={`relative flex items-center gap-2 px-3 py-2.5 border rounded-lg text-sm cursor-pointer transition-colors ${
                  isSelected
                    ? 'border-primary-500 bg-primary-50 text-primary-600 font-medium'
                    : 'border-gray-200 text-gray-700 hover:bg-gray-50'
                }`}
              >
                <input
                  type="checkbox"
                  checked={isSelected}
                  onChange={() => toggleMunicipality(m.id)}
                  className="sr-only"
                />
                {isSelected ? <FiCheckCircle size={14} className="shrink-0" /> : <FiMapPin size={14} className="shrink-0 text-gray-300" />}
                <span className="min-w-0 leading-tight">{m.name}</span>
              </label>
            )
          })}
        </div>
        {townCount === 0 ? (
          <p className="text-xs text-gray-500 mt-2">
            Pick one or more municipalities. You'll spend at least one day in each, with a stay in each town.
          </p>
        ) : tooManyTowns ? (
          <div role="alert" className="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-900">
            You've picked {townsLabel(townCount)} for a {numDays}-day trip. Travelling between municipalities takes most of a
            day, so each one needs at least one day of its own. Please add {townCount - numDays} more{' '}
            {townCount - numDays === 1 ? 'day' : 'days'} to your travel dates or remove {townsLabel(townCount - numDays)}.
          </div>
        ) : (
          numDays !== null &&
          townCount > 1 && (
            <p className="text-xs text-gray-500 mt-2">
              One municipality per day — {daysLabel(numDays)} for {townsLabel(townCount)}.
            </p>
          )
        )}
      </div>

      <div>
        <label className="flex items-center gap-1.5 text-sm font-medium text-gray-700 mb-3">
          <FiCalendar size={14} className="text-primary-500" /> Travel Dates
        </label>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label className="block text-xs text-gray-500 mb-1">Start Date</label>
            <input
              type="date"
              className="input-field"
              min={todayISO()}
              value={formData.start_date}
              onChange={(e) =>
                setFormData({
                  ...formData,
                  start_date: e.target.value,
                  end_date: formData.end_date && formData.end_date < e.target.value ? '' : formData.end_date,
                })
              }
              required
            />
          </div>
          <div>
            <label className="block text-xs text-gray-500 mb-1">End Date</label>
            <input
              type="date"
              className="input-field"
              min={formData.start_date || todayISO()}
              value={formData.end_date}
              onChange={(e) => setFormData({ ...formData, end_date: e.target.value })}
              required
            />
          </div>
        </div>
        {numDays !== null && (
          <p className={`text-xs mt-2 ${tooManyDays ? 'text-red-500' : 'text-gray-500'}`}>
            Trip length: {numDays} day{numDays > 1 ? 's' : ''}
            {tooManyDays && ` — trips can be up to ${MAX_TRIP_DAYS} days; please shorten your date range.`}
          </p>
        )}
      </div>

      <div>
        <label className="flex items-center gap-1.5 text-sm font-medium text-gray-700 mb-3">
          <FiActivity size={14} className="text-primary-500" /> Travel Pace
        </label>
        <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
          {TRAVEL_PACES.map((pace) => {
            const isSelected = formData.travel_pace === pace.value
            return (
              <button
                key={pace.value}
                type="button"
                onClick={() => setFormData({ ...formData, travel_pace: pace.value })}
                className={`p-3 border rounded-lg text-left transition-colors ${
                  isSelected ? 'border-primary-500 bg-primary-50' : 'border-gray-200 hover:bg-gray-50'
                }`}
              >
                <p className={`text-sm font-medium ${isSelected ? 'text-primary-600' : 'text-gray-900'}`}>{pace.label}</p>
                {pace.detail && <p className="text-xs text-gray-500 mt-0.5">{pace.detail}</p>}
              </button>
            )
          })}
        </div>
      </div>

      <div>
        <div className="flex items-center justify-between mb-3">
          <label className="flex items-center gap-1.5 text-sm font-medium text-gray-700">
            <FiHeart size={14} className="text-primary-500" /> Interests
          </label>
          <span className="text-xs text-gray-400">{formData.interests.length} selected</span>
        </div>
        <div className="grid grid-cols-2 md:grid-cols-3 gap-3">
          {INTERESTS.map((interest) => {
            const Icon = INTEREST_ICONS[interest.value] || FiStar
            const isSelected = formData.interests.includes(interest.value)
            return (
              <label
                key={interest.value}
                className={`relative flex flex-col items-center gap-2 p-4 border rounded-lg cursor-pointer transition-colors ${
                  isSelected ? 'border-primary-500 bg-primary-50' : 'border-gray-200 hover:bg-gray-50'
                }`}
              >
                <input
                  type="checkbox"
                  checked={isSelected}
                  onChange={() => toggleInterest(interest.value)}
                  className="sr-only"
                />
                {isSelected && (
                  <FiCheckCircle size={14} className="absolute top-2 right-2 text-primary-500" />
                )}
                <Icon size={20} className={isSelected ? 'text-primary-600' : 'text-gray-400'} />
                <span className={`text-sm font-medium ${isSelected ? 'text-primary-600' : 'text-gray-700'}`}>
                  {interest.label}
                </span>
              </label>
            )
          })}
        </div>
        {noInterests && (
          <p className="text-xs text-red-500 mt-2">Pick at least one interest to continue.</p>
        )}
      </div>

      <div>
        <label className="flex items-center gap-1.5 text-sm font-medium text-gray-700 mb-3">
          <FiDollarSign size={14} className="text-primary-500" /> Budget Preference
        </label>
        <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
          {BUDGETS.map((budget) => {
            const isSelected = formData.budget === budget.value
            return (
              <button
                key={budget.value}
                type="button"
                onClick={() => setFormData({ ...formData, budget: budget.value })}
                className={`p-3 border rounded-lg text-left transition-colors ${
                  isSelected ? 'border-primary-500 bg-primary-50' : 'border-gray-200 hover:bg-gray-50'
                }`}
              >
                <p className={`text-sm font-medium ${isSelected ? 'text-primary-600' : 'text-gray-900'}`}>{budget.label}</p>
                {budget.range && <p className="text-xs text-gray-500 mt-0.5">{budget.range}</p>}
              </button>
            )
          })}
        </div>
      </div>

      <div>
        <label htmlFor="travelers" className="flex items-center gap-1.5 text-sm font-medium text-gray-700 mb-3">
          <FiUsers size={14} className="text-primary-500" /> Number of Travelers
        </label>
        <input
          id="travelers"
          type="number"
          min={1}
          max={MAX_TRAVELERS}
          className="input-field"
          value={formData.travelers}
          onChange={(e) => setFormData({ ...formData, travelers: e.target.value })}
        />
        <p className="text-xs text-gray-500 mt-1.5">Used to estimate the total cost for your group (one room per 2 travelers).</p>
      </div>

      <div>
        <label className="flex items-center gap-1.5 text-sm font-medium text-gray-700 mb-3">
          <FiCoffee size={14} className="text-primary-500" /> Food & Beverage Preference
        </label>
        <select
          className="input-field"
          value={formData.food_preference}
          onChange={(e) => setFormData({ ...formData, food_preference: e.target.value })}
        >
          {CUISINE_PREFERENCES.map((c) => (
            <option key={c.value} value={c.value}>{c.label}</option>
          ))}
        </select>
      </div>

      <div>
        <label className="flex items-center gap-1.5 text-sm font-medium text-gray-700 mb-3">
          <FiHome size={14} className="text-primary-500" /> Accommodation Type Preference
        </label>
        <select
          className="input-field"
          value={formData.accommodation_type}
          onChange={(e) => setFormData({ ...formData, accommodation_type: e.target.value })}
        >
          {ACCOMMODATION_TYPES.map((a) => (
            <option key={a.value} value={a.value}>{a.label}</option>
          ))}
        </select>
      </div>

      <div>
        <label className="flex items-center gap-1.5 text-sm font-medium text-gray-700 mb-3">
          <FiEdit3 size={14} className="text-primary-500" /> Additional Preferences
        </label>
        <textarea
          className="input-field"
          rows={3}
          placeholder="Anything else we should know? e.g. traveling with kids, mobility needs, a must-see spot…"
          value={formData.additional_preferences}
          onChange={(e) => setFormData({ ...formData, additional_preferences: e.target.value })}
        />
      </div>

      <button
        type="submit"
        className="btn-primary w-full justify-center disabled:opacity-50 disabled:cursor-not-allowed"
        disabled={loading || limitReached || townCount === 0 || tooManyTowns || noInterests || !numDays || tooManyDays}
      >
        {loading ? (
          <>
            <FiLoader size={16} className="animate-spin" /> Generating Itinerary...
          </>
        ) : (
          <>
            <FiCompass size={16} /> Generate Itinerary
          </>
        )}
      </button>
    </form>
  )
}