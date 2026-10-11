import { useEffect, useMemo, useState } from 'react'
import { FiSearch, FiEdit2, FiTrash2, FiMapPin, FiX } from 'react-icons/fi'
import { getAdminActivities, createActivity, updateActivity, deleteActivity, getAdminDestinations } from '../../api/admin'
import { getImageUrl } from '../../utils/helpers'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import toast from 'react-hot-toast'

const EMPTY_FORM = {
  destination_id: '', name: '', description: '', type: 'outdoor',
  best_time_to_visit: '', what_to_wear: '', things_to_do: [], image: null, image_path: '',
}

export default function ManageActivities() {
  const [items, setItems] = useState([])
  const [destinations, setDestinations] = useState([])
  const [loading, setLoading] = useState(true)
  const [showForm, setShowForm] = useState(false)
  const [editingId, setEditingId] = useState(null)
  const [formData, setFormData] = useState(EMPTY_FORM)
  const [search, setSearch] = useState('')
  const [newThing, setNewThing] = useState('')

  const filteredItems = useMemo(() => {
    const q = search.trim().toLowerCase()
    if (!q) return items
    return items.filter((item) => item.name?.toLowerCase().includes(q))
  }, [items, search])

  const loadData = async () => {
    setLoading(true)
    try {
      const data = await getAdminActivities({ per_page: 100 })
      setItems(data.data || [])
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    loadData()
    getAdminDestinations({ per_page: 100 }).then((data) => setDestinations(data.data || []))
  }, [])

  const startEdit = (item) => {
    setEditingId(item.id)
    setFormData({
      destination_id: item.destination_id || '',
      name: item.name || '',
      description: item.description || '',
      type: item.type || 'outdoor',
      best_time_to_visit: item.best_time_to_visit || '',
      what_to_wear: item.what_to_wear || '',
      things_to_do: item.things_to_do ? item.things_to_do.split('\n').map((s) => s.trim()).filter(Boolean) : [],
      image: null,
      image_path: item.image_path || '',
    })
    setShowForm(true)
  }

  const closeForm = () => {
    setShowForm(false)
    setEditingId(null)
    setFormData(EMPTY_FORM)
    setNewThing('')
  }

  const addThing = () => {
    const value = newThing.trim()
    if (!value) return
    setFormData({ ...formData, things_to_do: [...formData.things_to_do, value] })
    setNewThing('')
  }

  const removeThing = (index) => {
    setFormData({ ...formData, things_to_do: formData.things_to_do.filter((_, i) => i !== index) })
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    try {
      const thingsToDo = formData.things_to_do.join('\n')
      const payload = new FormData()
      Object.keys(formData).forEach((key) => {
        if (key === 'image' || key === 'image_path' || key === 'things_to_do') return
        if (formData[key] !== '' && formData[key] !== null) payload.append(key, formData[key])
      })
      if (thingsToDo) payload.append('things_to_do', thingsToDo)
      if (formData.image) payload.append('image', formData.image)
      if (editingId) {
        await updateActivity(editingId, payload)
        toast.success('Activity updated')
      } else {
        await createActivity(payload)
        toast.success('Activity created')
      }
      closeForm()
      loadData()
    } catch (err) {
      const errors = err.response?.data?.errors
      if (errors) Object.values(errors).flat().forEach((msg) => toast.error(msg))
      else toast.error(editingId ? 'Failed to update activity' : 'Failed to create activity')
    }
  }

  const handleDelete = async (id) => {
    if (!confirm('Delete this activity?')) return
    try {
      await deleteActivity(id)
      toast.success('Activity deleted')
      loadData()
    } catch {
      toast.error('Failed to delete activity')
    }
  }

  if (loading) return <LoadingSpinner size="lg" />

  return (
    <div>
      <div className="flex flex-wrap items-end justify-between gap-4 mb-8">
        <div>
          <p className="text-primary-600 font-medium mb-2">Admin</p>
          <h1 className="text-3xl sm:text-4xl font-heading font-bold text-gray-900">Manage Activities</h1>
        </div>
        <button onClick={() => (showForm ? closeForm() : setShowForm(true))} className="btn-primary">
          {showForm ? 'Close Form' : 'Add Activity'}
        </button>
      </div>

      {showForm && (
        <form onSubmit={handleSubmit} className="bg-white rounded-xl p-5 sm:p-8 border border-gray-100 mb-8 space-y-5">
          <p className="text-sm font-medium text-gray-500">{editingId ? 'Editing activity' : 'New activity'}</p>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
            <select className="input-field" value={formData.destination_id} onChange={(e) => setFormData({ ...formData, destination_id: e.target.value })} required>
              <option value="">Select destination</option>
              {destinations.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
            </select>
            <input className="input-field" placeholder="Activity name" value={formData.name} onChange={(e) => setFormData({ ...formData, name: e.target.value })} required />
          </div>
          <textarea className="input-field" rows={3} placeholder="Description" value={formData.description} onChange={(e) => setFormData({ ...formData, description: e.target.value })} />
          <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">Type</label>
              <select className="input-field" value={formData.type} onChange={(e) => setFormData({ ...formData, type: e.target.value })} required>
                <option value="outdoor">Outdoor</option>
                <option value="indoor">Indoor</option>
              </select>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">Best time to visit</label>
              <input className="input-field" placeholder="e.g. Early morning or late afternoon" value={formData.best_time_to_visit} onChange={(e) => setFormData({ ...formData, best_time_to_visit: e.target.value })} />
            </div>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">What to wear</label>
            <input className="input-field" placeholder="e.g. Light clothing, swimwear, and sandals" value={formData.what_to_wear} onChange={(e) => setFormData({ ...formData, what_to_wear: e.target.value })} />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">Things to do</label>
            <div className="flex gap-2">
              <input
                className="input-field flex-1"
                placeholder="e.g. Watch the sunrise"
                value={newThing}
                onChange={(e) => setNewThing(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') {
                    e.preventDefault()
                    addThing()
                  }
                }}
              />
              <button type="button" onClick={addThing} className="px-4 py-2 border border-gray-200 rounded-lg text-gray-600 hover:bg-gray-50 whitespace-nowrap">
                Add Item
              </button>
            </div>
            {formData.things_to_do.length > 0 && (
              <ul className="mt-3 space-y-2">
                {formData.things_to_do.map((thing, index) => (
                  <li key={index} className="flex items-center justify-between gap-2 bg-gray-50 rounded-lg px-3 py-2 text-sm text-gray-700">
                    <span>{thing}</span>
                    <button type="button" onClick={() => removeThing(index)} className="text-gray-400 hover:text-red-600 shrink-0" title="Remove">
                      <FiX size={14} />
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">Photo</label>
            {(formData.image || formData.image_path) && (
              <img
                src={formData.image ? URL.createObjectURL(formData.image) : getImageUrl(formData.image_path)}
                alt=""
                className="w-20 h-20 rounded-lg object-cover mb-2 bg-gray-100"
              />
            )}
            <input
              type="file"
              accept="image/jpeg,image/png,image/webp"
              onChange={(e) => setFormData({ ...formData, image: e.target.files[0] })}
              className="input-field"
            />
            <p className="text-xs text-gray-500 mt-1">
              {editingId ? 'Optional. Upload a new photo to replace the current one.' : 'Optional. Automatically compressed to WebP with a thumbnail.'}
            </p>
          </div>
          <div className="flex gap-3">
            <button type="submit" className="btn-primary">{editingId ? 'Save Changes' : 'Save Activity'}</button>
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
        <input className="input-field pl-9" placeholder="Search activities..." value={search} onChange={(e) => setSearch(e.target.value)} />
      </div>

      <div className="bg-white rounded-xl border border-gray-100 overflow-hidden">
        <div className="overflow-x-auto">
          {/* Phones: keep columns readable and let the table scroll sideways. */}
          <table className="w-full min-w-[40rem] [&_.rounded-full]:whitespace-nowrap">
            <thead className="bg-gray-50 border-b border-gray-100">
              <tr>
                <th className="text-left px-6 py-3 text-sm font-medium text-gray-700">Activity</th>
                <th className="text-left px-6 py-3 text-sm font-medium text-gray-700">Destination</th>
                <th className="text-left px-6 py-3 text-sm font-medium text-gray-700">Type</th>
                <th className="text-right px-6 py-3 text-sm font-medium text-gray-700">Actions</th>
              </tr>
            </thead>
            <tbody>
              {filteredItems.map((item) => (
                <tr key={item.id} className="border-b border-gray-100 hover:bg-gray-50 transition-colors duration-150">
                  <td className="px-6 py-3">
                    <div className="flex items-center gap-3">
                      <img src={getImageUrl(item.image_path)} alt="" className="w-10 h-10 rounded-lg object-cover shrink-0 bg-gray-100" />
                      <p className="text-sm font-medium text-gray-900">{item.name}</p>
                    </div>
                  </td>
                  <td className="px-6 py-4 text-sm text-gray-600">
                    <span className="flex items-center gap-1"><FiMapPin size={12} />{item.destination?.name || '—'}</span>
                  </td>
                  <td className="px-6 py-4 text-sm text-gray-500 capitalize">{item.type || '—'}</td>
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