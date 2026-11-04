import { useEffect, useMemo, useState } from 'react'
import { FiSearch, FiEdit2, FiTrash2, FiCheck, FiX } from 'react-icons/fi'
import { getAdminHeroSlides, createHeroSlide, updateHeroSlide, deleteHeroSlide } from '../../api/admin'
import { getImageUrl } from '../../utils/helpers'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import toast from 'react-hot-toast'

const EMPTY_FORM = { title: '', subtitle: '', eyebrow: '', sort_order: 0, is_active: true, image: null }

export default function ManageHeroSlides() {
  const [items, setItems] = useState([])
  const [loading, setLoading] = useState(true)
  const [showForm, setShowForm] = useState(false)
  const [editingId, setEditingId] = useState(null)
  const [formData, setFormData] = useState(EMPTY_FORM)
  const [search, setSearch] = useState('')

  const filteredItems = useMemo(() => {
    const q = search.trim().toLowerCase()
    if (!q) return items
    return items.filter((item) => item.title?.toLowerCase().includes(q))
  }, [items, search])

  const loadData = async () => {
    setLoading(true)
    try {
      const data = await getAdminHeroSlides({ per_page: 100 })
      setItems(data.data || [])
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    loadData()
  }, [])

  const startEdit = (item) => {
    setEditingId(item.id)
    setFormData({
      title: item.title || '',
      subtitle: item.subtitle || '',
      eyebrow: item.eyebrow || '',
      sort_order: item.sort_order ?? 0,
      is_active: !!item.is_active,
      image: null,
    })
    setShowForm(true)
  }

  const closeForm = () => {
    setShowForm(false)
    setEditingId(null)
    setFormData(EMPTY_FORM)
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    const payload = new FormData()
    Object.keys(formData).forEach((key) => {
      if (key === 'image') return
      // FormData stringifies JS booleans as "true"/"false", which Laravel's
      // `boolean` validation rule rejects — send "1"/"0" instead.
      payload.append(key, key === 'is_active' ? (formData[key] ? '1' : '0') : formData[key])
    })
    if (formData.image) payload.append('image', formData.image)

    try {
      if (editingId) {
        await updateHeroSlide(editingId, payload)
        toast.success('Hero slide updated')
      } else {
        if (!formData.image) {
          toast.error('A banner image is required')
          return
        }
        await createHeroSlide(payload)
        toast.success('Hero slide created')
      }
      closeForm()
      loadData()
    } catch (err) {
      const errors = err.response?.data?.errors
      if (errors) Object.values(errors).flat().forEach((msg) => toast.error(msg))
      else toast.error(editingId ? 'Failed to update hero slide' : 'Failed to create hero slide')
    }
  }

  const handleDelete = async (id) => {
    if (!confirm('Delete this hero slide?')) return
    try {
      await deleteHeroSlide(id)
      toast.success('Hero slide deleted')
      loadData()
    } catch {
      toast.error('Failed to delete hero slide')
    }
  }

  if (loading) return <LoadingSpinner size="lg" />

  return (
    <div>
      <div className="flex flex-wrap items-end justify-between gap-4 mb-8">
        <div>
          <p className="text-primary-600 font-medium mb-2">Admin</p>
          <h1 className="text-2xl sm:text-3xl font-heading font-bold text-gray-900">Manage Hero Slides</h1>
        </div>
        <button onClick={() => (showForm ? closeForm() : setShowForm(true))} className="btn-primary">
          {showForm ? 'Close Form' : 'Add Hero Slide'}
        </button>
      </div>

      {showForm && (
        <form onSubmit={handleSubmit} className="bg-white rounded-xl p-5 sm:p-8 border border-gray-100 mb-8 space-y-5">
          <p className="text-sm font-medium text-gray-500">{editingId ? 'Editing hero slide' : 'New hero slide'}</p>
          <input className="input-field" placeholder="Eyebrow (small label above title)" value={formData.eyebrow} onChange={(e) => setFormData({ ...formData, eyebrow: e.target.value })} />
          <input className="input-field" placeholder="Title" value={formData.title} onChange={(e) => setFormData({ ...formData, title: e.target.value })} required />
          <textarea className="input-field" rows={3} placeholder="Subtitle / description" value={formData.subtitle} onChange={(e) => setFormData({ ...formData, subtitle: e.target.value })} />
          <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
            <input type="number" min="0" className="input-field" placeholder="Sort order" value={formData.sort_order} onChange={(e) => setFormData({ ...formData, sort_order: e.target.value })} />
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Banner Image {editingId && '(leave empty to keep current image)'}
              </label>
              <input
                type="file"
                accept="image/jpeg,image/png,image/webp"
                onChange={(e) => setFormData({ ...formData, image: e.target.files[0] || null })}
                className="input-field"
              />
            </div>
          </div>
          <label className="flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" checked={formData.is_active} onChange={(e) => setFormData({ ...formData, is_active: e.target.checked })} />
            Active (visible on the homepage)
          </label>
          <div className="flex gap-3">
            <button type="submit" className="btn-primary">{editingId ? 'Save Changes' : 'Save Hero Slide'}</button>
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
          placeholder="Search hero slides..."
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
                <th className="text-left px-6 py-3 text-xs font-semibold uppercase tracking-wider text-gray-500">Slide</th>
                <th className="text-left px-6 py-3 text-xs font-semibold uppercase tracking-wider text-gray-500">Sort Order</th>
                <th className="text-left px-6 py-3 text-xs font-semibold uppercase tracking-wider text-gray-500">Active</th>
                <th className="text-right px-6 py-3 text-xs font-semibold uppercase tracking-wider text-gray-500">Actions</th>
              </tr>
            </thead>
            <tbody>
              {filteredItems.map((item) => (
                <tr key={item.id} className="border-b border-gray-100 last:border-b-0 hover:bg-primary-50/40 transition-colors duration-150">
                  <td className="px-6 py-3">
                    <div className="flex items-center gap-3">
                      <div className="w-16 h-10 rounded-md overflow-hidden shrink-0 bg-gray-100">
                        <img src={getImageUrl(item.image_path)} alt={item.title} className="w-full h-full object-cover" />
                      </div>
                      <div className="min-w-0">
                        <p className="text-sm font-medium text-gray-900 truncate">{item.title}</p>
                        {item.eyebrow && <p className="text-xs text-gray-400 mt-0.5 truncate">{item.eyebrow}</p>}
                      </div>
                    </div>
                  </td>
                  <td className="px-6 py-4 text-sm text-gray-600">{item.sort_order}</td>
                  <td className="px-6 py-4 text-sm text-gray-600">
                    {item.is_active ? <FiCheck className="text-green-600" size={15} /> : <FiX className="text-gray-400" size={15} />}
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
