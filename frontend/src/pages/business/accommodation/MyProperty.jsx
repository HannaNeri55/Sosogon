
import { useEffect, useMemo, useRef, useState } from 'react'
import {
  FiUpload,
  FiCheckCircle,
  FiClock,
  FiAlertCircle,
  FiImage,
  FiSend,
} from 'react-icons/fi'
import {
  getMyAccommodation,
  updateMyAccommodation,
  updateMyAccommodationLocation,
  submitAccommodationForReview,
} from '../../../api/accommodationOwner'
import ListingPhotoManager from '../../../components/business/ListingPhotoManager'
import usePhotoArrangement from '../../../components/business/usePhotoArrangement'
import { getImageUrl } from '../../../utils/helpers'
import LoadingSpinner from '../../../components/common/LoadingSpinner'
import AccountProfileCard from '../../../components/business/AccountProfileCard'
import LocationCard from '../../../components/business/LocationCard'
import toast from 'react-hot-toast'
import AmenityPicker from '../../../components/common/AmenityPicker'
import { CLASSIFICATIONS, OFFERING_ARRANGEMENTS } from '../../../utils/accommodationOptions'
import { getErrorMessage, showApiError } from '../../../utils/apiErrors'
import { confirmDialog } from '../../../utils/dialogs'

const MIN_IMAGES = 5
const MAX_IMAGES = 100
const MAX_FILE_SIZE = 5 * 1024 * 1024

const STATUS_STYLES = {
  approved: 'bg-green-100 text-green-700',
  pending: 'bg-yellow-100 text-yellow-700',
  rejected: 'bg-red-100 text-red-700',
  archived: 'bg-gray-100 text-gray-700',
}

const REVIEW_STYLES = {
  draft: 'bg-gray-100 text-gray-700',
  submitted: 'bg-blue-100 text-blue-700',
  needs_revision: 'bg-red-100 text-red-700',
  published: 'bg-green-100 text-green-700',
}

const REVIEW_LABELS = {
  draft: 'Draft',
  submitted: 'Submitted for Review',
  needs_revision: 'Needs Revision',
  published: 'Published',
}

const EMPTY_FORM = {
  name: '',
  description: '',
  contact_number: '',
  email: '',
  website: '',
  operating_hours: '',
  price_range: '',
  amenities: [],
  offering_arrangement: '',
  classifications: [],
  check_in_time: '',
  check_out_time: '',
}

function getReviewStatus(property) {
  if (property?.owner_revision?.status) {
    return property.owner_revision.status
  }

  return property?.publication_review_status ||
    (property?.status === 'approved' ? 'published' : 'draft')
}

function getEditableData(property) {
  const proposed = property?.owner_revision?.proposed_data || {}

  const value = (key) => proposed[key] ?? property?.[key] ?? ''

  return {
    name: value('name'),
    description: value('description'),
    contact_number: value('contact_number'),
    email: value('email'),
    website: value('website'),
    operating_hours: value('operating_hours'),
    price_range: value('price_range'),
    amenities: Array.isArray(
      proposed.amenities ?? property?.amenities
    )
      ? proposed.amenities ?? property.amenities
      : [],
    offering_arrangement: value('offering_arrangement'),
    classifications: Array.isArray(proposed.classifications ?? property?.classifications)
      ? proposed.classifications ?? property.classifications
      : [],
    check_in_time: value('check_in_time'),
    check_out_time: value('check_out_time'),
  }
}

function sortImages(property) {
  const revisionGallery = property?.owner_revision?.proposed_gallery

  if (Array.isArray(revisionGallery)) {
    return revisionGallery.map((image, index) => ({
      ...image,
      sort_order: index,
    }))
  }

  return [...(property?.images || [])].sort(
    (a, b) =>
      (a.sort_order ?? 0) - (b.sort_order ?? 0) ||
      a.id - b.id
  )
}

export default function MyProperty() {
  const publicationStatusRef = useRef(null)

  const [accommodation, setAccommodation] = useState(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [submittingReview, setSubmittingReview] = useState(false)
  const [form, setForm] = useState(EMPTY_FORM)
  const [newImages, setNewImages] = useState([])

  const savedImages = useMemo(
    () => sortImages(accommodation),
    [accommodation]
  )

  // Photos already saved (the published gallery, or the pending revision's for a published listing).
  const savedCount = savedImages.length

  // Cover, order and removals stay local until the owner saves.
  const photos = usePhotoArrangement(savedImages, newImages)
  const totalCount = photos.items.length
  const remainingSlots = Math.max(0, MAX_IMAGES - totalCount)

  const reviewStatus = getReviewStatus(accommodation)
  const isPublished = accommodation?.status === 'approved'
  const isSubmitted = reviewStatus === 'submitted'
  const busy = saving || submittingReview

  const canSaveAndSubmit =
    !isSubmitted &&
    !busy &&
    totalCount >= MIN_IMAGES &&
    totalCount <= MAX_IMAGES

  const updateField = (field, value) => {
    setForm((current) => ({
      ...current,
      [field]: value,
    }))
  }

  const load = async () => {
    const data = await getMyAccommodation()

    setAccommodation(data)
    setForm(getEditableData(data))

    return data
  }

  useEffect(() => {
    let active = true

    getMyAccommodation()
      .then((data) => {
        if (!active) return

        setAccommodation(data)
        setForm(getEditableData(data))
      })
      .catch((error) => {
        if (active) {
          showApiError(error, 'Failed to load property details.')
        }
      })
      .finally(() => {
        if (active) setLoading(false)
      })

    return () => {
      active = false
    }
  }, [])

  const refresh = async () => {
    try {
      return await load()
    } catch {
      toast.error(
        'Property could not be refreshed. Please reload the page.'
      )
      return null
    }
  }

  const handleSelectImages = (event) => {
    if (isSubmitted || busy) return

    const files = Array.from(event.target.files || [])
    event.target.value = ''

    if (!files.length) return

    const allowedTypes = [
      'image/jpeg',
      'image/png',
      'image/webp',
    ]

    if (files.some((file) => !allowedTypes.includes(file.type))) {
      toast.error('Only JPG, JPEG, PNG, and WebP photos are allowed.')
      return
    }

    if (files.some((file) => file.size > MAX_FILE_SIZE)) {
      toast.error('Each photo must be 5 MB or smaller.')
      return
    }

    if (totalCount + files.length > MAX_IMAGES) {
      toast.error(`You can only have up to ${MAX_IMAGES} gallery photos.`)
      return
    }

    setNewImages((current) => [...current, ...files])
  }

  const handleRemoveNew = (file) => {
    setNewImages((current) => current.filter((item) => item !== file))
  }

  const handleLocationSave = async (data) => {
    if (isSubmitted) {
      toast.error(
        'Please wait for the Tourism Office review before editing the location.'
      )
      throw new Error('Property is under review.')
    }

    try {
      const response = await updateMyAccommodationLocation(data)
      await refresh()
      return response
    } catch (err) {
      showApiError(err, 'Failed to update location.', { allErrors: true })
      throw err
    }
  }

  // Published listing only: a declared spelling/formatting fix to the name or
  // description. The server publishes it only if it really is minor; otherwise it
  // is saved to the pending draft for review.
  const handleMinorFix = async () => {
    if (busy || isSubmitted) return

    const payload = new FormData()
    payload.append('name', form.name.trim())
    payload.append('description', form.description.trim())
    payload.append('minor_edit', '1')

    setSaving(true)
    try {
      const response = await updateMyAccommodation(payload)
      toast.success(response.message || 'Correction saved.')
      await refresh()
    } catch (err) {
      showApiError(err, 'Failed to save the correction.', { allErrors: true })
    } finally {
      setSaving(false)
    }
  }

  const handleSaveAndSubmit = async (submit = true) => {
    if (busy || isSubmitted) return

    if (!form.name.trim()) {
      toast.error('Please enter the establishment name.')
      return
    }

    if (form.description.trim().length < 50) {
      toast.error('Description must contain at least 50 characters.')
      return
    }

    if (form.email.trim()) {
      const validEmail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

      if (!validEmail.test(form.email.trim())) {
        toast.error('Please enter a valid email address.')
        return
      }
    }

    if (form.website.trim()) {
      try {
        const url = new URL(form.website.trim())

        if (!['http:', 'https:'].includes(url.protocol)) {
          throw new Error('Invalid protocol.')
        }
      } catch {
        toast.error('Please enter a valid website URL.')
        return
      }
    }

    if (totalCount > MAX_IMAGES || (submit && totalCount < MIN_IMAGES)) {
      toast.error(
        `Please provide between ${MIN_IMAGES} and ${MAX_IMAGES} photos.`
      )
      return
    }

    const confirmationMessage = isPublished
      ? 'Submit your updated property details and photos for Tourism Office approval? Your currently published listing will remain visible until the changes are approved.'
      : 'Save your property details and photos, then submit the completed listing to the Tourism Office for review?'

    if (submit && !(await confirmDialog({ title: 'Submit for review?', message: confirmationMessage, confirmLabel: 'Submit for review' }))) return

    const payload = new FormData()

    payload.append('name', form.name.trim())
    payload.append('description', form.description.trim())
    payload.append('contact_number', form.contact_number.trim())
    payload.append('email', form.email.trim())
    payload.append('website', form.website.trim())
    payload.append('operating_hours', form.operating_hours.trim())

    if (form.price_range) {
      payload.append('price_range', form.price_range)
    } else {
      payload.append('price_range', '')
    }

    // Explicitly submit an empty array when all amenities are unchecked.
    if (form.amenities.length === 0) {
      payload.append('amenities', '')
    } else {
      form.amenities.forEach((amenity) => {
        payload.append('amenities[]', amenity)
      })
    }

    payload.append('offering_arrangement', form.offering_arrangement)
    payload.append('check_in_time', form.check_in_time.trim())
    payload.append('check_out_time', form.check_out_time.trim())
    if (form.classifications.length === 0) {
      payload.append('classifications', '')
    } else {
      form.classifications.forEach((name) => payload.append('classifications[]', name))
    }

    newImages.forEach((file) => {
      payload.append('images[]', file)
    })
    photos.appendTo(payload)

    setSaving(true)

    try {
      // STEP 1: Save details and proposed gallery (with the chosen cover and order).
      await updateMyAccommodation(payload)
      setNewImages([])
      photos.reset()

      if (!submit) {
        toast.success(isPublished
          ? 'Draft saved. Your published listing stays unchanged until you submit and it is approved.'
          : 'Draft saved.')
        await refresh()
        return
      }

      // STEP 2: Reload the saved property.
      let updatedProperty

      try {
        updatedProperty = await load()
      } catch {
        toast.error(
          'Changes may have been saved, but the latest property could not be verified. Please refresh the page.'
        )
        return
      }

      const updatedImages = sortImages(updatedProperty)

      if (
        updatedImages.length < MIN_IMAGES ||
        updatedImages.length > MAX_IMAGES
      ) {
        toast.error(
          'Changes saved, but the gallery does not meet the 5–100 photo requirement.'
        )
        return
      }

      const updatedReviewStatus = getReviewStatus(updatedProperty)

      if (updatedReviewStatus === 'submitted') {
        toast.success('Your changes are already under review.')
        return
      }

      if (
        !['draft', 'needs_revision', 'published'].includes(
          updatedReviewStatus
        )
      ) {
        toast.error(
          'The current review status does not allow submission.'
        )
        return
      }

      // For approved properties, saving should have created a draft revision.
      if (
        updatedProperty.status === 'approved' &&
        !updatedProperty.owner_revision
      ) {
        toast.error(
          'The revision was not found after saving. Please refresh before submitting.'
        )
        return
      }

      // STEP 3: Submit to Tourism Office.
      setSubmittingReview(true)

      try {
        const response = await submitAccommodationForReview()

        toast.success(
          response.message ||
          'Property saved and submitted for review successfully.'
        )

        // STEP 4: Verify review status.
        const refreshedProperty = await refresh()

        if (
          refreshedProperty &&
          getReviewStatus(refreshedProperty) === 'submitted'
        ) {
          requestAnimationFrame(() => {
            publicationStatusRef.current?.scrollIntoView({
              behavior: 'smooth',
              block: 'start',
            })
          })
        }
      } catch (err) {
        toast.error(
          `Changes were saved, but submission failed: ${getErrorMessage(err, 'Please try again.', { allErrors: true })}`
        )
      } finally {
        setSubmittingReview(false)
      }
    } catch (err) {
      showApiError(err, 'Failed to save property. Nothing was submitted.', { allErrors: true })
    } finally {
      setSaving(false)
    }
  }

  if (loading) return <LoadingSpinner size="lg" />

  if (!accommodation) {
    return (
      <div className="bg-white rounded-2xl border border-gray-200/70 p-8 text-center shadow-(--shadow-card)">
        <FiAlertCircle
          size={32}
          className="mx-auto mb-3 text-gray-400"
        />

        <p className="text-gray-600">
          Property details are unavailable.
        </p>

        <button
          type="button"
          onClick={() => {
            setLoading(true)

            load()
              .catch((error) => showApiError(error, 'Failed to load property.'))
              .finally(() => setLoading(false))
          }}
          className="btn-primary mt-4"
        >
          Try Again
        </button>
      </div>
    )
  }

  const revisionNotes =
    accommodation.owner_revision?.admin_notes ||
    accommodation.admin_notes

  return (
    <div>
      {/* PAGE HEADER */}
      <div className="flex items-center justify-between flex-wrap gap-3 mb-8">
        <div>
          <p className="text-xs font-semibold uppercase tracking-wider text-secondary-500 mb-2">
            Accommodation Owner Portal
          </p>

          <h1 className="text-2xl sm:text-3xl font-heading font-bold text-gray-900">
            Profile &amp; Property
          </h1>
        </div>

        <div className="flex items-center flex-wrap gap-2">
          <span
            className={`text-xs font-medium px-3 py-1.5 rounded-full capitalize ${
              STATUS_STYLES[accommodation.status] ||
              'bg-gray-100 text-gray-700'
            }`}
          >
            {accommodation.status}
          </span>

          <span
            className={`text-xs font-medium px-3 py-1.5 rounded-full ${
              REVIEW_STYLES[reviewStatus] ||
              'bg-gray-100 text-gray-700'
            }`}
          >
            {REVIEW_LABELS[reviewStatus] || reviewStatus}
          </span>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* LEFT COLUMN */}
        <div className="lg:col-span-1 space-y-6">
          <AccountProfileCard />

          <LocationCard
            address={
              accommodation.owner_revision?.proposed_data?.address ??
              accommodation.address
            }
            municipality={accommodation.municipality}
            municipalityId={
              accommodation.owner_revision?.proposed_data?.municipality_id ??
              accommodation.municipality_id
            }
            onSave={handleLocationSave}
          />
        </div>

        {/* RIGHT COLUMN */}
        <div className="lg:col-span-2 space-y-6">
          {/* PUBLICATION STATUS */}
          <div
            ref={publicationStatusRef}
            className="bg-white rounded-2xl p-4 sm:p-6 border border-gray-200/70 scroll-mt-24 shadow-(--shadow-card)"
          >
            <div className="flex items-center gap-3 mb-3">
              {reviewStatus === 'published' ? (
                <FiCheckCircle
                  className="text-green-600"
                  size={22}
                />
              ) : reviewStatus === 'submitted' ? (
                <FiClock className="text-blue-600" size={22} />
              ) : (
                <FiAlertCircle
                  className="text-amber-600"
                  size={22}
                />
              )}

              <h2 className="text-lg font-heading font-semibold text-gray-900">
                Publication Status
              </h2>
            </div>

            {isPublished && (
              <p className="text-sm text-green-700 bg-green-50 rounded-lg p-3 mb-3">
                Your approved accommodation remains visible to tourists.
                Proposed changes will only become public after Tourism
                Office approval.
              </p>
            )}

            {reviewStatus === 'published' && (
              <p className="text-sm text-gray-600">
                Your property is published. You can submit updated
                details and photos for another review without taking
                the current listing offline.
              </p>
            )}

            {reviewStatus === 'submitted' && (
              <p className="text-sm text-gray-600">
                Your changes have been submitted to the Tourism
                Office. Editing is temporarily unavailable while
                the review is in progress.
              </p>
            )}

            {reviewStatus === 'draft' && (
              <p className="text-sm text-gray-600">
                Complete your property details, provide at least
                five photos, and submit your listing for review.
              </p>
            )}

            {reviewStatus === 'needs_revision' && (
              <div>
                <p className="text-sm text-gray-600">
                  The Tourism Office requested changes to your
                  submission. Update the details and submit again.
                </p>

                {revisionNotes && (
                  <p className="text-sm text-red-700 mt-3 bg-red-50 rounded-lg p-3">
                    <strong>Tourism Office Feedback:</strong>{' '}
                    {revisionNotes}
                  </p>
                )}
              </div>
            )}

            <div className="mt-4 pt-4 border-t border-gray-100">
              <div className="flex items-center justify-between gap-3 mb-3">
                <p className="text-sm font-medium text-gray-700">
                  Gallery requirement
                </p>

                <span className="text-sm font-semibold text-gray-900">
                  {savedCount} / {MIN_IMAGES} minimum
                </span>
              </div>

              <div className="w-full h-2 bg-gray-100 rounded-full overflow-hidden">
                <div
                  className={`h-full rounded-full ${
                    savedCount >= MIN_IMAGES
                      ? 'bg-green-500'
                      : 'bg-primary-500'
                  }`}
                  style={{
                    width: `${
                      Math.min(100, (savedCount / MIN_IMAGES) * 100)
                    }%`,
                  }}
                />
              </div>

              <p className="text-xs text-gray-500 mt-2">
                {savedCount >= MIN_IMAGES
                  ? 'Minimum saved photo requirement completed.'
                  : `Add ${MIN_IMAGES - savedCount} more saved photo(s).`}
              </p>
            </div>
          </div>

          {/* PROPERTY DETAILS */}
          <form
            onSubmit={(event) => event.preventDefault()}
            className="bg-white rounded-2xl p-4 sm:p-6 border border-gray-200/70 space-y-6 shadow-(--shadow-card)"
          >
            <div>
              <h2 className="text-lg font-heading font-semibold text-gray-900">
                Property Details
              </h2>

              <p className="text-sm text-gray-500 mt-1">
                Manage your accommodation information and photo gallery.
              </p>
            </div>

            <fieldset
              disabled={isSubmitted || busy}
              className="space-y-5 disabled:opacity-70"
            >
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">
                  Establishment Name
                </label>

                <input
                  className="input-field"
                  value={form.name}
                  onChange={(event) =>
                    updateField('name', event.target.value)
                  }
                  maxLength={255}
                  required
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">
                  Description / About
                </label>

                <textarea
                  rows={6}
                  className="input-field"
                  value={form.description}
                  onChange={(event) =>
                    updateField('description', event.target.value)
                  }
                  minLength={50}
                  required
                />

                <p className="text-xs text-gray-500 mt-1">
                  Minimum 50 characters.
                </p>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-2">
                    Contact Number
                  </label>

                  <input
                    type="tel"
                    className="input-field"
                    placeholder="e.g. 09XXXXXXXXX"
                    value={form.contact_number}
                    onChange={(event) =>
                      updateField('contact_number', event.target.value)
                    }
                    maxLength={50}
                  />
                </div>

                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-2">
                    Email Address
                  </label>

                  <input
                    type="email"
                    className="input-field"
                    placeholder="business@example.com"
                    value={form.email}
                    onChange={(event) =>
                      updateField('email', event.target.value)
                    }
                    maxLength={255}
                  />
                </div>
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">
                  Website (optional)
                </label>

                <input
                  type="url"
                  className="input-field"
                  placeholder="https://example.com"
                  value={form.website}
                  onChange={(event) =>
                    updateField('website', event.target.value)
                  }
                  maxLength={500}
                />
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-2">
                    Operating Hours
                  </label>

                  <input
                    type="text"
                    className="input-field"
                    placeholder="e.g. Open 24 hours"
                    value={form.operating_hours}
                    onChange={(event) =>
                      updateField('operating_hours', event.target.value)
                    }
                    maxLength={255}
                  />
                </div>

                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-2">
                    Price Range
                  </label>

                  <select
                    className="input-field"
                    value={form.price_range}
                    onChange={(event) =>
                      updateField('price_range', event.target.value)
                    }
                  >
                    <option value="">Select price range</option>
                    <option value="budget">Budget</option>
                    <option value="moderate">Moderate</option>
                    <option value="luxury">Luxury</option>
                  </select>
                </div>
              </div>

              {/* WHAT THE PROPERTY OFFERS */}
              <div>
                <h3 className="text-sm font-semibold text-gray-800 mb-2">What do you offer?</h3>
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-2" role="radiogroup" aria-label="What do you offer?">
                  {OFFERING_ARRANGEMENTS.map((option) => (
                    <label key={option.value} className={`rounded-lg border px-3 py-2.5 cursor-pointer ${form.offering_arrangement === option.value ? 'border-primary-400 bg-primary-50' : 'border-gray-200 hover:bg-gray-50'}`}>
                      <input
                        type="radio"
                        name="offering_arrangement"
                        className="sr-only"
                        checked={form.offering_arrangement === option.value}
                        onChange={() => updateField('offering_arrangement', option.value)}
                      />
                      <span className="block text-sm font-medium text-gray-800">{option.label}</span>
                      <span className="block text-xs text-gray-500">{option.hint}</span>
                    </label>
                  ))}
                </div>
                <p className="text-xs text-gray-500 mt-2">
                  You describe each room, villa or cottage under Accommodation Options. A property rented only as a whole needs none.
                </p>
              </div>

              <div>
                <h3 className="text-sm font-semibold text-gray-800 mb-2">What kind of property is it?</h3>
                <div className="flex flex-wrap gap-2">
                  {CLASSIFICATIONS.map((name) => {
                    const selected = form.classifications.includes(name)
                    return (
                      <label key={name} className={`cursor-pointer rounded-full border px-3 py-1.5 text-sm ${selected ? 'border-primary-500 bg-primary-50 text-primary-700' : 'border-gray-200 text-gray-600 hover:bg-gray-50'}`}>
                        <input
                          type="checkbox"
                          className="sr-only"
                          checked={selected}
                          onChange={() => updateField('classifications', selected ? form.classifications.filter((c) => c !== name) : [...form.classifications, name])}
                        />
                        {name}
                      </label>
                    )
                  })}
                </div>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label htmlFor="check_in_time" className="block text-sm font-medium text-gray-700 mb-2">Check-in time (optional)</label>
                  <input id="check_in_time" className="input-field" placeholder="e.g. 2:00 PM" maxLength={50} value={form.check_in_time} onChange={(event) => updateField('check_in_time', event.target.value)} />
                </div>
                <div>
                  <label htmlFor="check_out_time" className="block text-sm font-medium text-gray-700 mb-2">Check-out time (optional)</label>
                  <input id="check_out_time" className="input-field" placeholder="e.g. 12:00 PM" maxLength={50} value={form.check_out_time} onChange={(event) => updateField('check_out_time', event.target.value)} />
                </div>
              </div>

              {/* AMENITIES */}
              <div>
                <h3 className="text-sm font-semibold text-gray-800 mb-2">
                  Property Amenities
                </h3>

                <AmenityPicker
                  scope="property"
                  value={form.amenities}
                  onChange={(amenities) => updateField('amenities', amenities)}
                  disabled={isSubmitted}
                  hint="Facilities shared by the whole property. Each room or villa lists its own under Accommodation Options."
                />
              </div>
            </fieldset>

            {/* PROPERTY PHOTO GALLERY */}
            <div>
              <div className="flex items-center justify-between flex-wrap gap-3 mb-3">
                <div>
                  <h3 className="text-sm font-semibold text-gray-800">
                    Property Photo Gallery
                  </h3>

                  <p className="text-xs text-gray-500 mt-1">
                    Minimum 5 photos. Maximum 100 photos.
                  </p>
                </div>

                <span className="text-sm font-semibold text-gray-700 bg-gray-100 rounded-full px-3 py-1">
                  <FiImage className="inline mr-1" />
                  {totalCount} / {MAX_IMAGES}
                </span>
              </div>

              {isPublished && (
                <p className="text-xs text-blue-700 bg-blue-50 rounded-lg p-3 mb-4">
                  Gallery changes are prepared for review.
                  Your existing published photos remain visible
                  until the Tourism Office approves the revision.
                </p>
              )}

              {/* SAVED + NEW PHOTOS: cover, order and removals apply on save */}
              <ListingPhotoManager
                photos={photos}
                savedImages={savedImages}
                getImageUrl={getImageUrl}
                onRemoveNew={handleRemoveNew}
                disabled={busy || isSubmitted}
                label="Property photo"
              />

              {/* UPLOAD PHOTOS */}
              {totalCount < MAX_IMAGES && !isSubmitted && (
                <label className="flex items-center gap-3 border border-dashed border-gray-300 rounded-lg px-4 py-4 cursor-pointer hover:border-primary-300 hover:bg-primary-50/30 transition-colors">
                  <FiUpload
                    className="text-gray-400 shrink-0"
                    size={20}
                  />

                  <div className="min-w-0">
                    <span className="block text-sm font-medium text-gray-700">
                      Add Property Photos
                    </span>

                    <span className="block text-xs text-gray-500 mt-1">
                      JPEG, PNG, WebP • Up to 5 MB each •{' '}
                      {remainingSlots} slot(s) remaining
                    </span>
                  </div>

                  <input
                    type="file"
                    multiple
                    accept="image/jpeg,image/png,image/webp"
                    onChange={handleSelectImages}
                    disabled={busy || isSubmitted}
                    className="hidden"
                  />
                </label>
              )}

              {totalCount >= MAX_IMAGES && (
                <p className="text-sm text-green-700 bg-green-50 p-3 rounded-lg">
                  Maximum gallery capacity reached (100 photos).
                </p>
              )}

              <p className="text-xs text-gray-500 mt-3">
                Selected photos will be uploaded when you click
                Save &amp; Submit for Review.
              </p>
            </div>

            {/* SAVE AND SUBMIT */}
            <div className="border-t border-gray-100 pt-5 space-y-3">
              {isSubmitted ? (
                <p className="text-sm text-blue-700 bg-blue-50 rounded-lg p-3">
                  Your submission is currently under Tourism
                  Office review. Please wait for the decision
                  before editing or submitting again.
                </p>
              ) : (
                <>
                  <p className="text-xs text-gray-500">
                    Your property details and photos will be saved
                    before being submitted for Tourism Office review.
                  </p>

                  <button
                    type="button"
                    onClick={() => handleSaveAndSubmit(true)}
                    disabled={!canSaveAndSubmit}
                    className="btn-primary inline-flex items-center justify-center gap-2 min-h-[46px] px-5 py-3 text-sm font-semibold disabled:opacity-50 disabled:cursor-not-allowed"
                  >
                    <FiSend size={17} />

                    {submittingReview
                      ? 'Submitting for Review...'
                      : saving
                        ? 'Saving Property...'
                        : 'Save & Submit for Review'}
                  </button>

                  <button
                    type="button"
                    onClick={() => handleSaveAndSubmit(false)}
                    disabled={busy || isSubmitted}
                    className="ml-0 sm:ml-3 inline-flex items-center justify-center min-h-[46px] px-5 py-3 text-sm font-semibold rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                  >
                    Save Draft
                  </button>

                  {isPublished && (
                    <div className="rounded-lg border border-gray-200 p-3 text-xs text-gray-600 space-y-2">
                      <p>
                        Only fixing spelling, capitalization or punctuation in the name or description?
                        Small corrections that don&apos;t change any fact are published right away.
                        Anything else is saved for Tourism Office review.
                      </p>
                      <button
                        type="button"
                        onClick={handleMinorFix}
                        disabled={busy || photos.hasChanges}
                        className="inline-flex items-center min-h-9 px-3 rounded-lg bg-gray-100 text-gray-800 font-medium hover:bg-gray-200 disabled:opacity-50"
                      >
                        Publish spelling/formatting fix
                      </button>
                      {photos.hasChanges && <p className="text-amber-700">Save or undo your photo changes first.</p>}
                    </div>
                  )}

                  {totalCount < MIN_IMAGES && (
                    <p className="text-xs text-amber-700">
                      Upload at least {MIN_IMAGES} photos to
                      enable submission.
                    </p>
                  )}
                </>
              )}
            </div>
          </form>
        </div>
      </div>
    </div>
  )
}
