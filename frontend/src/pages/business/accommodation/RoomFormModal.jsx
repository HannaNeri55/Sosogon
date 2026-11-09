import { useState } from 'react'
import { FiChevronDown, FiTrash2, FiUpload, FiX } from 'react-icons/fi'
import toast from 'react-hot-toast'
import { createRoom, updateRoom, deleteRoomImage } from '../../../api/accommodationOwner'
import { getImageUrl } from '../../../utils/helpers'
import { ROOM_CATEGORIES } from '../../../utils/constants'
import { PRICING_MODES, UNIT_TYPES, isEntireProperty, optionName } from '../../../utils/accommodationOptions'
import AmenityPicker from '../../../components/common/AmenityPicker'

const textOf = (value) => (value === null || value === undefined ? '' : String(value))

/**
 * Add or edit one accommodation option. Only a name is needed; the rest appears as it applies to
 * the kind of unit. `entireProperty` opens it for the entire-property rental. Admins pass `save`
 * to correct an option directly, without its photos.
 */
export default function RoomFormModal({ room, entireProperty = false, onClose, onSaved, save, showPhotos = true }) {
  const isEdit = !!room
  const whole = entireProperty || isEntireProperty(room)

  const [unitType, setUnitType] = useState(whole ? 'entire_property' : room?.unit_type || 'room')
  const [name, setName] = useState(room ? optionName(room) : whole ? 'Entire property' : '')
  const [category, setCategory] = useState(room?.category || '')
  const [unitCount, setUnitCount] = useState(textOf(room?.unit_count))
  const [maxGuests, setMaxGuests] = useState(textOf(room?.max_guests))
  const [bedrooms, setBedrooms] = useState(textOf(room?.bedrooms))
  const [beds, setBeds] = useState(room?.bed_configuration || '')
  const [size, setSize] = useState(textOf(room?.size_sqm))
  const [description, setDescription] = useState(room?.description || '')
  const [notes, setNotes] = useState(room?.notes || '')
  const [pricingMode, setPricingMode] = useState(room?.pricing_mode || (Number(room?.price) > 0 ? 'indicative' : 'contact'))
  const [price, setPrice] = useState(Number(room?.price) > 0 ? textOf(room.price) : '')
  const [priceMax, setPriceMax] = useState(Number(room?.price_max) > 0 ? textOf(room.price_max) : '')
  const [priceNote, setPriceNote] = useState(room?.price_note || '')
  const [amenities, setAmenities] = useState(Array.isArray(room?.room_features) ? room.room_features : [])
  const [showMore, setShowMore] = useState(Boolean(room?.notes || room?.size_sqm))

  const [existingImages, setExistingImages] = useState(room?.images || [])
  const [images, setImages] = useState([])
  const [saving, setSaving] = useState(false)
  const [removingId, setRemovingId] = useState(null)
  const [imagesChanged, setImagesChanged] = useState(false)

  const isRoom = unitType === 'room'
  const noun = whole ? 'Entire Property' : 'Option'

  const handleRemoveExistingImage = async (imageId) => {
    if (!window.confirm('Remove this photo?')) return

    setRemovingId(imageId)

    try {
      await deleteRoomImage(room.id, imageId)
      setExistingImages((prev) => prev.filter((img) => img.id !== imageId))
      setImagesChanged(true)
      toast.success('Photo removed')
    } catch {
      toast.error('Failed to remove photo')
    } finally {
      setRemovingId(null)
    }
  }

  // Photo removals save immediately, so refresh the list when closing.
  const handleClose = () => (imagesChanged ? onSaved() : onClose())

  const handleSubmit = async (e) => {
    e.preventDefault()

    const payload = new FormData()
    const fields = {
      name: name.trim(),
      unit_type: unitType,
      category: isRoom ? category : '',
      unit_count: whole ? '' : unitCount,
      max_guests: maxGuests,
      bedrooms: isRoom ? '' : bedrooms,
      bed_configuration: beds.trim(),
      size_sqm: size,
      description: description.trim(),
      notes: notes.trim(),
      pricing_mode: pricingMode,
      price: pricingMode === 'contact' ? '' : price,
      price_max: pricingMode === 'contact' ? '' : priceMax,
      price_note: pricingMode === 'contact' ? '' : priceNote.trim(),
    }
    Object.entries(fields).forEach(([key, value]) => payload.append(key, value))

    // An empty value lets the owner remove every amenity saved before.
    if (amenities.length === 0) payload.append('room_features', '')
    amenities.forEach((amenity, index) => payload.append(`room_features[${index}]`, amenity))
    images.forEach((file) => payload.append('images[]', file))

    setSaving(true)

    try {
      const res = save ? await save(payload) : isEdit ? await updateRoom(room.id, payload) : await createRoom(payload)
      toast.success(res.message)
      onSaved()
    } catch (err) {
      const errors = err.response?.data?.errors
      if (errors) {
        Object.values(errors).flat().forEach((msg) => toast.error(msg))
      } else {
        toast.error(err.response?.data?.message || 'Failed to save')
      }
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="fixed inset-0 bg-black/40 flex items-center justify-center p-4 z-50">
      <div className="bg-white rounded-xl max-w-2xl w-full p-5 sm:p-6 max-h-[90vh] overflow-y-auto">
        <div className="flex items-center justify-between mb-5">
          <h2 className="text-lg font-heading font-semibold text-gray-900">{isEdit ? `Edit ${noun}` : `Add ${noun}`}</h2>
          <button type="button" onClick={handleClose} className="text-gray-400 hover:text-gray-600" aria-label="Close">
            <FiX size={20} />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="space-y-5">
          {!whole && (
            <div>
              <label htmlFor="option_type" className="block text-sm font-medium text-gray-700 mb-2">What is it?</label>
              <select id="option_type" className="input-field" value={unitType} onChange={(e) => setUnitType(e.target.value)}>
                {UNIT_TYPES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
              </select>
            </div>
          )}

          <div>
            <label htmlFor="option_name" className="block text-sm font-medium text-gray-700 mb-2">Name</label>
            <input
              id="option_name"
              className="input-field"
              placeholder={isRoom ? 'e.g. Superior Room, Room 101' : 'e.g. Villa 1, Beach Cottage'}
              maxLength={255}
              value={name}
              onChange={(e) => setName(e.target.value)}
              required
            />
          </div>

          {isRoom && (
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label htmlFor="option_category" className="block text-sm font-medium text-gray-700 mb-2">Room category (optional)</label>
                <select id="option_category" className="input-field" value={category} onChange={(e) => setCategory(e.target.value)}>
                  <option value="">Not specified</option>
                  {/* A category saved earlier stays selectable even if it's no longer on the list. */}
                  {category && !ROOM_CATEGORIES.some((c) => c.value === category) && <option value={category}>{category}</option>}
                  {ROOM_CATEGORIES.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
                </select>
              </div>
              <div>
                <label htmlFor="option_count" className="block text-sm font-medium text-gray-700 mb-2">How many rooms like this? (optional)</label>
                <input id="option_count" type="number" min="1" max="999" className="input-field" value={unitCount} onChange={(e) => setUnitCount(e.target.value)} />
                <p className="text-xs text-gray-500 mt-1">Similar rooms can share one entry. Add a separate option only if a room is different.</p>
              </div>
            </div>
          )}

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label htmlFor="option_guests" className="block text-sm font-medium text-gray-700 mb-2">Maximum guests (optional)</label>
              <input id="option_guests" type="number" min="1" max="200" className="input-field" value={maxGuests} onChange={(e) => setMaxGuests(e.target.value)} />
            </div>
            {!isRoom && (
              <div>
                <label htmlFor="option_bedrooms" className="block text-sm font-medium text-gray-700 mb-2">Bedrooms (optional)</label>
                <input id="option_bedrooms" type="number" min="0" max="100" className="input-field" value={bedrooms} onChange={(e) => setBedrooms(e.target.value)} />
              </div>
            )}
            {!whole && !isRoom && (
              <div>
                <label htmlFor="option_units" className="block text-sm font-medium text-gray-700 mb-2">How many like this? (optional)</label>
                <input id="option_units" type="number" min="1" max="999" className="input-field" value={unitCount} onChange={(e) => setUnitCount(e.target.value)} />
              </div>
            )}
            <div>
              <label htmlFor="option_beds" className="block text-sm font-medium text-gray-700 mb-2">Beds (optional)</label>
              <input id="option_beds" className="input-field" placeholder="e.g. 1 queen bed" maxLength={255} value={beds} onChange={(e) => setBeds(e.target.value)} />
            </div>
          </div>

          <div>
            <label htmlFor="option_description" className="block text-sm font-medium text-gray-700 mb-2">Description (optional)</label>
            <textarea id="option_description" rows={3} className="input-field" maxLength={2000} value={description} onChange={(e) => setDescription(e.target.value)} />
          </div>

          <fieldset className="border-t border-gray-100 pt-5">
            <legend className="text-sm font-semibold text-gray-900 mb-2">Rates (optional)</legend>
            <div className="flex flex-wrap gap-2 mb-3">
              {PRICING_MODES.map((mode) => (
                <label key={mode.value} className={`cursor-pointer rounded-full border px-3 py-1.5 text-sm ${pricingMode === mode.value ? 'border-primary-500 bg-primary-50 text-primary-700' : 'border-gray-200 text-gray-600 hover:bg-gray-50'}`}>
                  <input type="radio" name="pricing_mode" value={mode.value} checked={pricingMode === mode.value} onChange={() => setPricingMode(mode.value)} className="sr-only" />
                  {mode.label}
                </label>
              ))}
            </div>
            {pricingMode !== 'contact' && (
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                  <label htmlFor="option_price" className="block text-xs font-medium text-gray-600 mb-1">{pricingMode === 'varies' ? 'From (PHP, optional)' : 'Price (PHP)'}</label>
                  <input id="option_price" type="number" step="0.01" min="0" className="input-field" required={pricingMode === 'indicative'} value={price} onChange={(e) => setPrice(e.target.value)} />
                </div>
                <div>
                  <label htmlFor="option_price_max" className="block text-xs font-medium text-gray-600 mb-1">Up to (PHP, optional)</label>
                  <input id="option_price_max" type="number" step="0.01" min="0" className="input-field" value={priceMax} onChange={(e) => setPriceMax(e.target.value)} />
                </div>
                <div>
                  <label htmlFor="option_price_note" className="block text-xs font-medium text-gray-600 mb-1">What it covers (optional)</label>
                  <input id="option_price_note" className="input-field" placeholder="e.g. Per night, 2 guests" maxLength={255} value={priceNote} onChange={(e) => setPriceNote(e.target.value)} />
                </div>
              </div>
            )}
            <p className="text-xs text-gray-500 mt-2">Tourists see this as information to confirm with you. SOSOGON takes no bookings.</p>
          </fieldset>

          <div className="border-t border-gray-100 pt-5">
            <h3 className="text-sm font-semibold text-gray-900 mb-1">Amenities in this {whole ? 'rental' : isRoom ? 'room' : 'unit'}</h3>
            <AmenityPicker scope="unit" value={amenities} onChange={setAmenities} hint="Select only what this one has. Shared facilities belong in your property amenities." />
          </div>

          <div>
            <button type="button" onClick={() => setShowMore((v) => !v)} aria-expanded={showMore} className="inline-flex items-center gap-1 text-sm font-medium text-primary-600">
              <FiChevronDown size={15} className={showMore ? 'rotate-180 transition-transform' : 'transition-transform'} aria-hidden="true" /> More details
            </button>
            {showMore && (
              <div className="mt-3 space-y-4">
                <div>
                  <label htmlFor="option_size" className="block text-sm font-medium text-gray-700 mb-2">Size in square meters (optional)</label>
                  <input id="option_size" type="number" step="0.01" min="1" className="input-field" value={size} onChange={(e) => setSize(e.target.value)} />
                </div>
                <div>
                  <label htmlFor="option_notes" className="block text-sm font-medium text-gray-700 mb-2">Other notes (optional)</label>
                  <textarea id="option_notes" rows={2} className="input-field" maxLength={2000} value={notes} onChange={(e) => setNotes(e.target.value)} />
                </div>
              </div>
            )}
          </div>

          {showPhotos && (
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">Photos (optional)</label>

              {isEdit && existingImages.length > 0 && (
                <div className="grid grid-cols-4 gap-2 mb-3">
                  {existingImages.map((img) => (
                    <div key={img.id} className="relative group">
                      <img src={getImageUrl(img)} alt="" className="w-full h-16 object-cover rounded-lg bg-gray-100" />
                      <button
                        type="button"
                        onClick={() => handleRemoveExistingImage(img.id)}
                        disabled={removingId === img.id}
                        className="absolute top-1 right-1 bg-white/90 text-red-600 p-1 rounded-full sm:opacity-0 sm:group-hover:opacity-100 focus:opacity-100 transition-opacity disabled:opacity-50"
                        aria-label="Remove photo"
                      >
                        <FiTrash2 size={12} />
                      </button>
                    </div>
                  ))}
                </div>
              )}

              <label className="flex items-center gap-3 border border-dashed border-gray-300 rounded-lg px-4 py-3 cursor-pointer hover:border-primary-300 hover:bg-primary-50/30 transition-colors">
                <FiUpload className="text-gray-400 shrink-0" size={18} />
                <span className="min-w-0 text-sm text-gray-600 leading-snug wrap-anywhere">
                  {images.length > 0 ? `${images.length} photo(s) selected` : 'Click to add up to 10 photos (JPEG, PNG, WebP)'}
                </span>
                <input type="file" multiple accept="image/jpeg,image/png,image/webp" onChange={(e) => setImages(Array.from(e.target.files || []))} className="hidden" />
              </label>
            </div>
          )}

          {!save && isEdit && room.status !== 'pending' && (
            <p className="text-xs text-gray-500">Changes may need Tourism Office approval, depending on your property's approval status.</p>
          )}

          <div className="flex gap-3">
            <button type="submit" className="btn-primary" disabled={saving}>
              {saving ? 'Saving...' : isEdit ? 'Save Changes' : `Add ${noun}`}
            </button>
            <button type="button" onClick={handleClose} className="px-4 py-2 border border-gray-200 rounded-lg text-gray-600 hover:bg-gray-50">
              Cancel
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
