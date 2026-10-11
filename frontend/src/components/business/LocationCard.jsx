import { useState } from 'react'
import { FiMapPin, FiEdit2 } from 'react-icons/fi'
import { getMunicipalities } from '../../api/admin'
import toast from 'react-hot-toast'

export default function LocationCard({ address, municipality, municipalityId, onSave }) {
  const [editing, setEditing] = useState(false)
  const [municipalities, setMunicipalities] = useState([])
  const [form, setForm] = useState({ address, municipality_id: municipalityId })
  const [saving, setSaving] = useState(false)

  const startEditing = () => {
    setForm({ address, municipality_id: municipalityId })
    if (municipalities.length === 0) getMunicipalities().then(setMunicipalities)
    setEditing(true)
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    setSaving(true)
    try {
      await onSave(form)
      toast.success('Location updated')
      setEditing(false)
    } catch (err) {
      const errors = err.response?.data?.errors
      if (errors) Object.values(errors).flat().forEach((msg) => toast.error(msg))
      else toast.error('Failed to update location')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="bg-white rounded-xl p-6 border border-gray-100">
      <div className="flex items-center justify-between mb-4">
        <h2 className="text-lg font-heading font-semibold text-gray-900">Location</h2>
        {!editing && (
          <button
            type="button"
            onClick={startEditing}
            className="flex items-center gap-1.5 text-sm font-medium text-primary-600 hover:text-primary-700"
          >
            <FiEdit2 size={14} /> Edit
          </button>
        )}
      </div>

      {!editing ? (
        <div className="flex items-start gap-3 bg-gray-50 border border-gray-100 rounded-lg p-4 text-sm">
          <FiMapPin className="text-gray-400 shrink-0 mt-0.5" size={16} />
          <div>
            <p className="text-gray-900 font-medium">{address}</p>
            <p className="text-gray-500 mt-0.5">{municipality?.name}, Sorsogon Province</p>
          </div>
        </div>
      ) : (
        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">Address</label>
            <input
              className="input-field"
              value={form.address}
              onChange={(e) => setForm({ ...form, address: e.target.value })}
              required
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">Municipality</label>
            <select
              className="input-field"
              value={form.municipality_id}
              onChange={(e) => setForm({ ...form, municipality_id: e.target.value })}
              required
            >
              <option value="" disabled>Select municipality</option>
              {municipalities.map((m) => (
                <option key={m.id} value={m.id}>{m.name}</option>
              ))}
            </select>
          </div>
          <div className="flex items-center gap-3">
            <button type="submit" className="btn-primary" disabled={saving}>
              {saving ? 'Saving...' : 'Save Location'}
            </button>
            <button
              type="button"
              className="btn-secondary"
              onClick={() => {
                setEditing(false)
                setForm({ address, municipality_id: municipalityId })
              }}
              disabled={saving}
            >
              Cancel
            </button>
          </div>
        </form>
      )}
    </div>
  )
}
