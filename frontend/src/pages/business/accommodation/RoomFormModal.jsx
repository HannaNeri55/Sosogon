import { useState } from 'react'
import { FiUpload, FiX, FiTrash2 } from 'react-icons/fi'
import { createRoom, updateRoom, deleteRoomImage } from '../../../api/accommodationOwner'
import { getImageUrl } from '../../../utils/helpers'
import { ROOM_CATEGORIES } from '../../../utils/constants'
import toast from 'react-hot-toast'

export default function RoomFormModal({ room, onClose, onSaved }) {
  const isEdit = !!room
  const [roomNumber, setRoomNumber] = useState(room?.room_number || '')
  const [category, setCategory] = useState(room?.category || '')
  const [price, setPrice] = useState(room?.price ?? '')
  const [description, setDescription] = useState(room?.description || '')
  const [existingImages, setExistingImages] = useState(room?.images || [])
  const [images, setImages] = useState([])
  const [saving, setSaving] = useState(false)
  const [removingId, setRemovingId] = useState(null)
  const [imagesChanged, setImagesChanged] = useState(false)

  const handleRemoveExistingImage = async (imageId) => {
    if (!confirm('Remove this photo?')) return
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

  // Photo removals save immediately (not staged with the rest of the form),
  // so closing without submitting the other fields still needs to refresh
  // the room list behind the modal instead of leaving its thumbnail stale.
  const handleClose = () => (imagesChanged ? onSaved() : onClose())

  const handleSubmit = async (e) => {
    e.preventDefault()
    const payload = new FormData()
    payload.append('room_number', roomNumber)
    payload.append('category', category)
    payload.append('price', price)
    payload.append('description', description)
    images.forEach((file) => payload.append('images[]', file))

    setSaving(true)
    try {
      const res = isEdit ? await updateRoom(room.id, payload) : await createRoom(payload)
      toast.success(res.message)
      onSaved()
    } catch (err) {
      const errors = err.response?.data?.errors
      if (errors) Object.values(errors).flat().forEach((msg) => toast.error(msg))
      else toast.error(err.response?.data?.message || 'Failed to save room')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="fixed inset-0 bg-black/40 flex items-center justify-center p-4 z-50">
      <div className="bg-white rounded-xl max-w-lg w-full p-5 sm:p-6 max-h-[90vh] overflow-y-auto">
        <div className="flex items-center justify-between mb-5">
          <h2 className="text-lg font-heading font-semibold text-gray-900">{isEdit ? 'Edit Room' : 'Add Room'}</h2>
          <button onClick={handleClose} className="text-gray-400 hover:text-gray-600">
            <FiX size={20} />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="space-y-5">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">Room Number</label>
            <input
              className="input-field"
              placeholder="e.g. Room 101, Villa 3"
              value={roomNumber}
              onChange={(e) => setRoomNumber(e.target.value)}
              required
            />
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">Category</label>
            <select className="input-field" value={category} onChange={(e) => setCategory(e.target.value)} required>
              <option value="" disabled>Select a category</option>
              {ROOM_CATEGORIES.map((c) => (
                <option key={c.value} value={c.value}>{c.label}</option>
              ))}
            </select>
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">Price (PHP)</label>
            <input
              type="number"
              step="0.01"
              min="0"
              className="input-field"
              placeholder="Optional"
              value={price}
              onChange={(e) => setPrice(e.target.value)}
            />
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">Description</label>
            <textarea
              rows={4}
              className="input-field"
              placeholder="Bed type, size, features..."
              value={description}
              onChange={(e) => setDescription(e.target.value)}
            />
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">Photos</label>
            {isEdit && existingImages.length > 0 && (
              <div className="grid grid-cols-4 gap-2 mb-3">
                {existingImages.map((img) => (
                  <div key={img.id} className="relative group">
                    <img src={getImageUrl(img)} alt="" className="w-full h-16 object-cover rounded-lg bg-gray-100" />
                    <button
                      type="button"
                      onClick={() => handleRemoveExistingImage(img.id)}
                      disabled={removingId === img.id}
                      className="absolute top-1 right-1 bg-white/90 text-red-600 p-1 rounded-full opacity-0 group-hover:opacity-100 transition-opacity disabled:opacity-50"
                      title="Remove photo"
                    >
                      <FiTrash2 size={12} />
                    </button>
                  </div>
                ))}
              </div>
            )}
            <label className="flex items-center gap-3 border border-dashed border-gray-300 rounded-lg px-4 py-3 cursor-pointer hover:border-primary-300 hover:bg-primary-50/30 transition-colors">
              <FiUpload className="text-gray-400 shrink-0" size={18} />
              <span className="text-sm text-gray-600 truncate">
                {images.length > 0 ? `${images.length} photo(s) selected` : 'Click to add photos (JPEG, PNG, WebP)'}
              </span>
              <input
                type="file"
                multiple
                accept="image/jpeg,image/png,image/webp"
                onChange={(e) => setImages(Array.from(e.target.files))}
                className="hidden"
              />
            </label>
          </div>

          {isEdit && room.status !== 'pending' && (
            <p className="text-xs text-gray-500">Saving changes will require Tourism Office approval again before this room is visible to tourists.</p>
          )}

          <div className="flex gap-3">
            <button type="submit" className="btn-primary" disabled={saving}>
              {saving ? 'Saving...' : isEdit ? 'Save Changes' : 'Add Room'}
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
