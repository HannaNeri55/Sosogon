import { useEffect, useMemo, useState } from 'react'
import { FiSearch, FiEdit2, FiTrash2, FiStar, FiImage, FiUpload, FiX } from 'react-icons/fi'
import { getAdminEvents, createEvent, updateEvent, deleteEvent, getMunicipalities } from '../../api/admin'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import { getImageUrl } from '../../utils/helpers'
import toast from 'react-hot-toast'
import useScrollToForm from '../../hooks/useScrollToForm'
import { showApiError } from '../../utils/apiErrors'
import { confirmDialog } from '../../utils/dialogs'

const EMPTY_FORM = {
  title: '', description: '', location: '', municipality_id: '',
  start_date: '', end_date: '', is_featured: false, status: 'published',
}

// Same limits the server enforces (AdminEventController::IMAGE_RULE).
const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp']
const IMAGE_MAX_BYTES = 5 * 1024 * 1024

export default function ManageEvents() {
  const [items, setItems] = useState([])
  const [municipalities, setMunicipalities] = useState([])
  const [loading, setLoading] = useState(true)
  const [showForm, setShowForm] = useState(false)
  const [editingId, setEditingId] = useState(null)
  const [formData, setFormData] = useState(EMPTY_FORM)
  const [search, setSearch] = useState('')
  // Cover image: a newly chosen file (with a local preview), the event's current image, or "remove".
  const [imageFile, setImageFile] = useState(null)
  const [imagePreview, setImagePreview] = useState(null)
  const [currentImage, setCurrentImage] = useState(null)
  const [removeImage, setRemoveImage] = useState(false)

  useEffect(() => () => imagePreview && URL.revokeObjectURL(imagePreview), [imagePreview])

  const chooseImage = (file) => {
    if (!file) return
    if (!IMAGE_TYPES.includes(file.type)) {
      toast.error('Please choose a JPG, PNG or WebP image.')
      return
    }
    if (file.size > IMAGE_MAX_BYTES) {
      toast.error('The image is larger than 5 MB. Please choose a smaller one.')
      return
    }
    setImageFile(file)
    setImagePreview(URL.createObjectURL(file))
    setRemoveImage(false)
  }

  const clearImage = () => {
    setImageFile(null)
    setImagePreview(null)
    if (currentImage) setRemoveImage(true)
  }

  const filteredItems = useMemo(() => {
    const q = search.trim().toLowerCase()
    if (!q) return items
    return items.filter((item) => item.title?.toLowerCase().includes(q))
  }, [items, search])

  const formRef = useScrollToForm(showForm ? editingId ?? 'new' : null)

  const loadData = async () => {
    try {
      const data = await getAdminEvents({ per_page: 100 })
      setItems(data.data || [])
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    loadData()
    getMunicipalities().then(setMunicipalities)
  }, [])

  const startEdit = (item) => {
    setEditingId(item.id)
    setFormData({
      title: item.title || '',
      description: item.description || '',
      location: item.location || '',
      municipality_id: item.municipality_id || '',
      start_date: item.start_date || '',
      end_date: item.end_date || '',
      is_featured: !!item.is_featured,
      status: item.status || 'published',
    })
    setImageFile(null)
    setImagePreview(null)
    setCurrentImage(item.image_path || null)
    setRemoveImage(false)
    setShowForm(true)
  }

  const closeForm = () => {
    setShowForm(false)
    setEditingId(null)
    setFormData(EMPTY_FORM)
    setImageFile(null)
    setImagePreview(null)
    setCurrentImage(null)
    setRemoveImage(false)
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    const payload = new FormData()
    payload.append('title', formData.title)
    payload.append('description', formData.description)
    payload.append('location', formData.location || '')
    payload.append('municipality_id', formData.municipality_id || '')
    payload.append('start_date', formData.start_date)
    payload.append('end_date', formData.end_date || '')
    payload.append('is_featured', formData.is_featured ? '1' : '0')
    payload.append('status', formData.status)
    if (imageFile) payload.append('image', imageFile)
    else if (editingId && removeImage) payload.append('remove_image', '1')
    try {
      if (editingId) {
        await updateEvent(editingId, payload)
        toast.success('Event updated')
      } else {
        await createEvent(payload)
        toast.success('Event created')
      }
      closeForm()
      loadData()
    } catch (err) {
      const errors = err.response?.data?.errors
      if (errors) Object.values(errors).flat().forEach((msg) => toast.error(msg))
      else showApiError(err, editingId ? 'Failed to update event' : 'Failed to create event')
    }
  }

  const handleDelete = async (id) => {
    if (!(await confirmDialog({ title: 'Delete this event?', message: 'It will be removed from the site and the calendar. This cannot be undone.', confirmLabel: 'Delete', danger: true }))) return
    try {
      await deleteEvent(id)
      toast.success('Event deleted')
      loadData()
    } catch (error) {
      showApiError(error, 'Failed to delete event')
    }
  }

  if (loading) return <LoadingSpinner size="lg" />

  return (
    <div>
      <div className="flex flex-wrap items-end justify-between gap-4 mb-8">
        <div>
          <p className="text-primary-600 font-medium mb-2">Admin</p>
          <h1 className="text-2xl sm:text-3xl font-heading font-bold text-gray-900">Manage Events</h1>
        </div>
        <button onClick={() => (showForm ? closeForm() : setShowForm(true))} className="btn-primary">
          {showForm ? 'Close Form' : 'Add Event'}
        </button>
      </div>

      {showForm && (
        <form
          ref={formRef} onSubmit={handleSubmit} className="bg-white rounded-xl p-5 sm:p-8 border border-gray-100 mb-8 space-y-5">
          <p className="text-sm font-medium text-gray-500">{editingId ? 'Editing event' : 'New event'}</p>
          <input className="input-field" placeholder="Event title" value={formData.title} onChange={(e) => setFormData({ ...formData, title: e.target.value })} required />
          <textarea className="input-field" rows={4} placeholder="Description" value={formData.description} onChange={(e) => setFormData({ ...formData, description: e.target.value })} required />
          <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
            <select className="input-field" value={formData.municipality_id} onChange={(e) => setFormData({ ...formData, municipality_id: e.target.value })}>
              <option value="">Province-wide (no specific municipality)</option>
              {municipalities.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
            </select>
            <input className="input-field" placeholder="Location (optional, mainly for province-wide events)" value={formData.location} onChange={(e) => setFormData({ ...formData, location: e.target.value })} />
          </div>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">Start date</label>
              <input type="date" className="input-field" value={formData.start_date} onChange={(e) => setFormData({ ...formData, start_date: e.target.value })} required />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">End date</label>
              <input type="date" className="input-field" value={formData.end_date} onChange={(e) => setFormData({ ...formData, end_date: e.target.value })} />
            </div>
          </div>
          <div>
            <p className="block text-sm font-medium text-gray-700 mb-2">Cover image</p>
            {(() => {
              const shown = imagePreview || (!removeImage && currentImage ? getImageUrl(currentImage) : null)
              return shown ? (
                <div className="relative w-full max-w-md overflow-hidden rounded-xl border border-gray-200 bg-gray-100">
                  <img src={shown} alt="Event cover preview" className="aspect-video w-full object-cover" />
                  <div className="absolute right-2 top-2 flex gap-2">
                    <label className="inline-flex cursor-pointer items-center gap-1.5 rounded-full bg-white/95 px-3 py-1.5 text-xs font-semibold text-gray-800 shadow-sm hover:bg-white">
                      <FiUpload size={13} aria-hidden="true" /> Replace
                      <input type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" onChange={(e) => { chooseImage(e.target.files?.[0]); e.target.value = '' }} />
                    </label>
                    <button type="button" onClick={clearImage} className="inline-flex items-center gap-1 rounded-full bg-white/95 px-3 py-1.5 text-xs font-semibold text-red-700 shadow-sm hover:bg-white">
                      <FiX size={13} aria-hidden="true" /> Remove
                    </button>
                  </div>
                  {imageFile && (
                    <span className="absolute bottom-2 left-2 rounded-full bg-black/65 px-2.5 py-1 text-xs text-white">New image — saved when you submit</span>
                  )}
                </div>
              ) : (
                <label
                  className="flex w-full max-w-md cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-gray-300 bg-gray-50 px-6 py-8 text-center hover:border-primary-400 hover:bg-primary-50/40 transition-colors"
                  onDragOver={(e) => e.preventDefault()}
                  onDrop={(e) => { e.preventDefault(); chooseImage(e.dataTransfer.files?.[0]) }}
                >
                  <FiImage size={26} className="text-gray-400" aria-hidden="true" />
                  <span className="text-sm font-semibold text-gray-700">Upload a cover image or banner</span>
                  <span className="text-xs text-gray-500">JPG, PNG or WebP, up to 5 MB. A wide (landscape) photo works best.</span>
                  {removeImage && <span className="text-xs font-medium text-red-700">The current image will be removed when you save.</span>}
                  <input type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" onChange={(e) => { chooseImage(e.target.files?.[0]); e.target.value = '' }} />
                </label>
              )
            })()}
          </div>
          <div className="flex items-center gap-6">
            <label className="flex items-center gap-2 text-sm text-gray-700">
              <input type="checkbox" checked={formData.is_featured} onChange={(e) => setFormData({ ...formData, is_featured: e.target.checked })} />
              Featured event
            </label>
            <select className="input-field w-auto" value={formData.status} onChange={(e) => setFormData({ ...formData, status: e.target.value })}>
              <option value="published">Published</option>
              <option value="draft">Draft</option>
            </select>
          </div>
          <div className="flex gap-3">
            <button type="submit" className="btn-primary">{editingId ? 'Save Changes' : 'Save Event'}</button>
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
          placeholder="Search events..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
      </div>

      <div className="bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) overflow-hidden">
        <div className="overflow-x-auto">
          {/* Phones: keep columns readable and let the table scroll sideways. */}
          <table className="w-full min-w-[40rem] [&_.rounded-full]:whitespace-nowrap">
            <thead className="bg-gray-50/80 border-b border-gray-200">
              <tr>
                <th className="text-left px-6 py-3 text-xs font-semibold uppercase tracking-wider text-gray-500">Event</th>
                <th className="text-left px-6 py-3 text-xs font-semibold uppercase tracking-wider text-gray-500">Dates</th>
                <th className="text-left px-6 py-3 text-xs font-semibold uppercase tracking-wider text-gray-500">Status</th>
                <th className="text-left px-6 py-3 text-xs font-semibold uppercase tracking-wider text-gray-500">Featured</th>
                <th className="text-right px-6 py-3 text-xs font-semibold uppercase tracking-wider text-gray-500">Actions</th>
              </tr>
            </thead>
            <tbody>
              {filteredItems.map((item) => (
                <tr key={item.id} className="border-b border-gray-100 last:border-b-0 hover:bg-primary-50/40 transition-colors duration-150">
                  <td className="px-6 py-3">
                    <div className="flex items-center gap-3">
                      {item.image_path ? (
                        <img src={getImageUrl(item.image_path)} alt="" className="h-10 w-14 shrink-0 rounded-md object-cover bg-gray-100" loading="lazy" />
                      ) : (
                        <span className="flex h-10 w-14 shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-400" title="No cover image">
                          <FiImage size={15} aria-hidden="true" />
                        </span>
                      )}
                      <div className="min-w-0">
                        <p className="text-sm font-medium text-gray-900">{item.title}</p>
                        <p className="text-xs text-gray-400 mt-0.5">{item.municipality?.name || 'Province-wide'}</p>
                      </div>
                    </div>
                  </td>
                  <td className="px-6 py-4 text-sm text-gray-600">
                    {/* Each date stays whole; a range may wrap only between the two dates. */}
                    <span className="whitespace-nowrap">{item.start_date}</span>
                    {item.end_date && item.end_date !== item.start_date && (
                      <> <span className="whitespace-nowrap">{`\u2013 ${item.end_date}`}</span></>
                    )}
                  </td>
                  <td className="px-6 py-4">
                    <span className={`text-xs font-medium px-2.5 py-1 rounded-full capitalize ${item.status === 'published' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'}`}>
                      {item.status}
                    </span>
                  </td>
                  <td className="px-6 py-4 text-sm text-gray-600">
                    {item.is_featured ? <FiStar className="text-yellow-500 fill-yellow-500" size={15} /> : '\u2014'}
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