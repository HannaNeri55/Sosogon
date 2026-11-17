import { useEffect, useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { FiCheckCircle, FiCopy, FiLock, FiMapPin, FiSearch, FiSend, FiX } from 'react-icons/fi'
import toast from 'react-hot-toast'
import usePageTitle from '../../hooks/usePageTitle'
import useFormErrors from '../../hooks/useFormErrors'
import FieldError, { FormErrorSummary } from '../../components/common/FieldError'
import { useAuth } from '../../contexts/AuthContext'
import { getFeedbackOptions, submitFeedback } from '../../api/feedback'
import { getDestinations } from '../../api/destinations'
import { getAccommodations } from '../../api/accommodations'
import { getFoodBeverages } from '../../api/foodBeverages'
import { getRetailShops } from '../../api/retailShops'
import { showApiError } from '../../utils/apiErrors'

// Used until the server's list arrives (and if it can't be reached); the server checks the values.
const CATEGORIES = {
  general: 'General Feedback',
  suggestion: 'Suggestions for Improvement',
  incorrect_information: 'Incorrect Information',
  incorrect_pricing: 'Incorrect Pricing',
  incorrect_location: 'Incorrect Location',
  technical: 'Technical Issues',
  complaint: 'Complaints and Concerns',
  other: 'Other',
}
const MODULES = {
  destinations: 'Destinations',
  accommodations: 'Accommodations',
  food_beverage: 'Food & Beverage',
  shops: 'Shops',
  events: 'Events',
  trip_planner: 'Trip Planner',
  website: 'The website in general',
}

// The modules whose places can be picked, and how to look them up.
const PLACE_SEARCH = {
  destinations: { type: 'destination', label: 'destination', find: getDestinations },
  accommodations: { type: 'accommodation', label: 'accommodation', find: getAccommodations },
  food_beverage: { type: 'food_beverage', label: 'restaurant or café', find: getFoodBeverages },
  shops: { type: 'retail_shop', label: 'shop', find: getRetailShops },
}
const MODULE_OF_TYPE = Object.fromEntries(Object.entries(PLACE_SEARCH).map(([module, search]) => [search.type, module]))

const MESSAGE_MAX = 5000
const TRAP_FIELD = 'contact_preference_check'

const emptyForm = (user) => ({
  name: user?.name || '',
  email: user?.email || '',
  wants_reply: false,
  category: '',
  subject: '',
  message: '',
  module: '',
})

/**
 * Feedback & Concerns: anyone can send feedback, a suggestion or a concern, without an account
 * and without giving their name. Opened from a place's page, it is already about that place
 * (?type=destination&id=5&name=...). The sender gets a reference number; nothing is published.
 */
export default function Feedback() {
  usePageTitle('Feedback & Concerns')
  const { user } = useAuth()
  const [params] = useSearchParams()
  const formRef = useRef(null)

  const linkedType = params.get('type')
  const linkedPlace = MODULE_OF_TYPE[linkedType] && Number(params.get('id')) > 0
    ? { type: linkedType, id: Number(params.get('id')), name: params.get('name') || 'This place' }
    : null

  const [options, setOptions] = useState({ categories: CATEGORIES, modules: MODULES })
  const [values, setValues] = useState(() => ({
    ...emptyForm(user),
    category: params.get('category') in CATEGORIES ? params.get('category') : linkedPlace ? 'incorrect_information' : '',
    module: linkedPlace ? MODULE_OF_TYPE[linkedPlace.type] : '',
  }))
  const form = useFormErrors(formRef, {
    validate: () => ({
      email: values.wants_reply && !values.email.trim() ? 'Please enter your email address so we can reply.' : null,
    }),
  })
  const [place, setPlace] = useState(linkedPlace)
  const [sending, setSending] = useState(false)
  const [sent, setSent] = useState(null) // { reference, message, duplicate }

  useEffect(() => {
    getFeedbackOptions().then((data) => setOptions({ categories: data.categories, modules: data.modules })).catch(() => {})
  }, [])

  const set = (field) => (event) => {
    const value = event.target.type === 'checkbox' ? event.target.checked : event.target.value
    setValues((current) => ({ ...current, [field]: value }))
    if (field === 'module' && place && MODULE_OF_TYPE[place.type] !== value) setPlace(null)
    if (field === 'wants_reply') form.clear('email')
  }

  const handleSubmit = async (event) => {
    event.preventDefault()
    if (!form.check()) return
    setSending(true)
    try {
      const data = await submitFeedback({
        ...values,
        name: values.name.trim() || null,
        email: values.email.trim() || null,
        module: values.module || null,
        listing_type: place?.type ?? null,
        listing_id: place?.id ?? null,
        [TRAP_FIELD]: formRef.current?.elements[TRAP_FIELD]?.value || undefined,
      })
      setSent(data)
      window.scrollTo({ top: 0, behavior: 'smooth' })
    } catch (error) {
      if (!form.fromResponse(error)) showApiError(error, 'Your feedback could not be sent. Please try again.')
    } finally {
      setSending(false)
    }
  }

  const startOver = () => {
    setValues(emptyForm(user))
    setPlace(null)
    setSent(null)
  }

  if (sent) {
    return <SentNotice sent={sent} onAnother={startOver} />
  }

  const search = PLACE_SEARCH[values.module]

  return (
    <div className="bg-gray-50 min-h-screen">
      <div className="max-w-3xl mx-auto px-4 sm:px-6 pt-10 sm:pt-14 pb-16">
        <header className="mb-6">
          <p className="text-xs font-semibold uppercase tracking-[0.18em] text-primary-600">We&apos;re listening</p>
          <h1 className="mt-2 text-3xl sm:text-4xl font-heading font-semibold text-gray-900">Feedback &amp; Concerns</h1>
          <p className="mt-3 text-gray-600 leading-relaxed">
            Tell the Tourism Office what you think, suggest an improvement, or let us know if something on the site is wrong,
            like a price, opening hours or a location. Your name and email are optional.
          </p>
        </header>

        <form ref={formRef} noValidate onChange={form.onChange} onSubmit={handleSubmit} className="bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) p-5 sm:p-8 space-y-6">
          <FormErrorSummary form={form} />

          <fieldset className="space-y-5">
            <legend className="text-sm font-semibold uppercase tracking-wider text-gray-500 mb-1">Your message</legend>

            <div>
              <label htmlFor="feedback-category" className="block text-sm font-medium text-gray-700 mb-1.5">
                What is it about? <span className="text-red-500" aria-hidden="true">*</span>
              </label>
              <select id="feedback-category" name="category" required value={values.category} onChange={set('category')} className="input-field">
                <option value="">Choose a category</option>
                {Object.entries(options.categories).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
              </select>
              <FieldError form={form} name="category" />
            </div>

            <div>
              <label htmlFor="feedback-subject" className="block text-sm font-medium text-gray-700 mb-1.5">
                Subject <span className="text-red-500" aria-hidden="true">*</span>
              </label>
              <input id="feedback-subject" name="subject" required minLength={3} maxLength={150} value={values.subject} onChange={set('subject')}
                className="input-field" placeholder="For example: Entrance fee has changed" />
              <FieldError form={form} name="subject" />
            </div>

            <div>
              <label htmlFor="feedback-message" className="block text-sm font-medium text-gray-700 mb-1.5">
                Message <span className="text-red-500" aria-hidden="true">*</span>
              </label>
              <textarea id="feedback-message" name="message" required minLength={10} maxLength={MESSAGE_MAX} rows={6} value={values.message} onChange={set('message')}
                className="input-field" aria-describedby="feedback-message-hint"
                placeholder="Describe your feedback or concern. If something is wrong, tell us what the correct information is, if you know it." />
              <p id="feedback-message-hint" className="mt-1 text-right text-xs text-gray-400">{values.message.length.toLocaleString()} / {MESSAGE_MAX.toLocaleString()}</p>
              <FieldError form={form} name="message" />
            </div>
          </fieldset>

          <fieldset className="space-y-5 border-t border-gray-100 pt-6">
            <legend className="text-sm font-semibold uppercase tracking-wider text-gray-500 mb-1">Related to <span className="font-normal normal-case tracking-normal">(optional)</span></legend>

            <div>
              <label htmlFor="feedback-module" className="block text-sm font-medium text-gray-700 mb-1.5">Part of the website</label>
              <select id="feedback-module" name="module" value={values.module} onChange={set('module')} className="input-field">
                <option value="">Not specific</option>
                {Object.entries(options.modules).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
              </select>
              <FieldError form={form} name="module" />
            </div>

            {place ? (
              <div>
                <p className="block text-sm font-medium text-gray-700 mb-1.5">Place</p>
                <div className="flex items-center gap-3 rounded-xl border border-primary-200 bg-primary-50/60 px-4 py-3">
                  <FiMapPin className="shrink-0 text-primary-600" aria-hidden="true" />
                  <span className="min-w-0 flex-1 wrap-break-word text-sm font-medium text-gray-900">{place.name}</span>
                  <button type="button" onClick={() => { setPlace(null); form.clear('listing_id') }}
                    className="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-sm font-medium text-gray-600 hover:bg-white hover:text-gray-900">
                    <FiX size={14} aria-hidden="true" /> Remove
                  </button>
                </div>
                <FieldError form={form} name="listing_id" />
              </div>
            ) : search && (
              <PlacePicker key={values.module} search={search} onPick={(picked) => { setPlace(picked); form.clear('listing_id') }} />
            )}
          </fieldset>

          <fieldset className="space-y-5 border-t border-gray-100 pt-6">
            <legend className="text-sm font-semibold uppercase tracking-wider text-gray-500 mb-1">About you <span className="font-normal normal-case tracking-normal">(optional)</span></legend>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
              <div>
                <label htmlFor="feedback-name" className="block text-sm font-medium text-gray-700 mb-1.5">Name</label>
                <input id="feedback-name" name="name" maxLength={100} autoComplete="name" value={values.name} onChange={set('name')} className="input-field" />
                <FieldError form={form} name="name" />
              </div>
              <div>
                <label htmlFor="feedback-email" className="block text-sm font-medium text-gray-700 mb-1.5">
                  Email address {values.wants_reply && <span className="text-red-500" aria-hidden="true">*</span>}
                </label>
                <input id="feedback-email" name="email" type="email" maxLength={255} autoComplete="email" required={values.wants_reply}
                  value={values.email} onChange={set('email')} className="input-field" />
                <FieldError form={form} name="email" />
              </div>
            </div>

            <label className="flex items-start gap-3 text-sm text-gray-700">
              <input type="checkbox" name="wants_reply" checked={values.wants_reply} onChange={set('wants_reply')}
                className="mt-0.5 h-4 w-4 shrink-0 rounded border-gray-300 text-primary-600 focus:ring-primary-500" />
              <span>I&apos;d like a reply by email</span>
            </label>
          </fieldset>

          {/* For bots only: people never see or fill this in. */}
          <div aria-hidden="true" className="absolute left-[-9999px] h-px w-px overflow-hidden">
            <label htmlFor={TRAP_FIELD}>Leave this empty</label>
            <input id={TRAP_FIELD} name={TRAP_FIELD} type="text" tabIndex={-1} autoComplete="off" defaultValue="" />
          </div>

          <p className="flex gap-2.5 rounded-xl bg-gray-50 p-4 text-sm text-gray-600">
            <FiLock className="mt-0.5 shrink-0 text-gray-400" aria-hidden="true" />
            <span>
              Your message goes only to the Tourism Office and is never published. Your name and email are used only to reply to you.
              See our <Link to="/privacy-policy" className="font-medium text-primary-700 hover:text-primary-800 underline-offset-2 hover:underline">Privacy Policy</Link>.
            </span>
          </p>

          <div className="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-end">
            <p className="text-xs text-gray-500 sm:mr-auto"><span className="text-red-500">*</span> Required</p>
            <button type="submit" disabled={sending} className="btn-primary justify-center">
              <FiSend size={16} aria-hidden="true" /> {sending ? 'Sending…' : 'Send feedback'}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}

/** Search for a place of the chosen part of the site, by name. */
function PlacePicker({ search, onPick }) {
  const [query, setQuery] = useState('')
  // The answer for one search text; shown only while that is still the text in the box.
  const [found, setFound] = useState({ text: '', items: [], failed: false })
  const text = query.trim()
  const searching = text.length >= 2
  const answered = searching && found.text === text
  const loading = searching && !answered
  const results = answered ? found.items : []
  const failed = answered && found.failed

  useEffect(() => {
    if (!searching) return undefined
    let current = true
    const timer = setTimeout(() => {
      search.find({ q: text, per_page: 6 })
        .then((data) => current && setFound({ text, items: data.data || [], failed: false }))
        .catch(() => current && setFound({ text, items: [], failed: true }))
    }, 300)
    return () => { current = false; clearTimeout(timer) }
  }, [text, searching, search])

  return (
    <div>
      <label htmlFor="feedback-place" className="block text-sm font-medium text-gray-700 mb-1.5">Which {search.label}?</label>
      <div className="relative">
        <FiSearch className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={16} aria-hidden="true" />
        <input id="feedback-place" type="search" value={query} onChange={(e) => setQuery(e.target.value)} autoComplete="off"
          className="input-field pl-9" placeholder="Type at least 2 letters of its name" aria-describedby="feedback-place-status" />
      </div>
      <div id="feedback-place-status" aria-live="polite">
        {loading && <p className="mt-2 text-sm text-gray-500">Searching…</p>}
        {failed && !loading && <p className="mt-2 text-sm text-gray-500">The search isn&apos;t working right now. You can still name the place in your message.</p>}
        {answered && !failed && results.length === 0 && (
          <p className="mt-2 text-sm text-gray-500">No match. You can name the place in your message instead.</p>
        )}
      </div>
      {results.length > 0 && !loading && (
        <ul className="mt-2 divide-y divide-gray-100 overflow-hidden rounded-xl border border-gray-200">
          {results.map((item) => (
            <li key={item.id}>
              <button type="button" onClick={() => onPick({ type: search.type, id: item.id, name: item.name })}
                className="flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm hover:bg-primary-50 focus:bg-primary-50 focus:outline-none">
                <FiMapPin className="shrink-0 text-gray-400" aria-hidden="true" />
                <span className="min-w-0 flex-1 wrap-break-word font-medium text-gray-900">{item.name}</span>
                {item.municipality?.name && <span className="shrink-0 text-xs text-gray-500">{item.municipality.name}</span>}
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

function SentNotice({ sent, onAnother }) {
  const copy = () => {
    navigator.clipboard?.writeText(sent.reference).then(() => toast.success('Reference number copied.'), () => {})
  }

  return (
    <div className="bg-gray-50 min-h-screen px-4 py-12 sm:py-16">
      <div role="status" className="max-w-lg mx-auto bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) p-6 sm:p-8 text-center">
        <div className="w-16 h-16 rounded-full bg-primary-50 flex items-center justify-center mx-auto mb-5">
          <FiCheckCircle size={32} className="text-primary-600" aria-hidden="true" />
        </div>
        <h1 className="text-2xl font-heading font-bold text-gray-900">
          {sent.duplicate ? 'We already have your message' : 'Thank you! Your feedback was sent'}
        </h1>
        <p className="mt-3 text-gray-600 leading-relaxed">
          {sent.duplicate
            ? 'You sent this same message a moment ago, so we didn’t save it twice.'
            : 'The Tourism Office will review it. Keep your reference number in case you contact us about it.'}
        </p>
        <div className="mt-6 rounded-xl border border-dashed border-primary-300 bg-primary-50/60 px-4 py-4">
          <p className="text-xs font-semibold uppercase tracking-wider text-primary-700">Reference number</p>
          <p className="mt-1 font-mono text-xl font-bold tracking-wider text-gray-900 break-all">{sent.reference}</p>
          <button type="button" onClick={copy} className="mt-2 inline-flex items-center gap-1.5 text-sm font-medium text-primary-700 hover:text-primary-800">
            <FiCopy size={14} aria-hidden="true" /> Copy
          </button>
        </div>
        <div className="mt-8 flex flex-col gap-3 sm:flex-row sm:justify-center">
          <button type="button" onClick={onAnother} className="btn-secondary justify-center">Send another message</button>
          <Link to="/" className="btn-primary justify-center">Back to home</Link>
        </div>
      </div>
    </div>
  )
}
