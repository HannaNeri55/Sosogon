import { useEffect, useState } from 'react'
import { FiUpload, FiTrash2 } from 'react-icons/fi'
import { getMyAccommodation, updateMyAccommodation, updateMyAccommodationLocation, deleteAccommodationImage } from '../../../api/accommodationOwner'
import { getImageUrl } from '../../../utils/helpers'
import LoadingSpinner from '../../../components/common/LoadingSpinner'
import AccountProfileCard from '../../../components/business/AccountProfileCard'
import LocationCard from '../../../components/business/LocationCard'
import toast from 'react-hot-toast'

const STATUS_STYLES = {
  approved: 'bg-green-100 text-green-700',
  pending: 'bg-yellow-100 text-yellow-700',
  rejected: 'bg-red-100 text-red-700',
  archived: 'bg-gray-100 text-gray-700',
}

export default function MyProperty() {
  const [accommodation, setAccommodation] = useState(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [form, setForm] = useState({ name: '', description: '', website: '' })
  const [newImages, setNewImages] = useState([])

  const load = () =>
    getMyAccommodation().then((data) => {
      setAccommodation(data)
      setForm({ name: data.name, description: data.description, website: data.website || '' })
    })

  useEffect(() => {
    load().finally(() => setLoading(false))
  }, [])

  const handleSubmit = async (e) => {
    e.preventDefault()
    const payload = new FormData()
    payload.append('name', form.name)
    payload.append('description', form.description)
    payload.append('website', form.website)
    newImages.forEach((file) => payload.append('images[]', file))

    setSaving(true)
    try {
      const res = await updateMyAccommodation(payload)
      toast.success(res.message)
      setNewImages([])
      load()
    } catch (err) {
      const errors = err.response?.data?.errors
      if (errors) Object.values(errors).flat().forEach((msg) => toast.error(msg))
      else toast.error('Failed to update property')
    } finally {
      setSaving(false)
    }
  }

  const handleLocationSave = async (data) => {
    const res = await updateMyAccommodationLocation(data)
    setAccommodation(res.accommodation)
  }

  const handleDeleteImage = async (imageId) => {
    if (!confirm('Remove this photo?')) return
    try {
      await deleteAccommodationImage(imageId)
      toast.success('Photo removed')
      load()
    } catch {
      toast.error('Failed to remove photo')
    }
  }

  if (loading) return <LoadingSpinner size="lg" />
  if (!accommodation) return null

  return (
    <div>
      <div className="flex items-center justify-between flex-wrap gap-3 mb-8">
        <div>
          <p className="text-primary-600 font-medium mb-2">Accommodation Owner Portal</p>
          <h1 className="text-3xl font-heading font-bold text-gray-900">Profile &amp; Property</h1>
        </div>
        <span className={`text-xs font-medium px-3 py-1.5 rounded-full capitalize ${STATUS_STYLES[accommodation.status] || 'bg-gray-100 text-gray-700'}`}>
          {accommodation.status}
        </span>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div className="lg:col-span-1 space-y-6">
          <AccountProfileCard />
          <LocationCard
            address={accommodation.address}
            municipality={accommodation.municipality}
            municipalityId={accommodation.municipality_id}
            onSave={handleLocationSave}
          />
        </div>

        <form onSubmit={handleSubmit} className="lg:col-span-2 bg-white rounded-xl p-6 border border-gray-100 space-y-6 h-fit">
          <h2 className="text-lg font-heading font-semibold text-gray-900">Property Details</h2>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">Establishment Name</label>
            <input className="input-field" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required />
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">Description</label>
            <textarea
              rows={6}
              className="input-field"
              value={form.description}
              onChange={(e) => setForm({ ...form, description: e.target.value })}
              required
              minLength={50}
            />
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">Website (optional)</label>
            <input
              type="url"
              className="input-field"
              placeholder="https://..."
              value={form.website}
              onChange={(e) => setForm({ ...form, website: e.target.value })}
            />
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">Photos</label>
            {accommodation.images?.length > 0 && (
              <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
                {accommodation.images.map((img) => (
                  <div key={img.id} className="relative group">
                    <img src={getImageUrl(img)} alt="" className="w-full h-24 object-cover rounded-lg bg-gray-100" />
                    <button
                      type="button"
                      onClick={() => handleDeleteImage(img.id)}
                      className="absolute top-1.5 right-1.5 bg-white/90 text-red-600 p-1.5 rounded-full opacity-0 group-hover:opacity-100 transition-opacity"
                      title="Remove photo"
                    >
                      <FiTrash2 size={13} />
                    </button>
                  </div>
                ))}
              </div>
            )}
            <label className="flex items-center gap-3 border border-dashed border-gray-300 rounded-lg px-4 py-3 cursor-pointer hover:border-primary-300 hover:bg-primary-50/30 transition-colors">
              <FiUpload className="text-gray-400 shrink-0" size={18} />
              <span className="text-sm text-gray-600 truncate">
                {newImages.length > 0 ? `${newImages.length} new photo(s) selected` : 'Click to add photos (JPEG, PNG, WebP)'}
              </span>
              <input
                type="file"
                multiple
                accept="image/jpeg,image/png,image/webp"
                onChange={(e) => setNewImages(Array.from(e.target.files))}
                className="hidden"
              />
            </label>
          </div>

          {accommodation.status !== 'pending' && (
            <p className="text-xs text-gray-500">Saving changes will require the Tourism Office to review and approve your property again.</p>
          )}

          <button type="submit" className="btn-primary" disabled={saving}>
            {saving ? 'Saving...' : 'Save Changes'}
          </button>
        </form>
      </div>
    </div>
  )
}
