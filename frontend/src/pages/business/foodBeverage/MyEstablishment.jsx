
import { useEffect, useMemo, useRef, useState } from 'react'
import {
  FiUpload,
  FiCheckCircle,
  FiClock,
  FiAlertCircle,
  FiImage,
} from 'react-icons/fi'
import {
  getMyFoodBeverage,
  updateMyFoodBeverage,
  updateMyFoodBeverageLocation,
  submitFoodBeverageForReview,
} from '../../../api/foodBeverageOwner'
import { getImageUrl } from '../../../utils/helpers'
import LoadingSpinner from '../../../components/common/LoadingSpinner'
import AccountProfileCard from '../../../components/business/AccountProfileCard'
import LocationCard from '../../../components/business/LocationCard'
import ListingPhotoManager from '../../../components/business/ListingPhotoManager'
import usePhotoArrangement from '../../../components/business/usePhotoArrangement'
import toast from 'react-hot-toast'
import { showApiError } from '../../../utils/apiErrors'
import useFormErrors from '../../../hooks/useFormErrors'
import FieldError, { FormErrorSummary } from '../../../components/common/FieldError'

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
  submitted: 'Under Review',
  needs_revision: 'Needs Revision',
  published: 'Published',
}

function getEditableData(data) {
  const proposed = data?.owner_revision?.proposed_data || {}

  return {
    name: proposed.name ?? data?.name ?? '',
    description: proposed.description ?? data?.description ?? '',
    website: proposed.website ?? data?.website ?? '',
  }
}

export default function MyEstablishment() {
  const [foodBeverage, setFoodBeverage] = useState(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [newImages, setNewImages] = useState([])
  const [form, setForm] = useState({
    name: '',
    description: '',
    website: '',
  })
  // Field errors (the listing's values are already called `form`).
  const formRef = useRef(null)
  const fieldErrors = useFormErrors(formRef, {
    validate: () => ({
      name: !form.name.trim() ? 'Please enter the business name.' : null,
      description: form.description.trim().length < 50 ? 'The description must contain at least 50 characters.' : null,
    }),
  })

  const statusRef = useRef(null)

  const isPublished = foodBeverage?.status === 'approved'
  const revision = isPublished ? foodBeverage?.owner_revision : null
  const reviewStatus = revision?.status
    || foodBeverage?.publication_review_status
    || (isPublished ? 'published' : 'draft')

  const locked = reviewStatus === 'submitted'
  const busy = saving

  const savedImages = useMemo(() => {
    if (foodBeverage?.status === 'approved'
        && foodBeverage?.owner_revision?.proposed_gallery) {
      return foodBeverage.owner_revision.proposed_gallery.map(
        (image, index) => ({
          ...image,
          sort_order: index,
        })
      )
    }

    return [...(foodBeverage?.images || [])].sort(
      (a, b) =>
        (a.sort_order ?? 0) - (b.sort_order ?? 0)
        || a.id - b.id
    )
  }, [foodBeverage])

  const savedCount = savedImages.length

  // Cover, order and removals stay local until the owner saves.
  const photos = usePhotoArrangement(savedImages, newImages)
  const totalCount = photos.items.length
  const remainingSlots = MAX_IMAGES - totalCount

  const canSubmit =
    !busy &&
    !locked &&
    totalCount >= MIN_IMAGES &&
    totalCount <= MAX_IMAGES

  const applyData = (data) => {
    setFoodBeverage(data)
    setForm(getEditableData(data))
  }

  const load = async () => {
    const data = await getMyFoodBeverage()
    applyData(data)
    return data
  }

  useEffect(() => {
    let active = true

    getMyFoodBeverage()
      .then((data) => {
        if (active) applyData(data)
      })
      .catch((error) => {
        if (active) showApiError(error, 'Failed to load establishment details.')
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
      await load()
    } catch {
      toast.error('Unable to refresh establishment details.')
    }
  }

  const scrollToStatus = () => {
    statusRef.current?.scrollIntoView({
      behavior: 'smooth',
      block: 'center',
    })
  }

  const handleSelectImages = (event) => {
    const files = Array.from(event.target.files || [])
    event.target.value = ''

    if (!files.length) return

    if (files.some((file) => ![
      'image/jpeg',
      'image/png',
      'image/webp',
    ].includes(file.type))) {
      fieldErrors.setErrors({ images: 'Only JPG, JPEG, PNG, and WebP photos are allowed.' })
      return
    }

    if (files.some((file) => file.size > MAX_FILE_SIZE)) {
      fieldErrors.setErrors({ images: 'Each photo must be 5 MB or smaller.' })
      return
    }

    if (totalCount + files.length > MAX_IMAGES) {
      fieldErrors.setErrors({ images: 'Gallery cannot exceed 100 photos.' })
      return
    }

    fieldErrors.clear('images')
    setNewImages((current) => [...current, ...files])
  }

  const handleRemoveNew = (file) => {
    setNewImages((current) => current.filter((item) => item !== file))
  }

  // Published listing only: a declared spelling/formatting fix to the name or
  // description. The server publishes it only if it really is minor; otherwise it
  // is saved to the pending draft for review.
  const handleMinorFix = async () => {
    if (busy || locked) return

    const payload = new FormData()
    payload.append('name', form.name.trim())
    payload.append('description', form.description.trim())
    payload.append('minor_edit', '1')

    setSaving(true)
    try {
      const response = await updateMyFoodBeverage(payload)
      toast.success(response.message || 'Correction saved.')
      await refresh()
    } catch (error) {
      if (!fieldErrors.fromResponse(error)) showApiError(error, 'Failed to save the correction.', { allErrors: true })
    } finally {
      setSaving(false)
    }
  }

  const handleSaveAndSubmit = async (event, submit = true) => {
    event?.preventDefault()

    if (busy || locked) return

    const photoProblem = totalCount > MAX_IMAGES || (submit && !canSubmit)
      ? 'You need 5–100 photos and an editable listing.'
      : null
    if (!fieldErrors.check({ images: photoProblem })) return

    const payload = new FormData()
    payload.append('name', form.name.trim())
    payload.append('description', form.description.trim())
    payload.append('website', form.website.trim())

    newImages.forEach((file) => {
      payload.append('images[]', file)
    })
    photos.appendTo(payload)

    setSaving(true)

    try {
      await updateMyFoodBeverage(payload)
      setNewImages([])
      photos.reset()

      if (!submit) {
        toast.success(isPublished
          ? 'Draft saved. Your published listing stays unchanged until you submit and it is approved.'
          : 'Draft saved.')
        await refresh()
        return
      }

      const response = await submitFoodBeverageForReview()

      toast.success(
        response.message || 'Changes submitted for review.'
      )

      await refresh()
      scrollToStatus()
    } catch (error) {
      if (!fieldErrors.fromResponse(error)) showApiError(error, 'Could not complete Save & Submit for Review.', { allErrors: true })

      // Refresh because saving may have succeeded even if
      // the subsequent submission failed.
      await refresh()
    } finally {
      setSaving(false)
    }
  }

  const handleLocationSave = async (data) => {
    if (locked) {
      throw new Error('Changes are locked while under review.')
    }

    // A failure reaches LocationCard, which shows it under its fields.
    const response = await updateMyFoodBeverageLocation(data)
    await refresh()
    return response
  }

  if (loading) return <LoadingSpinner size="lg" />

  if (!foodBeverage) {
    return (
      <div className="bg-white rounded-xl border p-8 text-center">
        <FiAlertCircle size={32} className="mx-auto mb-3 text-gray-400" />
        <p className="text-gray-600">
          Establishment details are unavailable.
        </p>
        <button
          type="button"
          className="btn-primary mt-4"
          onClick={async () => {
            setLoading(true)
            try {
              await load()
            } catch (error) {
              showApiError(error, 'Failed to load establishment.')
            } finally {
              setLoading(false)
            }
          }}
        >
          Try Again
        </button>
      </div>
    )
  }

  return (
    <div>
      <div className="flex items-center justify-between flex-wrap gap-3 mb-8">
        <div>
          <p className="text-xs font-semibold uppercase tracking-wider text-secondary-500 mb-2">
            Food &amp; Beverage Owner Portal
          </p>
          <h1 className="text-2xl sm:text-3xl font-heading font-bold text-gray-900">
            Profile &amp; Establishment
          </h1>
        </div>

        <div className="flex flex-wrap gap-2">
          <span className={`text-xs font-medium px-3 py-1.5 rounded-full ${
            STATUS_STYLES[foodBeverage.status] || STATUS_STYLES.archived
          }`}>
            {foodBeverage.status}
          </span>
          <span className={`text-xs font-medium px-3 py-1.5 rounded-full ${
            REVIEW_STYLES[reviewStatus] || REVIEW_STYLES.draft
          }`}>
            {REVIEW_LABELS[reviewStatus] || reviewStatus}
          </span>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div className="lg:col-span-1 space-y-6">
          <AccountProfileCard />

          <LocationCard
            address={
              revision?.proposed_data?.address
              ?? foodBeverage.address
            }
            municipality={foodBeverage.municipality}
            municipalityId={
              revision?.proposed_data?.municipality_id
              ?? foodBeverage.municipality_id
            }
            onSave={handleLocationSave}
          />

          {revision && (
            <p className="text-xs text-gray-500 bg-white rounded-lg p-3 border">
              Location changes are saved to the proposed revision.
              The published location stays unchanged until approval.
            </p>
          )}
        </div>

        <div className="lg:col-span-2 space-y-6">
          <div
            ref={statusRef}
            className="bg-white rounded-2xl p-4 sm:p-6 border border-gray-200/70 shadow-(--shadow-card)"
          >
            <div className="flex items-center gap-3 mb-3">
              {reviewStatus === 'published' ? (
                <FiCheckCircle className="text-green-600" size={22} />
              ) : reviewStatus === 'submitted' ? (
                <FiClock className="text-blue-600" size={22} />
              ) : (
                <FiAlertCircle className="text-amber-600" size={22} />
              )}
              <h2 className="text-lg font-heading font-semibold text-gray-900">
                Publication Status
              </h2>
            </div>

            {isPublished && (
              <p className="text-sm text-green-700 mb-3">
                Your approved establishment remains published while
                proposed changes are reviewed.
              </p>
            )}

            {reviewStatus === 'published' && (
              <p className="text-sm text-gray-600">
                Your establishment is published. You may submit
                proposed updates without unpublishing it.
              </p>
            )}

            {reviewStatus === 'draft' && (
              <p className="text-sm text-gray-600">
                Complete the establishment details and gallery,
                then use Save &amp; Submit for Review.
              </p>
            )}

            {reviewStatus === 'submitted' && (
              <p className="text-sm text-blue-700">
                Your changes are under Tourism Office review.
                Editing is temporarily locked until a decision is made.
              </p>
            )}

            {reviewStatus === 'needs_revision' && (
              <div>
                <p className="text-sm text-gray-600">
                  The Tourism Office requested corrections.
                  Update the draft and submit it again.
                </p>

                {(revision?.admin_notes || foodBeverage.admin_notes) && (
                  <p className="text-sm text-red-700 mt-3 bg-red-50 rounded-lg p-3">
                    <strong>Tourism Office Feedback:</strong>{' '}
                    {revision?.admin_notes || foodBeverage.admin_notes}
                  </p>
                )}
              </div>
            )}

            <div className="mt-4 pt-4 border-t border-gray-100">
              <div className="flex justify-between text-sm mb-2">
                <span>Saved gallery photos</span>
                <strong>{savedCount} / {MIN_IMAGES} minimum</strong>
              </div>
              <div className="w-full h-2 bg-gray-100 rounded-full overflow-hidden">
                <div
                  className={`h-full ${
                    savedCount >= MIN_IMAGES
                      ? 'bg-green-500'
                      : 'bg-primary-500'
                  }`}
                  style={{
                    width: `${Math.min(
                      100,
                      (savedCount / MIN_IMAGES) * 100
                    )}%`,
                  }}
                />
              </div>
            </div>
          </div>

          <form
            ref={formRef}
            noValidate
            onChange={fieldErrors.onChange}
            onSubmit={handleSaveAndSubmit}
            className="bg-white rounded-2xl p-4 sm:p-6 border border-gray-200/70 space-y-6 shadow-(--shadow-card)"
          >
            <h2 className="text-lg font-heading font-semibold text-gray-900">
              Establishment Details
            </h2>
            <FormErrorSummary form={fieldErrors} />

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Business Name
              </label>
              <input name="name" maxLength={255}
                className="input-field"
                value={form.name}
                onChange={(event) =>
                  setForm((current) => ({
                    ...current,
                    name: event.target.value,
                  }))
                }
                required
                maxLength={255}
                disabled={locked || busy}
              />
              <FieldError form={fieldErrors} name="name" />
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Description
              </label>
              <textarea name="description"
                rows={6}
                className="input-field"
                value={form.description}
                onChange={(event) =>
                  setForm((current) => ({
                    ...current,
                    description: event.target.value,
                  }))
                }
                required
                minLength={50}
                disabled={locked || busy}
              />
              <FieldError form={fieldErrors} name="description" />
              <p className="text-xs text-gray-500 mt-1">
                Minimum 50 characters.
              </p>
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Website (optional)
              </label>
              <input name="website" maxLength={500}
                type="url"
                className="input-field"
                placeholder="https://..."
                value={form.website}
                onChange={(event) =>
                  setForm((current) => ({
                    ...current,
                    website: event.target.value,
                  }))
                }
                disabled={locked || busy}
              />
              <FieldError form={fieldErrors} name="website" />
            </div>

            <div>
              <div className="flex items-center justify-between flex-wrap gap-3 mb-3">
                <div>
                  <h3 className="text-sm font-semibold text-gray-800">
                    Storefront / Interior Photo Gallery
                  </h3>
                  <p className="text-xs text-gray-500 mt-1">
                    Minimum 5 photos, maximum 100 photos.
                  </p>
                </div>
                <span className="text-sm font-semibold bg-gray-100 px-3 py-1 rounded-full">
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
                disabled={busy || locked}
                label="Establishment photo"
              />

              {totalCount < MAX_IMAGES && (
                <label className={`flex items-center gap-3 border border-dashed rounded-lg px-4 py-4 ${
                  locked || busy
                    ? 'opacity-50 cursor-not-allowed'
                    : 'cursor-pointer hover:bg-primary-50/30'
                }`}>
                  <FiUpload size={20} className="text-gray-400" />

                  <div>
                    <span className="block text-sm font-medium">
                      Add Establishment Photos
                    </span>
                    <span className="block text-xs text-gray-500">
                      JPG, PNG, WebP • Up to 5 MB each •{' '}
                      {remainingSlots} slots remaining
                    </span>
                  </div>

                  <input
                    type="file"
                    multiple
                    accept="image/jpeg,image/png,image/webp"
                    name="images"
                    onChange={handleSelectImages}
                    disabled={locked || busy}
                    className="hidden"
                  />
                </label>
              )}
              <FieldError form={fieldErrors} name="images" />
            </div>

            {isPublished && (
              <p className="text-xs text-green-700 bg-green-50 p-3 rounded-lg">
                Your published restaurant will remain visible while
                your proposed changes are reviewed.
              </p>
            )}

            <button
              type="submit"
              disabled={!canSubmit}
              className="btn-primary disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {saving
                ? 'Saving and submitting...'
                : locked
                  ? 'Under Review'
                  : 'Save & Submit for Review'}
            </button>

            {!locked && (
              <button
                type="button"
                onClick={() => handleSaveAndSubmit(null, false)}
                disabled={busy}
                className="ml-0 sm:ml-3 inline-flex items-center justify-center min-h-[46px] px-5 py-3 text-sm font-semibold rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50 disabled:opacity-50"
              >
                Save Draft
              </button>
            )}

            {isPublished && !locked && (
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

            {!locked && totalCount < MIN_IMAGES && (
              <p className="text-xs text-amber-700">
                Add {MIN_IMAGES - totalCount} more photo(s)
                before submitting.
              </p>
            )}
          </form>
        </div>
      </div>
    </div>
  )
}
