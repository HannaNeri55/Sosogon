import { FiAlertCircle, FiCheckSquare, FiInfo } from 'react-icons/fi'
import { costNoteIsEstimate } from '../../utils/estimates'

/**
 * "Before you go": what to bring, what to arrange, safety, and which parts of the plan are
 * estimates, gathered from the itinerary's own stops (the planner's notes and activities), so
 * it only mentions what this trip actually has. Nothing here is a confirmed fact the stops
 * don't already say.
 */
function tripReminders(itinerary) {
  const items = itinerary.items || []
  const text = (item) => `${item.notes || ''} ${item.preference_note || ''}`
  const has = (pattern) => items.some((item) => pattern.test(text(item)))
  const hasActivity = (pattern) => items.some((item) => (item.activities || []).some((a) => pattern.test(a.name || '')))
  const publicTransport = itinerary.transport_preference === 'public_transport'

  const bring = ['Valid ID and enough cash: smaller places may not take cards or e-wallets']
  if (has(/swimwear/i)) bring.push('Swimwear, a towel and a change of clothes for the swimming stops')
  if (has(/comfortable shoes|trekking|hike/i)) bring.push('Comfortable walking shoes and water for the walks and hikes')
  if (has(/sun protection/i) || has(/swimwear/i)) bring.push('Sun protection: a hat and sunscreen')
  if (hasActivity(/packed (breakfast|lunch|dinner)|picnic lunch/i) || has(/packed (breakfast|lunch|dinner)/i)) bring.push('Food for the packed meals the plan mentions')
  bring.push('An umbrella or light rain jacket: weather in Sorsogon can change quickly')

  const arrange = []
  if ((itinerary.stays || []).some((stay) => stay.accommodation_id) || itinerary.accommodation_id) {
    arrange.push('Book your stay directly with the property: SOSOGON takes no bookings, and rates and availability must be confirmed with them.')
  }
  if (hasActivity(/^Registration and briefing$/)) {
    arrange.push('Boat trips, guided tours and similar activities need a local operator or guide: contact them or the municipal tourism office ahead, and allow time to register on the day.')
  }
  if (publicTransport) {
    arrange.push('Ask your accommodation or the tourism office which jeepneys, vans or tricycles to take, and confirm fares and the last trips of the day.')
  }

  const safety = []
  if (has(/Coast Guard|sea conditions/i)) safety.push('Boat trips depend on the weather and sea: check Coast Guard and PAGASA advisories the day before, and follow the crew\'s safety instructions.')
  if (has(/whale shark/i)) safety.push('Whale shark sightings are never guaranteed; follow the interaction rules the operator gives you.')
  if (has(/surf/i)) safety.push('Surf only within your level, ideally with a local instructor.')
  if (has(/swimwear/i)) safety.push('Swim only where it is allowed and safe, and never alone.')
  safety.push('Save the local emergency numbers and tell someone your plans for the day.')

  const notes = []
  if (items.some((item) => Number(item.cost_max) > 0 && costNoteIsEstimate(item.cost_note))) {
    notes.push('Some prices are estimates from typical ranges, not rates confirmed by the places themselves.')
  }
  if (has(/hours shown are typical|Opening hours aren't listed|isn't listed|doesn't list a check-in/i)) {
    notes.push('Some opening or check-in hours are typical or not listed: confirm them before you go.')
  }
  if (publicTransport || has(/min by road\)/)) {
    notes.push(publicTransport
      ? 'Travel times are public-transport estimates, including waiting and transfers; real times depend on routes and schedules.'
      : 'Some travel times are estimated from the map distance, so allow extra time.')
  }
  if (has(/Coast Guard|whale shark|Surfing conditions|Firefly watching depends/i)) {
    notes.push('Weather- and season-dependent activities can be cancelled on the day; the plan never guarantees them.')
  }
  notes.push('Meals are planned at fixed times — breakfast by 8:00 AM, lunch 11:30 AM – 12:30 PM and dinner 6:00 – 7:30 PM — so the day never runs late on an empty stomach.')

  return { bring, arrange, safety, notes }
}

function Section({ icon: Icon, title, items }) {
  if (items.length === 0) return null
  return (
    <div>
      <p className="flex items-center gap-1.5 text-sm font-semibold text-gray-900">
        <Icon size={15} className="text-primary-600" aria-hidden="true" /> {title}
      </p>
      <ul className="mt-1.5 space-y-1 text-sm text-gray-600 list-disc pl-5">
        {items.map((item) => <li key={item}>{item}</li>)}
      </ul>
    </div>
  )
}

export default function TripReminders({ itinerary }) {
  const { bring, arrange, safety, notes } = tripReminders(itinerary)
  return (
    <div className="bg-white rounded-xl p-4 sm:p-6 border border-gray-100 mb-6">
      <p className="text-primary-600 font-medium mb-4">Before you go</p>
      <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
        <Section icon={FiCheckSquare} title="What to bring" items={bring} />
        <Section icon={FiCheckSquare} title="Arrange ahead" items={arrange} />
        <Section icon={FiAlertCircle} title="Safety" items={safety} />
        <Section icon={FiInfo} title="Important notes" items={notes} />
      </div>
    </div>
  )
}
