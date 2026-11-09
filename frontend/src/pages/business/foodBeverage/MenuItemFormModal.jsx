import { useState } from 'react'
import { FiUpload, FiX } from 'react-icons/fi'
import { createMenuItem, updateMenuItem } from '../../../api/foodBeverageOwner'
import { getImageUrl } from '../../../utils/helpers'
import toast from 'react-hot-toast'
import { showApiError } from '../../../utils/apiErrors'

export default function MenuItemFormModal({ item, onClose, onSaved }) {
  const isEdit = !!item
  const [name, setName] = useState(item?.name || '')
  const [description, setDescription] = useState(item?.description || '')
  const [price, setPrice] = useState(item?.price != null ? String(item.price) : '')
  const [image, setImage] = useState(null)
  const [saving, setSaving] = useState(false)

  const handleSubmit = async (e) => {
    e.preventDefault()
    const payload = new FormData()
    payload.append('name', name)
    payload.append('description', description)
    payload.append('price', price)
    if (image) payload.append('image', image)

    setSaving(true)
    try {
      const res = isEdit ? await updateMenuItem(item.id, payload) : await createMenuItem(payload)
      toast.success(res.message)
      onSaved()
    } catch (err) {
      const errors = err.response?.data?.errors
      if (errors) Object.values(errors).flat().forEach((msg) => toast.error(msg))
      else showApiError(err, 'Failed to save dish')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="fixed inset-0 bg-black/40 flex items-center justify-center p-4 z-50">
      <div className="bg-white rounded-xl max-w-lg w-full p-5 sm:p-6 max-h-[90vh] overflow-y-auto">
        <div className="flex items-center justify-between mb-5">
          <h2 className="text-lg font-heading font-semibold text-gray-900">{isEdit ? 'Edit Dish' : 'Add Dish'}</h2>
          <button onClick={onClose} className="text-gray-400 hover:text-gray-600">
            <FiX size={20} />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="space-y-5">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">Dish Name</label>
            <input
              className="input-field"
              placeholder="e.g. Bicol Express, Sinigang na Baboy"
              value={name}
              onChange={(e) => setName(e.target.value)}
              required
            />
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">Description</label>
            <textarea
              rows={4}
              className="input-field"
              placeholder="Ingredients, dietary notes (e.g. spicy, vegetarian)..."
              value={description}
              onChange={(e) => setDescription(e.target.value)}
            />
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">Price (₱)</label>
            <input
              type="number"
              step="0.01"
              min="0"
              className="input-field"
              placeholder="0.00"
              value={price}
              onChange={(e) => setPrice(e.target.value)}
              required
            />
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">Photo</label>
            {isEdit && item.image_path && (
              <div className="mb-3">
                <img src={getImageUrl(item)} alt="" className="w-24 h-24 object-cover rounded-lg bg-gray-100" />
              </div>
            )}
            <label className="flex items-center gap-3 border border-dashed border-gray-300 rounded-lg px-4 py-3 cursor-pointer hover:border-primary-300 hover:bg-primary-50/30 transition-colors">
              <FiUpload className="text-gray-400 shrink-0" size={18} />
              <span className="min-w-0 text-sm text-gray-600 leading-snug wrap-anywhere">
                {image ? image.name : 'Click to add a photo (JPEG, PNG, WebP)'}
              </span>
              <input
                type="file"
                accept="image/jpeg,image/png,image/webp"
                onChange={(e) => setImage(e.target.files[0] || null)}
                className="hidden"
              />
            </label>
          </div>

          {isEdit && item.status !== 'pending' && (
            <p className="text-xs text-gray-500">Saving changes will require Tourism Office approval again before this dish is visible to tourists.</p>
          )}

          <div className="flex gap-3">
            <button type="submit" className="btn-primary" disabled={saving}>
              {saving ? 'Saving...' : isEdit ? 'Save Changes' : 'Add Dish'}
            </button>
            <button type="button" onClick={onClose} className="px-4 py-2 border border-gray-200 rounded-lg text-gray-600 hover:bg-gray-50">
              Cancel
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
