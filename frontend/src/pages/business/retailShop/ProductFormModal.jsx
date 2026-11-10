import { useState, useRef } from 'react'
import { FiUpload, FiX } from 'react-icons/fi'
import { createRetailShopProduct, updateRetailShopProduct } from '../../../api/retailShopOwner'
import { getImageUrl } from '../../../utils/helpers'
import toast from 'react-hot-toast'
import { showApiError } from '../../../utils/apiErrors'
import useFormErrors from '../../../hooks/useFormErrors'
import FieldError, { FormErrorSummary } from '../../../components/common/FieldError'

const PRODUCT_TYPES = ['Souvenir', 'Local Food Product', 'Handicraft', 'Clothing']

export default function ProductFormModal({ item, onClose, onSaved }) {
  const isEdit = !!item
  const [name, setName] = useState(item?.name || '')
  const [description, setDescription] = useState(item?.description || '')
  const [price, setPrice] = useState(item?.price != null ? String(item.price) : '')
  const [productType, setProductType] = useState(item?.product_type || PRODUCT_TYPES[0])
  const [image, setImage] = useState(null)
  const [saving, setSaving] = useState(false)
  const formRef = useRef(null)
  const form = useFormErrors(formRef, {
    validate: () => ({ image: image && image.size > 5 * 1024 * 1024 ? 'Choose a photo of 5 MB or less.' : null }),
  })

  const handleSubmit = async (e) => {
    e.preventDefault()
    if (!form.check()) return
    const payload = new FormData()
    payload.append('name', name)
    payload.append('description', description)
    payload.append('price', price)
    payload.append('product_type', productType)
    if (image) payload.append('image', image)

    setSaving(true)
    try {
      const res = isEdit ? await updateRetailShopProduct(item.id, payload) : await createRetailShopProduct(payload)
      toast.success(res.message)
      onSaved()
    } catch (err) {
      if (!form.fromResponse(err)) showApiError(err, 'Failed to save product')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="fixed inset-0 bg-black/40 flex items-center justify-center p-4 z-50">
      <div className="bg-white rounded-xl max-w-lg w-full p-5 sm:p-6 max-h-[90vh] overflow-y-auto">
        <div className="flex items-center justify-between mb-5">
          <h2 className="text-lg font-heading font-semibold text-gray-900">{isEdit ? 'Edit Product' : 'Add Product'}</h2>
          <button onClick={onClose} className="text-gray-400 hover:text-gray-600">
            <FiX size={20} />
          </button>
        </div>

        <form ref={formRef} noValidate onChange={form.onChange} onSubmit={handleSubmit} className="space-y-5">
          <FormErrorSummary form={form} />
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">Product Name</label>
            <input name="name" maxLength={255}
              className="input-field"
              placeholder="e.g. Pili Nut Brittle, Abaca Woven Bag"
              value={name}
              onChange={(e) => setName(e.target.value)}
              required
            />
            <FieldError form={form} name="name" />
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">Product Type</label>
            <select name="product_type" className="input-field" value={productType} onChange={(e) => setProductType(e.target.value)} required>
              {PRODUCT_TYPES.map((t) => (
                <option key={t} value={t}>{t}</option>
              ))}
            </select>
            <FieldError form={form} name="product_type" />
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">Description</label>
            <textarea name="description" maxLength={2000}
              rows={4}
              className="input-field"
              placeholder="Materials used, cultural significance, ingredients..."
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              required
            />
            <FieldError form={form} name="description" />
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">Price (₱)</label>
            <input name="price"
              type="number"
              step="0.01"
              min="0"
              className="input-field"
              placeholder="0.00"
              value={price}
              onChange={(e) => setPrice(e.target.value)}
              required
            />
            <FieldError form={form} name="price" />
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">Photo</label>
            {isEdit && item.images?.[0] && (
              <div className="mb-3">
                <img src={getImageUrl(item.images[0])} alt="" className="w-24 h-24 object-cover rounded-lg bg-gray-100" />
              </div>
            )}
            <label className="flex items-center gap-3 border border-dashed border-gray-300 rounded-lg px-4 py-3 cursor-pointer hover:border-primary-300 hover:bg-primary-50/30 transition-colors">
              <FiUpload className="text-gray-400 shrink-0" size={18} />
              <span className="min-w-0 text-sm text-gray-600 leading-snug wrap-anywhere">
                {image ? image.name : 'Click to add a photo (JPEG, PNG, WebP)'}
              </span>
              <input
                type="file"
                name="image"
                accept="image/jpeg,image/png,image/webp"
                onChange={(e) => setImage(e.target.files[0] || null)}
                className="hidden"
              />
            </label>
            <FieldError form={form} name="image" />
          </div>

          {isEdit && item.status !== 'pending' && (
            <p className="text-xs text-gray-500">Saving changes will require Tourism Office approval again before this product is visible to tourists.</p>
          )}

          <div className="flex gap-3">
            <button type="submit" className="btn-primary" disabled={saving}>
              {saving ? 'Saving...' : isEdit ? 'Save Changes' : 'Add Product'}
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
