// frontend/src/pages/admin/ManageDestinations.jsx — full file
import { useEffect, useMemo, useState } from 'react'
import { FiSearch, FiEdit2, FiTrash2, FiEye, FiMapPin, FiStar, FiX } from 'react-icons/fi'
import { getAdminDestinations, createDestination, updateDestination, deleteDestination, deleteDestinationImage, getMunicipalities, getCategories } from '../../api/admin'
import { getImageUrl } from '../../utils/helpers'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import toast from 'react-hot-toast'

const EMPTY_FORM = {
  name: '', description: '', category_id: '', municipality_id: '1',
  address: '', latitude: '', longitude: '', operating_hours: '', entrance_fee: '',
  contact_number: '', website: '', status: 'published', is_featured: false,
}

export default function ManageDestinations() {
  const [items, setItems] = useState([])
  const [municipalities, setMunicipalities] = useState([])
  const [categories, setCategories] = useState([])
  const [loading, setLoading] = useState(true)
  const [showForm, setShowForm] = useState(false)
  const [editingId, setEditingId] = useState(null)
  const [formData, setFormData] = useState(EMPTY_FORM)
  const [existingImages, setExistingImages] = useState([])
  const [search, setSearch] = useState('')

  const filteredItems = useMemo(() => {
    const q = search.trim().toLowerCase()
    if (!q) return items
    return items.filter((item) => item.name?.toLowerCase().includes(q) || item.category?.name?.toLowerCase().includes(q))
  }, [items, search])

  const loadData = async () => {
    setLoading(true)
    try {
      const data = await getAdminDestinations({ per_page: 100 })
      setItems(data.data || [])
    } finally {
      setLoading(false)
    }
  }

  const loadMeta = async () => {
    const [m, c] = await Promise.all([getMunicipalities(), getCategories('destination')])
    setMunicipalities(m)
    setCategories(c)
  }

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    loadData()
    loadMeta()
  }, [])

  const startEdit = (item) => {
    setEditingId(item.id)
    setFormData({
      name: item.name || '',
      description: item.description || '',
      category_id: item.category_id || '',
      municipality_id: item.municipality_id || '1',
      address: item.address || '',
      latitude: item.latitude || '',
      longitude: item.longitude || '',
      operating_hours: item.operating_hours || '',
      entrance_fee: item.entrance_fee || '',
      contact_number: item.contact_number || '',
      website: item.website || '',
      status: item.status || 'published',
      is_featured: !!item.is_featured,
    })
    setExistingImages(item.images || [])
    setShowForm(true)
  }

  const closeForm = () => {
    setShowForm(false)
    setEditingId(null)
    setFormData(EMPTY_FORM)
    setExistingImages([])
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    try {
      const payload = new FormData()
      Object.keys(formData).forEach((key) => {
        if (key === 'images') return
        if (formData[key] !== '' && formData[key] !== null) {
          // FormData stringifies JS booleans as "true"/"false", which Laravel's
          // `boolean` validation rule rejects — send "1"/"0" instead.
          payload.append(key, key === 'is_featured' ? (formData[key] ? '1' : '0') : formData[key])
        }
      })
      if (formData.images && formData.images.length > 0) {
        formData.images.forEach((file) => payload.append('images[]', file))
      }

      if (editingId) {
        await updateDestination(editingId, payload)
        toast.success('Destination updated')
      } else {
        await createDestination(payload)
        toast.success('Destination created')
      }
      closeForm()
      loadData()
    } catch (e) {
      const errors = e.response?.data?.errors
      if (errors) Object.values(errors).flat().forEach((msg) => toast.error(msg))
      else toast.error(editingId ? 'Failed to update destination' : 'Failed to create destination')
    }
  }

  const handleDeleteImage = async (imageId) => {
    if (!confirm('Remove this image?')) return
    try {
      await deleteDestinationImage(editingId, imageId)
      setExistingImages((prev) => prev.filter((img) => img.id !== imageId))
      toast.success('Image removed')
    } catch {
      toast.error('Failed to remove image')
    }
  }

  const handleDelete = async (id) => {
    if (!confirm('Delete this destination?')) return
    try {
      await deleteDestination(id)
      toast.success('Destination deleted')
      loadData()
    } catch {
      toast.error('Failed to delete destination')
    }
  }

  if (loading) return <LoadingSpinner size="lg" />

  return (
    <div>
      <div className="flex flex-wrap items-end justify-between gap-4 mb-8">
        <div>
          <p className="text-primary-600 font-medium mb-2">Admin</p>
          <h1 className="text-3xl sm:text-4xl font-heading font-bold text-gray-900">Manage Destinations</h1>
        </div>
        <button onClick={() => (showForm ? closeForm() : setShowForm(true))} className="btn-primary">
          {showForm ? 'Close Form' : 'Add Destination'}
        </button>
      </div>

      {showForm && (
        <form onSubmit={handleSubmit} className="bg-white rounded-xl p-5 sm:p-8 border border-gray-100 mb-8 space-y-5">
          <p className="text-sm font-medium text-gray-500">{editingId ? 'Editing destination' : 'New destination'}</p>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
            <input className="input-field" placeholder="Destination name" value={formData.name} onChange={(e) => setFormData({ ...formData, name: e.target.value })} required />
            <select className="input-field" value={formData.category_id} onChange={(e) => setFormData({ ...formData, category_id: e.target.value })} required>
              <option value="">Select category</option>
              {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </div>
          <textarea className="input-field" rows={4} placeholder="Description" value={formData.description} onChange={(e) => setFormData({ ...formData, description: e.target.value })} required />
          <input className="input-field" placeholder="Address" value={formData.address} onChange={(e) => setFormData({ ...formData, address: e.target.value })} required />
          <select className="input-field" value={formData.municipality_id} onChange={(e) => setFormData({ ...formData, municipality_id: e.target.value })}>
            {municipalities.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
          </select>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-5">
            <input type="number" step="0.0000001" className="input-field" placeholder="Latitude" value={formData.latitude} onChange={(e) => setFormData({ ...formData, latitude: e.target.value })} required />
            <input type="number" step="0.0000001" className="input-field" placeholder="Longitude" value={formData.longitude} onChange={(e) => setFormData({ ...formData, longitude: e.target.value })} required />
            <input className="input-field" placeholder="Operating hours" value={formData.operating_hours} onChange={(e) => setFormData({ ...formData, operating_hours: e.target.value })} />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Destination Images
            </label>
            {editingId && existingImages.length > 0 && (
              <div className="grid grid-cols-4 sm:grid-cols-6 gap-3 mb-3">
                {existingImages.map((img) => (
                  <div key={img.id} className="relative group">
                    <img
                      src={getImageUrl(img)}
                      alt=""
                      className="w-full aspect-square rounded-lg object-cover border border-gray-200"
                    />
                    <button
                      type="button"
                      onClick={() => handleDeleteImage(img.id)}
                      className="absolute -top-1.5 -right-1.5 w-5 h-5 rounded-full bg-red-600 text-white flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity"
                      title="Remove image"
                    >
                      <FiX size={12} />
                    </button>
                  </div>
                ))}
              </div>
            )}
            <input
              type="file"
              multiple
              accept="image/jpeg,image/png,image/webp"
              onChange={(e) => setFormData({ ...formData, images: Array.from(e.target.files) })}
              className="input-field"
            />
            <p className="text-xs text-gray-500 mt-1">
              {editingId ? 'Upload additional images (JPEG, PNG, WebP, max 5MB each)' : 'Upload up to 10 images (JPEG, PNG, WebP, max 5MB each)'}
            </p>
          </div>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-5">
            <input type="number" step="0.01" className="input-field" placeholder="Entrance fee" value={formData.entrance_fee} onChange={(e) => setFormData({ ...formData, entrance_fee: e.target.value })} />
            <input className="input-field" placeholder="Contact number" value={formData.contact_number} onChange={(e) => setFormData({ ...formData, contact_number: e.target.value })} />
            <select className="input-field" value={formData.status} onChange={(e) => setFormData({ ...formData, status: e.target.value })}>
              <option value="published">Published</option>
              <option value="draft">Draft</option>
            </select>
          </div>
          <input type="url" className="input-field" placeholder="Website (optional)" value={formData.website} onChange={(e) => setFormData({ ...formData, website: e.target.value })} />
          <label className="flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" checked={formData.is_featured} onChange={(e) => setFormData({ ...formData, is_featured: e.target.checked })} />
            Featured destination (shown on the homepage)
          </label>
          <div className="flex gap-3">
            <button type="submit" className="btn-primary">{editingId ? 'Save Changes' : 'Save Destination'}</button>
            {editingId && (
              <button type="button" onClick={closeForm} className="px-4 py-2 border border-gray-200 rounded-lg text-gray-600 hover:bg-gray-50">
                Cancel
              </button>
            )}
          </div>
        </form>
      )}

      <div className="relative mb-5 max-w-sm">
        <FiSearch className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={16} />
        <input
          className="input-field pl-9"
          placeholder="Search destinations..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
      </div>

      <div className="bg-white rounded-xl border border-gray-100 overflow-hidden">
        <div className="overflow-x-auto">
          {/* Phones: keep columns readable and let the table scroll sideways. */}
          <table className="w-full min-w-[40rem] [&_.rounded-full]:whitespace-nowrap">
            <thead className="bg-gray-50 border-b border-gray-100">
              <tr>
                <th className="text-left px-6 py-3 text-sm font-medium text-gray-700">Destination</th>
                <th className="text-left px-6 py-3 text-sm font-medium text-gray-700">Category</th>
                <th className="text-left px-6 py-3 text-sm font-medium text-gray-700">Status</th>
                <th className="text-left px-6 py-3 text-sm font-medium text-gray-700">Featured</th>
                <th className="text-left px-6 py-3 text-sm font-medium text-gray-700">Views</th>
                <th className="text-right px-6 py-3 text-sm font-medium text-gray-700">Actions</th>
              </tr>
            </thead>
            <tbody>
              {filteredItems.map((item) => (
                <tr key={item.id} className="border-b border-gray-100 hover:bg-gray-50 transition-colors duration-150">
                  <td className="px-6 py-3">
                    <div className="flex items-center gap-3">
                      <img
                        src={getImageUrl(item.images?.[0])}
                        alt=""
                        className="w-10 h-10 rounded-lg object-cover shrink-0 bg-gray-100"
                      />
                      <div>
                        <p className="text-sm font-medium text-gray-900">{item.name}</p>
                        <p className="text-xs text-gray-400 flex items-center gap-1 mt-0.5"><FiMapPin size={10} />{item.municipality?.name}</p>
                      </div>
                    </div>
                  </td>
                  <td className="px-6 py-4">
                    <span className="text-xs font-medium px-2.5 py-1 rounded-full bg-secondary-50 text-secondary-700">{item.category?.name}</span>
                  </td>
                  <td className="px-6 py-4">
                    <span className={`text-xs font-medium px-2.5 py-1 rounded-full capitalize ${item.status === 'published' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'}`}>
                      {item.status}
                    </span>
                  </td>
                  <td className="px-6 py-4 text-sm text-gray-600">
                    {item.is_featured ? <FiStar className="text-yellow-500 fill-yellow-500" size={15} /> : '—'}
                  </td>
                  <td className="px-6 py-4 text-sm text-gray-600">
                    <span className="flex items-center gap-1.5"><FiEye size={13} className="text-gray-400" />{item.view_count}</span>
                  </td>
                  <td className="px-6 py-4">
                    <div className="flex gap-3 justify-end">
                      <button onClick={() => startEdit(item)} className="p-2 text-gray-400 hover:text-primary-600 hover:bg-primary-50 rounded-lg transition-colors" title="Edit">
                        <FiEdit2 size={15} />
                      </button>
                      <button onClick={() => handleDelete(item.id)} className="p-2 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Delete">
                        <FiTrash2 size={15} />
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}