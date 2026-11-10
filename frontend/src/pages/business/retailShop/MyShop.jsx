
import { useEffect, useMemo, useState, useRef } from 'react'
import {
  FiUpload,
  FiCheckCircle,
  FiClock,
  FiAlertCircle,
  FiImage,
} from 'react-icons/fi'
import {
  getMyRetailShop,
  updateMyRetailShop,
  updateMyRetailShopLocation,
} from '../../../api/retailShopOwner'
import { getImageUrl } from '../../../utils/helpers'
import LoadingSpinner from '../../../components/common/LoadingSpinner'
import AccountProfileCard from '../../../components/business/AccountProfileCard'
import LocationCard from '../../../components/business/LocationCard'
import ListingPhotoManager from '../../../components/business/ListingPhotoManager'
import usePhotoArrangement from '../../../components/business/usePhotoArrangement'
import toast from 'react-hot-toast'
import { showApiError } from '../../../utils/apiErrors'
import { confirmDialog } from '../../../utils/dialogs'
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
  submitted: 'Submitted for Review',
  needs_revision: 'Needs Revision',
  published: 'Published',
}

export default function MyShop() {
  const [shop, setShop] = useState(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [submittingReview, setSubmittingReview] = useState(false)

  const [form, setForm] = useState({
    name: '',
    description: '',
  })
  // Field errors (the listing's values are already called `form`).
  const formRef = useRef(null)
  const fieldErrors = useFormErrors(formRef, {
    validate: () => ({
      name: !form.name.trim() ? 'Please enter the business name.' : null,
      description: form.description.trim().length < 50 ? 'The description must contain at least 50 characters.' : null,
    }),
  })

  const [newImages, setNewImages] = useState([])

  const revision =
    shop?.status === 'approved' ? shop?.owner_revision : null

  const isUnderReview =
    revision?.status === 'submitted' ||
    (shop?.status !== 'approved' &&
      shop?.publication_review_status === 'submitted')

  const savedImages = useMemo(() => {
    if (shop?.status === 'approved' && revision) {
      return (revision.proposed_gallery || []).map(
        (image, index) => ({ ...image, sort_order: index })
      )
    }

    return [...(shop?.images || [])].sort(
      (a, b) =>
        (a.sort_order ?? 0) - (b.sort_order ?? 0) ||
        a.id - b.id
    )
  }, [shop, revision])

  const savedCount = savedImages.length

  // Cover, order and removals stay local until the owner saves.
  const photos = usePhotoArrangement(savedImages, newImages)
  const totalCount = photos.items.length
  const remainingSlots = Math.max(0, MAX_IMAGES - totalCount)

  const reviewStatus =
    revision?.status ||
    shop?.publication_review_status ||
    (shop?.status === 'approved' ? 'published' : 'draft')

  const canSubmitForReview =
    !isUnderReview &&
    (shop?.status !== 'approved' || Boolean(revision)) &&
    savedCount >= MIN_IMAGES &&
    savedCount <= MAX_IMAGES &&
    !photos.hasChanges &&
    !saving &&
    !submittingReview

  const load = async () => {
    const data = await getMyRetailShop()

    setShop(data)

    const proposed = data.owner_revision?.proposed_data || {}

    setForm({
      name: proposed.name ?? data.name ?? '',
      description: proposed.description ?? data.description ?? '',
    })

    return data
  }

  useEffect(() => {
    let active = true

    getMyRetailShop()
      .then((data) => {
        if (!active) return

        setShop(data)

        const proposed = data.owner_revision?.proposed_data || {}

        setForm({
          name: proposed.name ?? data.name ?? '',
          description: proposed.description ?? data.description ?? '',
        })
      })
      .catch((error) => {
        if (active) {
          showApiError(error, 'Failed to load shop details.')
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
      await load()
    } catch {
      toast.error('Changes saved, but the shop could not be refreshed.')
    }
  }

  const handleSelectImages = (event) => {
    const files = Array.from(event.target.files || [])
    event.target.value = ''

    if (!files.length) return

    const allowedTypes = [
      'image/jpeg',
      'image/png',
      'image/webp',
    ]

    const invalidType = files.find(
      (file) => !allowedTypes.includes(file.type)
    )

    if (invalidType) {
      fieldErrors.setErrors({ images: 'Only JPG, JPEG, PNG, and WebP photos are allowed.' })
      return
    }

    const oversized = files.find(
      (file) => file.size > MAX_FILE_SIZE
    )

    if (oversized) {
      fieldErrors.setErrors({ images: 'Each photo must be 5 MB or smaller.' })
      return
    }

    if (totalCount + files.length > MAX_IMAGES) {
      fieldErrors.setErrors({ images: `You can only have up to ${MAX_IMAGES} gallery photos.` })
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
    if (saving || submittingReview || isUnderReview) return

    const payload = new FormData()
    payload.append('name', form.name.trim())
    payload.append('description', form.description.trim())
    payload.append('minor_edit', '1')

    setSaving(true)
    try {
      const response = await updateMyRetailShop(payload)
      toast.success(response.message || 'Correction saved.')
      await refresh()
    } catch (error) {
      if (!fieldErrors.fromResponse(error)) showApiError(error, 'Failed to save the correction.', { allErrors: true })
    } finally {
      setSaving(false)
    }
  }

  const handleSubmit = async (event) => {
    event.preventDefault()

    if (isUnderReview) {
      toast.error('Please wait for the Tourism Office review result.')
      return
    }

    if (!fieldErrors.check({ images: totalCount > MAX_IMAGES ? 'Gallery cannot exceed 100 photos.' : null })) return

    const payload = new FormData()

    payload.append('name', form.name)
    payload.append('description', form.description)

    newImages.forEach((file) => {
      payload.append('images[]', file)
    })
    photos.appendTo(payload)

    setSaving(true)

    try {
      const response = await updateMyRetailShop(payload)

      toast.success(response.message || 'Shop changes saved.')
      setNewImages([])
      photos.reset()
      await refresh()
    } catch (error) {
      if (!fieldErrors.fromResponse(error)) showApiError(error, 'Failed to update shop.', { allErrors: true })
    } finally {
      setSaving(false)
    }
  }

  const handleLocationSave = async (data) => {
    if (isUnderReview) throw new Error('Location is under review.')
    // A failure reaches LocationCard, which shows it under its fields.
    const response = await updateMyRetailShopLocation(data)

    if (response.shop) {
      setShop(response.shop)
    }

    await refresh()

    return response
  }

  const handleSubmitForReview = async () => {
    const photoProblem = photos.hasChanges
      ? 'Save your photo changes before submitting for review.'
      : savedCount < MIN_IMAGES ? `Upload at least ${MIN_IMAGES} photos before submitting.` : null
    if (!fieldErrors.check({ images: photoProblem })) return

    if (
      !(await confirmDialog({ title: 'Submit for review?', message: 'Your saved shop changes go to the Tourism Office for review. A published shop stays visible until they are approved.', confirmLabel: 'Submit for review' }))
    ) {
      return
    }

    setSubmittingReview(true)

    try {
      const { submitRetailShopForReview } = await import(
        '../../../api/retailShopOwner'
      )

      if (typeof submitRetailShopForReview !== 'function') {
        throw new Error(
          'Submit for review API function is not configured.'
        )
      }

      const response = await submitRetailShopForReview()

      toast.success(
        response.message || 'Shop submitted for review.'
      )

      await refresh()
    } catch (error) {
      // Checks above throw plain Errors with their own message; request failures use the API's.
      if (!fieldErrors.fromResponse(error)) showApiError(
        error,
        error.isAxiosError ? 'Failed to submit shop for review.' : error.message || 'Failed to submit shop for review.',
        { allErrors: true }
      )
    } finally {
      setSubmittingReview(false)
    }
  }

  if (loading) {
    return <LoadingSpinner size="lg" />
  }

  if (!shop) {
    return (
      <div className="bg-white rounded-2xl border border-gray-200/70 p-8 text-center shadow-(--shadow-card)">
        <FiAlertCircle
          size={32}
          className="mx-auto mb-3 text-gray-400"
        />

        <p className="text-gray-600">
          Shop details are unavailable.
        </p>

        <button
          type="button"
          onClick={() => {
            setLoading(true)

            load()
              .catch((error) => showApiError(error, 'Failed to load shop.'))
              .finally(() => setLoading(false))
          }}
          className="btn-primary mt-4"
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
            Local Products Owner Portal
          </p>

          <h1 className="text-2xl sm:text-3xl font-heading font-bold text-gray-900">
            Profile &amp; Shop
          </h1>
        </div>

        <div className="flex items-center flex-wrap gap-2">
          <span
            className={`text-xs font-medium px-3 py-1.5 rounded-full capitalize ${
              STATUS_STYLES[shop.status] ||
              'bg-gray-100 text-gray-700'
            }`}
          >
            {shop.status}
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
        <div className="lg:col-span-1 space-y-6">
          <AccountProfileCard />

          <LocationCard
            address={revision?.proposed_data?.address ?? shop.address}
            municipality={shop.municipality}
            municipalityId={revision?.proposed_data?.municipality_id ?? shop.municipality_id}
            onSave={handleLocationSave}
            readOnly={isUnderReview}
          />
        </div>

        <div className="lg:col-span-2 space-y-6">
          <div className="bg-white rounded-2xl p-4 sm:p-6 border border-gray-200/70 shadow-(--shadow-card)">
            <div className="flex items-center gap-3 mb-3">
              {reviewStatus === 'published' ? (
                <FiCheckCircle
                  className="text-green-600"
                  size={22}
                />
              ) : reviewStatus === 'submitted' ? (
                <FiClock
                  className="text-blue-600"
                  size={22}
                />
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

            {reviewStatus === 'published' && (
              <p className="text-sm text-gray-600">
                Your shop is published. Saving new changes
                will require another Tourism Office review.
              </p>
            )}

            {reviewStatus === 'submitted' && (
              <p className="text-sm text-gray-600">
                Your shop has been submitted to the Tourism
                Office. Please wait for the review result.
                Saving new changes will return the listing to draft.
              </p>
            )}

            {reviewStatus === 'draft' && (
              <p className="text-sm text-gray-600">
                Your shop is currently a draft. Complete the
                details, upload at least 5 photos, save your
                changes, and submit for publication review.
              </p>
            )}

            {reviewStatus === 'needs_revision' && (
              <div>
                <p className="text-sm text-gray-600">
                  Your shop needs revisions before it can be
                  published. Update the listing and submit it again.
                </p>

                {(revision?.admin_notes || shop.admin_notes) && (
                  <p className="text-sm text-red-700 mt-3 bg-red-50 rounded-lg p-3">
                    <strong>Tourism Office Feedback:</strong>{' '}
                    {revision?.admin_notes || shop.admin_notes}
                  </p>
                )}
              </div>
            )}

            {shop.status !== 'approved' && (
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
                        Math.min(
                          100,
                          (savedCount / MIN_IMAGES) * 100
                        )
                      }%`,
                    }}
                  />
                </div>

                {savedCount < MIN_IMAGES ? (
                  <p className="text-xs text-gray-500 mt-2">
                    Add {MIN_IMAGES - savedCount} more saved
                    photo(s) to meet the minimum requirement.
                  </p>
                ) : (
                  <p className="text-xs text-green-700 mt-2">
                    Minimum photo requirement completed.
                  </p>
                )}
              </div>
            )}

            {!isUnderReview && (shop.status !== 'approved' || Boolean(revision)) && (
                <div className="mt-5">
                  <button
                    type="button"
                    onClick={handleSubmitForReview}
                    disabled={!canSubmitForReview}
                    className="btn-primary disabled:opacity-50 disabled:cursor-not-allowed"
                  >
                    {submittingReview
                      ? 'Submitting...'
                      : 'Submit for Review'}
                  </button>

                  {photos.hasChanges && (
                    <p className="text-xs text-amber-700 mt-2">
                      Save your photo changes before submitting.
                    </p>
                  )}
                </div>
              )}
          </div>

          <form
            ref={formRef}
            noValidate
            onChange={fieldErrors.onChange}
            onSubmit={handleSubmit}
            className="bg-white rounded-2xl p-4 sm:p-6 border border-gray-200/70 space-y-6 h-fit shadow-(--shadow-card)"
          >
            <h2 className="text-lg font-heading font-semibold text-gray-900">
              Shop Details
            </h2>
            <FormErrorSummary form={fieldErrors} />

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Business Name
              </label>

              <input name="name" maxLength={255}
                className="input-field"
                value={form.name}
                disabled={isUnderReview}
                onChange={(event) =>
                  setForm({
                    ...form,
                    name: event.target.value,
                  })
                }
                required
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
                disabled={isUnderReview}
                onChange={(event) =>
                  setForm({
                    ...form,
                    description: event.target.value,
                  })
                }
                required
                minLength={50}
              />
              <FieldError form={fieldErrors} name="description" />
            </div>

            <div>
              <div className="flex items-center justify-between flex-wrap gap-3 mb-3">
                <div>
                  <label className="block text-sm font-medium text-gray-700">
                    Storefront / Interior Photo Gallery
                  </label>

                  <p className="text-xs text-gray-500 mt-1">
                    Minimum 5 photos to publish. Maximum 100 photos.
                  </p>
                </div>

                <span className="text-sm font-semibold text-gray-700 bg-gray-100 rounded-full px-3 py-1">
                  <FiImage className="inline mr-1" />
                  {totalCount} / {MAX_IMAGES}
                </span>
              </div>

              {shop.status === 'approved' && (
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
                disabled={saving || submittingReview || isUnderReview}
                label="Shop photo"
              />

              {totalCount < MAX_IMAGES ? (
                <label className="flex items-center gap-3 border border-dashed border-gray-300 rounded-lg px-4 py-4 cursor-pointer hover:border-primary-300 hover:bg-primary-50/30 transition-colors">
                  <FiUpload
                    className="text-gray-400 shrink-0"
                    size={20}
                  />

                  <div className="min-w-0">
                    <span className="block text-sm font-medium text-gray-700">
                      Add Shop Photos
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
                    name="images"
                    onChange={handleSelectImages}
                    disabled={saving || submittingReview || isUnderReview}
                    className="hidden"
                  />
                </label>
              ) : (
                <p className="text-sm text-green-700 bg-green-50 p-3 rounded-lg">
                  Maximum gallery capacity reached (100 photos).
                </p>
              )}
              <FieldError form={fieldErrors} name="images" />

              <p className="text-xs text-gray-500 mt-3">
                Your selected photos will be uploaded when you
                click Save Changes.
              </p>
            </div>

            {shop.status === 'approved' && (
              <p className="text-xs text-amber-700 bg-amber-50 p-3 rounded-lg">
                Your published shop remains visible to tourists
                while proposed changes are reviewed.
                Only approved changes replace the published listing.
              </p>
            )}

            <button
              type="submit"
              className="btn-primary disabled:opacity-50"
              disabled={saving || submittingReview || isUnderReview}
            >
              {saving ? 'Saving...' : 'Save Changes'}
            </button>

            {shop.status === 'approved' && !isUnderReview && (
              <div className="rounded-lg border border-gray-200 p-3 text-xs text-gray-600 space-y-2">
                <p>
                  Only fixing spelling, capitalization or punctuation in the name or description?
                  Small corrections that don&apos;t change any fact are published right away.
                  Anything else is saved for Tourism Office review.
                </p>
                <button
                  type="button"
                  onClick={handleMinorFix}
                  disabled={saving || submittingReview || photos.hasChanges}
                  className="inline-flex items-center min-h-9 px-3 rounded-lg bg-gray-100 text-gray-800 font-medium hover:bg-gray-200 disabled:opacity-50"
                >
                  Publish spelling/formatting fix
                </button>
                {photos.hasChanges && <p className="text-amber-700">Save or undo your photo changes first.</p>}
              </div>
            )}
          </form>
        </div>
      </div>
    </div>
  )
}
