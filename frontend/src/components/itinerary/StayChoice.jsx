import { useState } from 'react'
import { FiHome, FiSlash, FiCheck, FiCheckCircle, FiSearch, FiInfo, FiUsers, FiStar, FiClock } from 'react-icons/fi'
import { ACCOMMODATION_TYPES } from '../../utils/constants'
import { stayOptions } from '../../utils/roomTypes'
import { roomLabel } from '../../utils/selections'
import { accommodationTypeLabel, CONTACT_FOR_RATES, optionFeatures, optionPrice, unitTypeLabel } from '../../utils/accommodationOptions'
import { getImageUrl, IMAGE_FALLBACK_SVG, formatPeso } from '../../utils/helpers'
import { estimateOf } from '../../utils/estimates'

// "Check-in from 1:00 PM (as listed)" or "Check-in usually around 2:00 PM (typical, not confirmed)".
function checkTimes(stay) {
  if (stay.check_in_time || stay.check_out_time) {
    return `Check-in ${stay.check_in_time || 'not stated'}, check-out ${stay.check_out_time || 'not stated'} (as listed)`
  }
  const listed = /check[\s-]?in|check[\s-]?out/i.test(stay.operating_hours || '') ? stay.operating_hours : null
  if (listed) return listed
  const inAt = estimateOf(stay, 'check_in')
  const outAt = estimateOf(stay, 'check_out')
  if (!inAt && !outAt) return 'Check-in and check-out times not listed — ask the property.'
  return `Check-in usually around ${inAt || '—'}, check-out around ${outAt || '—'} (typical times, not confirmed by the property)`
}

function priceLine(stay) {
  const prices = (stay.rooms || []).map((r) => Number(r.price)).filter((p) => p > 0)
  return prices.length ? `From ${formatPeso(Math.min(...prices))} / night (indicative)` : 'Contact for rates'
}

const optionCard = (selected) =>
  `relative flex items-start gap-3 rounded-xl border p-4 pr-9 text-left transition-all ${
    selected ? 'border-primary-500 bg-primary-50 ring-1 ring-primary-500' : 'border-gray-200 bg-white hover:border-primary-200 hover:bg-gray-50'
  }`

/**
 * The planner's accommodation question: whether a stay is needed and, if so, the type, an
 * optional stay of the tourist's own choosing in one of their towns, and its room type.
 */
export default function StayChoice({ formData, set, travelers, stays, loading, failed, townCount, roomProblem }) {
  const [query, setQuery] = useState('')
  const needed = formData.accommodation_needed
  const selected = stays.find((s) => s.id === formData.accommodation_id) || null
  const q = query.trim().toLowerCase()
  const shown = q ? stays.filter((s) => `${s.name} ${s.type || ''} ${s.municipality?.name || ''}`.toLowerCase().includes(q)) : stays
  const options = stayOptions(selected, travelers)
  const pickedRoom = selected?.rooms?.find((r) => r.id === formData.room_id)

  const chooseStay = (id) => set({ accommodation_id: formData.accommodation_id === id ? null : id, room_id: null })

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3" role="radiogroup" aria-label="Do you need accommodation for this trip?">
        <button type="button" role="radio" aria-checked={needed === true} onClick={() => set({ accommodation_needed: true })} className={optionCard(needed === true)}>
          <span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-full ${needed === true ? 'bg-primary-500 text-white' : 'bg-gray-100 text-gray-500'}`}>
            <FiHome size={18} aria-hidden="true" />
          </span>
          <span>
            <span className={`block text-sm font-semibold ${needed === true ? 'text-primary-700' : 'text-gray-900'}`}>Yes, I need accommodation</span>
            <span className="block text-xs text-gray-500 mt-0.5">Choose a stay, or let us suggest one near your destinations.</span>
          </span>
          {needed === true && <FiCheckCircle size={15} className="absolute top-3 right-3 text-primary-500" aria-hidden="true" />}
        </button>
        <button
          type="button"
          role="radio"
          aria-checked={needed === false}
          onClick={() => set({ accommodation_needed: false })}
          className={optionCard(needed === false)}
        >
          <span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-full ${needed === false ? 'bg-primary-500 text-white' : 'bg-gray-100 text-gray-500'}`}>
            <FiSlash size={18} aria-hidden="true" />
          </span>
          <span>
            <span className={`block text-sm font-semibold ${needed === false ? 'text-primary-700' : 'text-gray-900'}`}>No, I don't need accommodation</span>
            <span className="block text-xs text-gray-500 mt-0.5">You have your own place to stay, or it's a day trip.</span>
          </span>
          {needed === false && <FiCheckCircle size={15} className="absolute top-3 right-3 text-primary-500" aria-hidden="true" />}
        </button>
      </div>

      {needed === false && (
        <p className="flex gap-2 rounded-xl bg-secondary-50 px-4 py-3 text-sm text-secondary-800">
          <FiInfo size={16} className="mt-0.5 shrink-0" aria-hidden="true" />
          We won't suggest a stay or add any room costs. Each day starts at its first stop, with meals planned around it.
        </p>
      )}

      {needed === true && (
        <>
          <div>
            <p className="text-xs font-semibold text-gray-600 mb-2">Preferred type</p>
            <div className="flex flex-wrap gap-2" role="radiogroup" aria-label="Accommodation type">
              {ACCOMMODATION_TYPES.map((a) => {
                const isSelected = formData.accommodation_type === a.value
                return (
                  <button
                    key={a.value}
                    type="button"
                    role="radio"
                    aria-checked={isSelected}
                    onClick={() => set({ accommodation_type: a.value })}
                    className={`inline-flex items-center gap-1.5 rounded-full border px-3.5 py-2 text-sm font-medium transition-colors ${
                      isSelected ? 'border-primary-500 bg-primary-500 text-white' : 'border-gray-200 bg-white text-gray-700 hover:border-primary-200 hover:bg-gray-50'
                    }`}
                  >
                    {isSelected && <FiCheck size={14} aria-hidden="true" />}
                    {a.label}
                  </button>
                )
              })}
            </div>
          </div>

          <div>
            <div className="flex items-baseline justify-between gap-2 mb-1">
              <p className="text-xs font-semibold text-gray-600">Choose your stay</p>
              <span className="text-xs text-gray-400">Optional</span>
            </div>
            <p className="text-xs text-gray-500 mb-3">
              {selected
                ? `${selected.name} is your stay in ${selected.municipality?.name || 'its town'}.${townCount > 1 ? " We'll pick stays near your destinations in your other towns." : ''}`
                : "Leave it to us and we'll pick a stay near your destinations in each town, matching your type and budget."}
            </p>

            {loading ? (
              <div className="space-y-2" aria-hidden="true">
                {[0, 1, 2].map((i) => <div key={i} className="skeleton h-16 rounded-xl" />)}
              </div>
            ) : stays.length === 0 ? (
              <p className="rounded-xl border border-dashed border-gray-200 px-4 py-5 text-center text-sm text-gray-500">
                {failed ? "We couldn't load the stays in your towns. You can still continue and we'll pick one for you." : 'No verified stays are listed in your towns yet.'}
              </p>
            ) : (
              <>
                {stays.length > 5 && (
                  <div className="relative mb-2">
                    <FiSearch size={15} className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400" aria-hidden="true" />
                    <input
                      id="stay_search"
                      type="search"
                      className="input-field pl-10"
                      placeholder={`Search ${stays.length} stays…`}
                      aria-label="Search stays"
                      value={query}
                      onChange={(e) => setQuery(e.target.value)}
                    />
                  </div>
                )}
                <ul className="max-h-80 overflow-y-auto rounded-xl border border-gray-200 divide-y divide-gray-100" aria-label="Stays to choose from">
                  {shown.length === 0 && <li className="px-4 py-5 text-center text-sm text-gray-500">No stay matches "{query}".</li>}
                  {shown.map((stay) => {
                    const isSelected = stay.id === formData.accommodation_id
                    const image = stay.images?.find((img) => img.is_primary) || stay.images?.[0]
                    const price = priceLine(stay)
                    return (
                      <li key={stay.id}>
                        <button
                          type="button"
                          aria-pressed={isSelected}
                          onClick={() => chooseStay(stay.id)}
                          className={`flex w-full items-center gap-3 px-3 py-3 text-left transition-colors ${isSelected ? 'bg-primary-50' : 'hover:bg-gray-50'}`}
                        >
                          <img
                            src={getImageUrl(image, { thumbnail: true })}
                            alt=""
                            className="h-14 w-16 shrink-0 rounded-lg object-cover bg-gray-100"
                            loading="lazy"
                            onError={(e) => { e.target.onerror = null; e.target.src = IMAGE_FALLBACK_SVG }}
                          />
                          <span className="min-w-0 flex-1">
                            <span className={`block truncate text-sm font-semibold ${isSelected ? 'text-primary-700' : 'text-gray-900'}`}>{stay.name}</span>
                            <span className="block truncate text-xs text-gray-500">
                              {[accommodationTypeLabel(stay), stay.municipality?.name, stay.price_range && `${stay.price_range} price`].filter(Boolean).join(' · ')}
                            </span>
                            <span className="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-gray-600">
                              {price && <span className="font-medium">{price}</span>}
                              {Number(stay.average_rating) > 0 && (
                                <span className="inline-flex items-center gap-0.5">
                                  <FiStar size={11} className="text-accent-500" aria-hidden="true" /> {Number(stay.average_rating).toFixed(1)}
                                </span>
                              )}
                            </span>
                          </span>
                          <span
                            className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-full border ${isSelected ? 'border-primary-500 bg-primary-500 text-white' : 'border-gray-300 text-transparent'}`}
                            aria-hidden="true"
                          >
                            <FiCheck size={13} />
                          </span>
                        </button>
                      </li>
                    )
                  })}
                </ul>
              </>
            )}
          </div>

          {selected && (
            <div className="rounded-xl border border-gray-200 bg-white p-4">
              <p className="text-sm font-semibold text-gray-900">Options at {selected.name}</p>
              <p className="flex gap-1.5 text-xs text-gray-500 mt-1">
                <FiClock size={12} className="mt-0.5 shrink-0" aria-hidden="true" /> {checkTimes(selected)}
              </p>

              {options.length === 0 ? (
                <p className="mt-3 text-sm text-gray-500">
                  {selected.name} hasn't listed its options on SOSOGON yet. {CONTACT_FOR_RATES}
                </p>
              ) : (
                <div className="mt-3 grid grid-cols-1 gap-2" role="radiogroup" aria-label={`Options at ${selected.name}`}>
                  {options.map(({ option, sleeps, holds, fits, needed, perNight }) => {
                    const isSelected = pickedRoom?.id === option.id
                    const price = optionPrice(option)
                    const features = optionFeatures(option)
                    return (
                      <button
                        key={option.id}
                        type="button"
                        role="radio"
                        aria-checked={isSelected}
                        disabled={!fits}
                        onClick={() => set({ room_id: isSelected ? null : option.id })}
                        className={`${optionCard(isSelected)} disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:bg-white disabled:hover:border-gray-200`}
                      >
                        <span className="min-w-0 flex-1">
                          <span className="flex flex-wrap items-baseline justify-between gap-x-3">
                            <span className={`text-sm font-semibold ${isSelected ? 'text-primary-700' : 'text-gray-900'}`}>{roomLabel(option)}</span>
                            {price.amount && <span className="text-sm font-semibold text-gray-900">{price.amount} <span className="text-xs font-normal text-gray-500">/ night</span></span>}
                          </span>
                          <span className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-gray-500">
                            <span>{unitTypeLabel(option.unit_type)}</span>
                            {sleeps && <span className="inline-flex items-center gap-1"><FiUsers size={11} aria-hidden="true" /> Sleeps {sleeps}</span>}
                          </span>
                          {features.length > 0 && <span className="mt-1 block text-xs text-gray-500">{features.slice(0, 5).join(' · ')}</span>}
                          <span className={`mt-1.5 block text-xs ${fits ? 'text-secondary-700' : 'text-red-600'}`}>
                            {!fits
                              ? `Sleeps up to ${holds} of your ${travelers} travelers.`
                              : perNight
                                ? `For ${travelers} traveler${travelers > 1 ? 's' : ''}: ${needed} unit${needed > 1 ? 's' : ''}, ${formatPeso(perNight)} a night (indicative).`
                                : price.note}
                          </span>
                        </span>
                        {isSelected && <FiCheckCircle size={15} className="absolute top-3 right-3 text-primary-500" aria-hidden="true" />}
                      </button>
                    )
                  })}
                </div>
              )}
              {roomProblem && <p role="alert" className="mt-2 text-sm text-red-600">{roomProblem}</p>}
              <p className="mt-3 flex gap-1.5 text-xs text-gray-500">
                <FiInfo size={12} className="mt-0.5 shrink-0" aria-hidden="true" />
                SOSOGON shows what this property lists. It takes no bookings and doesn't track availability: contact the property directly.
                {options.length > 0 && !pickedRoom && ' Without a choice here, we suggest what fits your group and budget.'}
              </p>
            </div>
          )}
        </>
      )}
    </div>
  )
}
