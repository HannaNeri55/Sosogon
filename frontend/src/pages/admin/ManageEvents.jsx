import { useEffect, useMemo, useState } from 'react'
import { FiSearch, FiEdit2, FiTrash2, FiStar } from 'react-icons/fi'
import { getAdminEvents, createEvent, updateEvent, deleteEvent, getMunicipalities } from '../../api/admin'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import toast from 'react-hot-toast'

const EMPTY_FORM = {
  title: '', description: '', location: '', municipality_id: '',
  start_date: '', end_date: '', is_featured: false, status: 'published',
}

export default function ManageEvents() {
  const [items, setItems] = useState([])
  const [municipalities, setMunicipalities] = useState([])
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
      const data = await getAdminEvents({ per_page: 100 })
      setItems(data.data || [])
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
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
    setShowForm(true)
  }

  const closeForm = () => {
    setShowForm(false)
    setEditingId(null)
    setFormData(EMPTY_FORM)
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    const payload = { ...formData, municipality_id: formData.municipality_id || null }
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
      else toast.error(editingId ? 'Failed to update event' : 'Failed to create event')
    }
  }

  const handleDelete = async (id) => {
    if (!confirm('Delete this event?')) return
    try {
      await deleteEvent(id)
      toast.success('Event deleted')
      loadData()
    } catch {
      toast.error('Failed to delete event')
    }
  }

  if (loading) return <LoadingSpinner size="lg" />

  return (
    <div>
      <div className="flex flex-wrap items-end justify-between gap-4 mb-8">
        <div>
          <p className="text-primary-600 font-medium mb-2">Admin</p>
          <h1 className="text-3xl sm:text-4xl font-heading font-bold text-gray-900">Manage Events</h1>
        </div>
        <button onClick={() => (showForm ? closeForm() : setShowForm(true))} className="btn-primary">
          {showForm ? 'Close Form' : 'Add Event'}
        </button>
      </div>

      {showForm && (
        <form onSubmit={handleSubmit} className="bg-white rounded-xl p-8 border border-gray-100 mb-8 space-y-5">
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
          <p className="text-xs text-gray-500">No photo upload here yet — events created or edited this way keep the default placeholder image until upload support is added to this form.</p>
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

      <div className="bg-white rounded-xl border border-gray-100 overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead className="bg-gray-50 border-b border-gray-100">
              <tr>
                <th className="text-left px-6 py-3 text-sm font-medium text-gray-700">Event</th>
                <th className="text-left px-6 py-3 text-sm font-medium text-gray-700">Dates</th>
                <th className="text-left px-6 py-3 text-sm font-medium text-gray-700">Status</th>
                <th className="text-left px-6 py-3 text-sm font-medium text-gray-700">Featured</th>
                <th className="text-right px-6 py-3 text-sm font-medium text-gray-700">Actions</th>
              </tr>
            </thead>
            <tbody>
              {filteredItems.map((item) => (
                <tr key={item.id} className="border-b border-gray-100 hover:bg-gray-50 transition-colors duration-150">
                  <td className="px-6 py-3">
                    <p className="text-sm font-medium text-gray-900">{item.title}</p>
                    <p className="text-xs text-gray-400 mt-0.5">{item.municipality?.name || 'Province-wide'}</p>
                  </td>
                  <td className="px-6 py-4 text-sm text-gray-600">
                    {item.start_date}{item.end_date && item.end_date !== item.start_date ? ` \u2013 ${item.end_date}` : ''}
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