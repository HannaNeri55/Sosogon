
import { useEffect, useMemo, useState } from 'react'
import {
  FiCheck,
  FiX,
  FiEye,
  FiImage,
  FiRefreshCw,
  FiMapPin,
  FiAlertCircle,
  FiChevronLeft,
  FiChevronRight,
} from 'react-icons/fi'
import toast from 'react-hot-toast'
import {
  getAdminAccommodations,
  getAdminFoodBeverages,
  getAdminRetailShops,
  getAdminAccommodation,
  getAdminFoodBeverage,
  getAdminRetailShop,
  approveAccommodationPublication,
  rejectAccommodationPublication,
  approveAccommodationRevision,
  requestAccommodationRevisionChanges,
  approveFoodBeveragePublication,
  rejectFoodBeveragePublication,
  approveFoodBeverageRevision,
  requestFoodBeverageRevisionChanges,
  approveRetailShopPublication,
  rejectRetailShopPublication,
  approveRetailShopRevision,
  requestRetailShopRevisionChanges,
} from '../../api/admin'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import SampleBadge from '../../components/common/SampleBadge'
import RevisionComparison from '../../components/admin/RevisionComparison'
import {
  getImageUrl,
  IMAGE_FALLBACK_SVG,
} from '../../utils/helpers'

const BUSINESS_TYPES = [
  { key: 'accommodation', label: 'Accommodations' },
  { key: 'food_beverage', label: 'Food & Beverage' },
  { key: 'retail_shop', label: 'Retail Shops' },
]

const REVIEW_STATUSES = [
  { key: 'submitted', label: 'For Review' },
  { key: 'draft', label: 'Drafts' },
  { key: 'needs_revision', label: 'Needs Revision' },
  { key: 'published', label: 'Published' },
  { key: 'all', label: 'All' },
]

const MIN_IMAGES = 5
const MAX_IMAGES = 100

const API = {
  accommodation: {
    list: getAdminAccommodations,
    detail: getAdminAccommodation,
    approve: approveAccommodationPublication,
    reject: rejectAccommodationPublication,
    approveRevision: approveAccommodationRevision,
    requestChanges: requestAccommodationRevisionChanges,
  },
  food_beverage: {
    list: getAdminFoodBeverages,
    detail: getAdminFoodBeverage,
    approve: approveFoodBeveragePublication,
    reject: rejectFoodBeveragePublication,
    approveRevision: approveFoodBeverageRevision,
    requestChanges: requestFoodBeverageRevisionChanges,
  },
  retail_shop: {
    list: getAdminRetailShops,
    detail: getAdminRetailShop,
    approve: approveRetailShopPublication,
    reject: rejectRetailShopPublication,
    approveRevision: approveRetailShopRevision,
    requestChanges: requestRetailShopRevisionChanges,
  },
}

const STATUS_STYLES = {
  draft: 'bg-gray-100 text-gray-700',
  submitted: 'bg-amber-100 text-amber-800',
  needs_revision: 'bg-red-100 text-red-700',
  published: 'bg-green-100 text-green-800',
}

const STATUS_LABELS = {
  draft: 'Draft',
  submitted: 'For Review',
  needs_revision: 'Needs Revision',
  published: 'Published',
}

const ACTIVE_REVISION_STATUSES = [
  'draft',
  'submitted',
  'needs_revision',
]

function getRevision(item, type) {
  if (
    !item ||
    item.status !== 'approved' ||
    !['accommodation', 'food_beverage', 'retail_shop'].includes(type)
  ) {
    return null
  }

  const direct =
    item.pending_revision ??
    item.owner_revision ??
    item.latest_revision

  if (
    direct &&
    typeof direct === 'object' &&
    ACTIVE_REVISION_STATUSES.includes(direct.status)
  ) {
    return direct
  }

  if (Array.isArray(item.revisions)) {
    const revisions = [...item.revisions]
      .filter((revision) =>
        ACTIVE_REVISION_STATUSES.includes(revision.status)
      )
      .sort((a, b) => Number(b.id) - Number(a.id))

    return revisions[0] || null
  }

  return null
}

function getReviewStatus(item, type) {
  const revision = getRevision(item, type)

  if (revision?.status) return revision.status

  if (item?.publication_review_status) {
    return item.publication_review_status
  }

  if (item?.status === 'approved') return 'published'
  if (item?.status === 'rejected') return 'needs_revision'

  return 'draft'
}

function getListingName(item) {
  return (
    item?.name ||
    item?.business_name ||
    item?.shop_name ||
    item?.title ||
    `Listing #${item?.id ?? ''}`
  )
}

function getListingImages(item) {
  return [...(item?.images || [])].sort((a, b) => {
    if (Boolean(a.is_primary) !== Boolean(b.is_primary)) {
      return a.is_primary ? -1 : 1
    }

    return Number(a.sort_order ?? 0) - Number(b.sort_order ?? 0)
  })
}

function getProposedImages(item, type) {
  const revision = getRevision(item, type)

  if (Array.isArray(revision?.proposed_gallery)) {
    return revision.proposed_gallery.map((image, index) => ({
      ...image,
      sort_order: index,
    }))
  }

  return getListingImages(item)
}

function getProposedValue(item, type, key) {
  const proposed = getRevision(item, type)?.proposed_data

  if (
    proposed &&
    Object.prototype.hasOwnProperty.call(proposed, key)
  ) {
    return proposed[key]
  }

  return item?.[key]
}

function getPhotoPath(image) {
  return (
    image?.image_path ||
    image?.path ||
    image?.url ||
    image?.image_url ||
    ''
  )
}

function getErrorMessage(error, fallback) {
  const response = error?.response?.data

  if (response?.errors) {
    const message = Object.values(response.errors)
      .flat()
      .find(Boolean)

    if (message) return message
  }

  return response?.message || error?.message || fallback
}

function extractItems(response) {
  if (Array.isArray(response)) return response
  if (Array.isArray(response?.data)) return response.data
  if (Array.isArray(response?.data?.data)) return response.data.data
  return []
}

function extractDetail(response) {
  if (response?.data && !Array.isArray(response.data)) {
    return response.data
  }

  return response
}

function formatValue(value) {
  if (Array.isArray(value)) {
    return value.length ? value.join(', ') : 'None specified'
  }

  if (value === null || value === undefined || value === '') {
    return '—'
  }

  return String(value)
}

function InformationField({ label, value }) {
  return (
    <div>
      <p className="mb-1 text-xs text-gray-500">{label}</p>
      <p className="break-words whitespace-pre-line text-sm font-medium text-gray-800">
        {formatValue(value)}
      </p>
    </div>
  )
}

function GalleryViewer({ images }) {
  const [selectedIndex, setSelectedIndex] = useState(0)

  useEffect(() => {
    setSelectedIndex(0)
  }, [images])

  if (!images.length) {
    return (
      <div className="rounded-xl border border-dashed bg-gray-50 p-8 text-center">
        <FiImage size={30} className="mx-auto mb-2 text-gray-400" />
        <p className="text-sm text-gray-600">
          No gallery photos uploaded.
        </p>
      </div>
    )
  }

  const currentIndex = Math.min(selectedIndex, images.length - 1)
  const currentImage = images[currentIndex]

  const changeImage = (direction) => {
    setSelectedIndex((current) =>
      (current + direction + images.length) % images.length
    )
  }

  return (
    <div className="space-y-3">
      <div className="relative overflow-hidden rounded-xl bg-gray-100">
        <img
          src={getImageUrl(getPhotoPath(currentImage))}
          alt={`Gallery photo ${currentIndex + 1}`}
          onError={(event) => {
            event.currentTarget.onerror = null
            event.currentTarget.src = IMAGE_FALLBACK_SVG
          }}
          className="h-56 w-full object-contain sm:h-80"
        />

        {images.length > 1 && (
          <>
            <button
              type="button"
              onClick={() => changeImage(-1)}
              aria-label="Previous photo"
              className="absolute left-3 top-1/2 -translate-y-1/2 rounded-full bg-white/90 p-2 shadow"
            >
              <FiChevronLeft size={19} />
            </button>

            <button
              type="button"
              onClick={() => changeImage(1)}
              aria-label="Next photo"
              className="absolute right-3 top-1/2 -translate-y-1/2 rounded-full bg-white/90 p-2 shadow"
            >
              <FiChevronRight size={19} />
            </button>
          </>
        )}

        <span className="absolute bottom-3 right-3 rounded-lg bg-black/70 px-3 py-1 text-xs text-white">
          {currentIndex + 1} / {images.length}
        </span>
      </div>

      <div className="flex gap-2 overflow-x-auto pb-2">
        {images.map((image, index) => (
          <button
            key={image.key ?? image.id ?? index}
            type="button"
            onClick={() => setSelectedIndex(index)}
            className={`relative shrink-0 overflow-hidden rounded-lg border-2 ${
              index === currentIndex
                ? 'border-primary-500'
                : 'border-transparent'
            }`}
          >
            <img
              src={getImageUrl(getPhotoPath(image))}
              alt={`Thumbnail ${index + 1}`}
              onError={(event) => {
                event.currentTarget.onerror = null
                event.currentTarget.src = IMAGE_FALLBACK_SVG
              }}
              className="h-16 w-20 object-cover"
            />

            {image.is_primary && (
              <span className="absolute bottom-0 inset-x-0 bg-primary-600 text-center text-[11px] text-white">
                Cover
              </span>
            )}
          </button>
        ))}
      </div>
    </div>
  )
}

function ReviewModal({
  listing,
  type,
  onClose,
  onDecision,
  busy,
}) {
  const [decision, setDecision] = useState(null)
  const [notes, setNotes] = useState('')

  const revision = useMemo(
    () => getRevision(listing, type),
    [listing, type]
  )
  const isRevision = Boolean(revision)

  const images = useMemo(
    () => getProposedImages(listing, type),
    [listing, type]
  )

  const reviewStatus = getReviewStatus(listing, type)
  const isSubmitted = reviewStatus === 'submitted'
  const hasValidGallery =
    images.length >= MIN_IMAGES &&
    images.length <= MAX_IMAGES

  const canApprove = isSubmitted && hasValidGallery
  const proposed = (key) => getProposedValue(listing, type, key)

  const confirmDecision = () => {
    if (decision === 'approve' && !canApprove) {
      toast.error('Approval requires a submitted listing with 5–100 photos.')
      return
    }

    if (decision === 'reject' && !notes.trim()) {
      toast.error('Please provide a reason for requesting changes.')
      return
    }

    onDecision(type, listing.id, decision, notes.trim(), isRevision)
  }

  const businessLabel =
    BUSINESS_TYPES.find((item) => item.key === type)?.label || 'Business'

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-3 sm:p-5"
      role="dialog"
      aria-modal="true"
      aria-label="Review business listing"
    >
      <div className="max-h-[94vh] w-full max-w-4xl overflow-y-auto rounded-2xl bg-white shadow-xl">
        <div className="sticky top-0 z-10 flex items-start justify-between gap-3 border-b bg-white p-5">
          <div>
            <p className="text-xs font-semibold uppercase tracking-wide text-primary-600">
              {isRevision
                ? `${businessLabel} Revision Review`
                : 'Listing Publication Review'}
            </p>
            <h2 className="mt-1 font-heading text-xl font-bold text-gray-900">
              {proposed('name') || getListingName(listing)}
            </h2>
            <p className="mt-1 text-sm text-gray-500">{businessLabel}</p>
          </div>

          <button
            type="button"
            onClick={onClose}
            disabled={busy}
            className="rounded-lg p-2 text-gray-500 hover:bg-gray-100"
          >
            <FiX size={20} />
          </button>
        </div>

        <div className="space-y-6 p-5 sm:p-6">
          <div className="flex flex-wrap items-center gap-3">
            <span className={`rounded-full px-3 py-1 text-xs font-semibold ${
              STATUS_STYLES[reviewStatus] || STATUS_STYLES.draft
            }`}>
              {STATUS_LABELS[reviewStatus] || reviewStatus}
            </span>

            <span className="text-sm text-gray-500">
              Listing status: {listing.status || '—'}
            </span>

            {isRevision && (
              <span className="rounded-full bg-blue-100 px-3 py-1 text-xs font-semibold text-blue-700">
                Proposed Update
              </span>
            )}
          </div>

          {isRevision && (
            <div className="rounded-lg border border-blue-100 bg-blue-50 p-4">
              <h3 className="font-semibold text-blue-900">
                Review of Published {businessLabel} Changes
              </h3>
              <p className="mt-2 text-sm text-blue-800">
                The original approved listing remains visible to
                tourists until this proposed revision is approved.
                Review the proposed information and gallery below.
              </p>
            </div>
          )}

          {isRevision && (
            <RevisionComparison listing={listing} revision={revision} />
          )}

          <section>
            <div className="mb-3 flex items-center justify-between gap-3">
              <h3 className="font-semibold text-gray-900">
                {isRevision ? 'Proposed Photo Gallery' : 'Business Photo Gallery'}
              </h3>
              <span className={`rounded-lg px-3 py-1 text-sm font-semibold ${
                hasValidGallery
                  ? 'bg-green-50 text-green-700'
                  : 'bg-amber-50 text-amber-800'
              }`}>
                {images.length} / {MAX_IMAGES} photos
              </span>
            </div>

            <GalleryViewer images={images} />

            {!hasValidGallery && (
              <p className="mt-3 flex gap-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-800">
                <FiAlertCircle className="mt-0.5 shrink-0" />
                Approval requires between 5 and 100 photos.
              </p>
            )}
          </section>

          <section className="space-y-4">
            <h3 className="font-semibold text-gray-900">
              {isRevision
                ? 'Proposed Listing Information'
                : 'Listing Information'}
            </h3>

            <div className="grid grid-cols-1 gap-4 rounded-xl bg-gray-50 p-4 sm:grid-cols-2">
              <InformationField
                label="Business Name"
                value={proposed('name') || getListingName(listing)}
              />
              <InformationField label="Owner" value={listing.user?.name} />
              <InformationField
                label="Contact Number"
                value={proposed('contact_number')}
              />
              <InformationField
                label="Email Address"
                value={proposed('email')}
              />
              <InformationField label="Website" value={proposed('website')} />
              <InformationField
                label="Operating Hours"
                value={proposed('operating_hours')}
              />
              <InformationField
                label="Price Range"
                value={proposed('price_range')}
              />
              <InformationField
                label="Municipality"
                value={
                  isRevision &&
                  revision?.proposed_data?.municipality_id &&
                  Number(revision.proposed_data.municipality_id) !==
                    Number(listing.municipality_id)
                    ? `Municipality ID: ${revision.proposed_data.municipality_id}`
                    : listing.municipality?.name
                }
              />
              <div className="sm:col-span-2">
                <InformationField
                  label="Address"
                  value={proposed('address')}
                />
              </div>

              {type === 'accommodation' && (
                <div className="sm:col-span-2">
                  <InformationField
                    label="Property Amenities"
                    value={proposed('amenities')}
                  />
                </div>
              )}
            </div>

            <div>
              <p className="mb-1 text-xs text-gray-500">Description</p>
              <p className="whitespace-pre-line text-sm leading-relaxed text-gray-700">
                {proposed('description') || 'No description provided.'}
              </p>
            </div>
          </section>

          {isRevision && (
            <section className="space-y-3">
              <h3 className="font-semibold text-gray-900">
                Currently Published Version
              </h3>
              <p className="text-sm text-gray-500">
                These details remain visible to tourists until approval.
              </p>

              <div className="grid grid-cols-1 gap-4 rounded-xl border p-4 sm:grid-cols-2">
                <InformationField
                  label="Published Business Name"
                  value={listing.name}
                />
                <InformationField
                  label="Published Contact"
                  value={listing.contact_number}
                />
                <InformationField
                  label="Published Website"
                  value={listing.website}
                />
                <InformationField
                  label="Published Price Range"
                  value={listing.price_range}
                />
                <InformationField
                  label="Published Address"
                  value={listing.address}
                />
                <div className="sm:col-span-2">
                  <InformationField
                    label="Published Description"
                    value={listing.description}
                  />
                </div>
              </div>

              <p className="text-xs text-gray-500">
                Published gallery: {getListingImages(listing).length} photos.
              </p>

              {revision.admin_notes && (
                <div className="rounded-lg bg-amber-50 p-3">
                  <p className="text-xs font-semibold text-amber-800">
                    Previous Revision Feedback
                  </p>
                  <p className="mt-1 whitespace-pre-line text-sm text-amber-800">
                    {revision.admin_notes}
                  </p>
                </div>
              )}
            </section>
          )}

          {!isRevision && listing.admin_notes && (
            <div className="rounded-lg bg-amber-50 p-3">
              <p className="text-xs font-semibold text-amber-800">
                Previous Admin Notes
              </p>
              <p className="mt-1 whitespace-pre-line text-sm text-amber-800">
                {listing.admin_notes}
              </p>
            </div>
          )}

          {!isSubmitted && (
            <div className="rounded-lg border border-blue-100 bg-blue-50 p-3 text-sm text-blue-800">
              {reviewStatus === 'draft'
                ? 'The owner is still preparing this submission.'
                : reviewStatus === 'published'
                  ? 'This listing is currently published.'
                  : 'This listing is not currently awaiting approval.'}
            </div>
          )}
        </div>

        <div className="sticky bottom-0 z-10 border-t bg-white p-5">
          {isSubmitted && decision ? (
            <div className="space-y-3">
              <label className="block text-sm font-medium text-gray-700">
                {decision === 'approve'
                  ? 'Approval note (optional)'
                  : 'Reason for requesting changes'}
              </label>

              <textarea
                value={notes}
                onChange={(event) => setNotes(event.target.value)}
                rows={3}
                maxLength={1000}
                placeholder={
                  decision === 'approve'
                    ? 'Optional message to the business owner'
                    : 'Explain what needs to be corrected'
                }
                className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-400"
              />

              <div className="flex flex-wrap gap-2">
                <button
                  type="button"
                  onClick={confirmDecision}
                  disabled={
                    busy ||
                    (decision === 'approve' && !canApprove)
                  }
                  className={`rounded-lg px-4 py-2 text-sm font-medium text-white disabled:opacity-50 ${
                    decision === 'approve'
                      ? 'bg-green-600 hover:bg-green-700'
                      : 'bg-red-600 hover:bg-red-700'
                  }`}
                >
                  {busy
                    ? 'Processing...'
                    : decision === 'approve'
                      ? isRevision
                        ? 'Confirm Revision Approval'
                        : 'Confirm Publication Approval'
                      : isRevision
                        ? 'Confirm Request Changes'
                        : 'Confirm Rejection'}
                </button>

                <button
                  type="button"
                  onClick={() => {
                    setDecision(null)
                    setNotes('')
                  }}
                  disabled={busy}
                  className="rounded-lg border px-4 py-2 text-sm"
                >
                  Back
                </button>
              </div>
            </div>
          ) : (
            <div className="flex flex-wrap justify-end gap-2">
              {isSubmitted && (
                <>
                  <button
                    type="button"
                    onClick={() => setDecision('approve')}
                    disabled={!canApprove || busy}
                    className="flex items-center gap-2 rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                  >
                    <FiCheck size={16} />
                    {isRevision ? 'Approve Revision' : 'Approve & Publish'}
                  </button>

                  <button
                    type="button"
                    onClick={() => setDecision('reject')}
                    disabled={busy}
                    className="flex items-center gap-2 rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                  >
                    <FiX size={16} />
                    {isRevision ? 'Request Changes' : 'Reject'}
                  </button>
                </>
              )}

              <button
                type="button"
                onClick={onClose}
                disabled={busy}
                className="rounded-lg border px-4 py-2 text-sm"
              >
                Close
              </button>
            </div>
          )}
        </div>
      </div>
    </div>
  )
}

export default function ReviewListings() {
  const [businessType, setBusinessType] = useState('accommodation')
  const [statusFilter, setStatusFilter] = useState('submitted')
  const [items, setItems] = useState([])
  const [loading, setLoading] = useState(true)
  const [refreshKey, setRefreshKey] = useState(0)
  const [selectedListing, setSelectedListing] = useState(null)
  const [loadingDetail, setLoadingDetail] = useState(false)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    let active = true

    const load = async () => {
      setLoading(true)

      try {
        const response = await API[businessType].list({
          per_page: 100,
        })

        if (active) setItems(extractItems(response))
      } catch (error) {
        if (active) {
          toast.error(
            getErrorMessage(error, 'Failed to load business listings.')
          )
          setItems([])
        }
      } finally {
        if (active) setLoading(false)
      }
    }

    load()

    return () => {
      active = false
    }
  }, [businessType, refreshKey])

  const filteredItems = useMemo(() => {
    if (statusFilter === 'all') return items

    return items.filter(
      (item) => getReviewStatus(item, businessType) === statusFilter
    )
  }, [items, statusFilter, businessType])

  const openListing = async (item) => {
    setLoadingDetail(true)

    try {
      const response = await API[businessType].detail(item.id)

      setSelectedListing({
        type: businessType,
        data: extractDetail(response),
      })
    } catch (error) {
      toast.error(
        getErrorMessage(error, 'Failed to load listing details.')
      )
    } finally {
      setLoadingDetail(false)
    }
  }

  const handleDecision = async (
    type,
    id,
    decision,
    notes,
    isRevision = false
  ) => {
    if (busy) return

    setBusy(true)

    try {
      if (isRevision && API[type].approveRevision) {
        if (decision === 'approve') {
          await API[type].approveRevision(id, notes)
          toast.success('Revision approved and published.')
        } else {
          await API[type].requestChanges(id, notes)
          toast.success('Requested changes sent to the owner.')
        }
      } else if (decision === 'approve') {
        await API[type].approve(id, notes)
        toast.success('Listing approved and published.')
      } else {
        await API[type].reject(id, notes)
        toast.success('Listing returned for revision.')
      }

      setSelectedListing(null)
      setRefreshKey((value) => value + 1)
    } catch (error) {
      toast.error(
        getErrorMessage(error, 'Failed to review listing.')
      )
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <p className="mb-2 font-medium text-primary-600">Admin</p>
          <h1 className="font-heading text-2xl font-bold text-gray-900 sm:text-3xl">
            Listing Publication Review
          </h1>
          <p className="mt-2 max-w-2xl text-sm text-gray-500">
            Review new business listings and proposed Accommodation
            Food &amp; Beverage, and Retail Shop updates before publishing changes.
          </p>
        </div>

        <button
          type="button"
          onClick={() => setRefreshKey((value) => value + 1)}
          className="flex items-center gap-2 rounded-lg border bg-white px-4 py-2 text-sm"
        >
          <FiRefreshCw size={15} />
          Refresh
        </button>
      </div>

      <div className="flex flex-wrap gap-2">
        {BUSINESS_TYPES.map((type) => (
          <button
            key={type.key}
            type="button"
            onClick={() => {
              setBusinessType(type.key)
              setStatusFilter('submitted')
              setSelectedListing(null)
            }}
            className={`rounded-lg border px-4 py-2 text-sm font-medium ${
              businessType === type.key
                ? 'border-primary-500 bg-primary-500 text-white'
                : 'border-gray-200 bg-white text-gray-600'
            }`}
          >
            {type.label}
          </button>
        ))}
      </div>

      <div className="flex flex-wrap gap-2">
        {REVIEW_STATUSES.map((status) => {
          const count = status.key === 'all'
            ? items.length
            : items.filter(
                (item) =>
                  getReviewStatus(item, businessType) === status.key
              ).length

          return (
            <button
              key={status.key}
              type="button"
              onClick={() => setStatusFilter(status.key)}
              className={`rounded-lg px-3 py-2 text-sm font-medium ${
                statusFilter === status.key
                  ? 'bg-gray-900 text-white'
                  : 'bg-gray-100 text-gray-600'
              }`}
            >
              {status.label} ({count})
            </button>
          )
        })}
      </div>

      {loading ? (
        <LoadingSpinner size="lg" />
      ) : filteredItems.length === 0 ? (
        <div className="rounded-xl border bg-white p-12 text-center">
          <FiImage size={32} className="mx-auto mb-3 text-gray-300" />
          <p className="font-medium text-gray-700">No listings found.</p>
          <p className="mt-1 text-sm text-gray-500">
            {statusFilter === 'submitted'
              ? 'There are no listings awaiting review in this category.'
              : 'No listings match the selected filter.'}
          </p>
        </div>
      ) : (
        <div className="space-y-4">
          {filteredItems.map((item) => {
            const reviewStatus = getReviewStatus(item, businessType)
            const images = getProposedImages(item, businessType)
            const isRevision = Boolean(getRevision(item, businessType))

            return (
              <div
                key={item.id}
                className="rounded-xl border border-gray-100 bg-white p-5 sm:p-6"
              >
                <div className="flex flex-col gap-4 sm:flex-row">
                  <div className="h-36 w-full shrink-0 overflow-hidden rounded-lg bg-gray-100 sm:w-44">
                    {images.length > 0 ? (
                      <img
                        src={getImageUrl(getPhotoPath(images[0]))}
                        alt={getListingName(item)}
                        onError={(event) => {
                          event.currentTarget.onerror = null
                          event.currentTarget.src = IMAGE_FALLBACK_SVG
                        }}
                        className="h-full w-full object-cover"
                      />
                    ) : (
                      <div className="flex h-full items-center justify-center">
                        <FiImage size={28} className="text-gray-300" />
                      </div>
                    )}
                  </div>

                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <h2 className="font-heading text-lg font-semibold text-gray-900">
                        {getProposedValue(
                          item,
                          businessType,
                          'name'
                        ) || getListingName(item)}
                      </h2>

                      <span className={`rounded-full px-2.5 py-1 text-xs font-medium ${
                        STATUS_STYLES[reviewStatus] || STATUS_STYLES.draft
                      }`}>
                        {STATUS_LABELS[reviewStatus] || reviewStatus}
                      </span>

                      {isRevision && (
                        <span className="rounded-full bg-blue-100 px-2.5 py-1 text-xs font-medium text-blue-700">
                          Proposed Update
                        </span>
                      )}
                      {item.is_sample && <SampleBadge />}
                    </div>

                    <p className="mt-2 line-clamp-2 text-sm text-gray-600">
                      {getProposedValue(
                        item,
                        businessType,
                        'description'
                      ) || 'No description provided.'}
                    </p>

                    <div className="mt-3 flex flex-wrap gap-x-5 gap-y-2 text-xs text-gray-500">
                      <span className="flex items-center gap-1">
                        <FiImage size={14} />
                        {images.length} photos
                      </span>

                      {item.municipality?.name && (
                        <span className="flex items-center gap-1">
                          <FiMapPin size={14} />
                          {item.municipality.name}
                        </span>
                      )}

                      <span>Status: {item.status || '—'}</span>
                    </div>

                    {isRevision && (
                      <p className="mt-2 text-xs text-blue-700">
                        Published listing stays online while changes
                        are reviewed.
                      </p>
                    )}
                  </div>

                  <div className="flex shrink-0 items-start">
                    <button
                      type="button"
                      onClick={() => openListing(item)}
                      disabled={loadingDetail}
                      className="flex items-center gap-2 rounded-lg bg-primary-500 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                    >
                      <FiEye size={15} />
                      {reviewStatus === 'submitted'
                        ? isRevision
                          ? 'Review Changes'
                          : 'Review Listing'
                        : 'View Details'}
                    </button>
                  </div>
                </div>
              </div>
            )
          })}
        </div>
      )}

      {loadingDetail && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/30">
          <LoadingSpinner size="lg" />
        </div>
      )}

      {selectedListing && (
        <ReviewModal
          listing={selectedListing.data}
          type={selectedListing.type}
          onClose={() => setSelectedListing(null)}
          onDecision={handleDecision}
          busy={busy}
        />
      )}
    </div>
  )
}
