import { useEffect, useState } from 'react'
import { FiInfo, FiMapPin, FiPhone, FiUpload, FiFile } from 'react-icons/fi'
import { createSubmission } from '../../api/business'
import { getMunicipalities } from '../../api/admin'
import { SUBMISSION_TYPES } from '../../utils/constants'
import { useAuth } from '../../contexts/AuthContext'
import toast from 'react-hot-toast'

function SectionLabel({ icon: Icon, children }) {
  return (
    <div className="flex items-center gap-2 pb-3 mb-5 border-b border-gray-100">
      <Icon className="text-primary-600" size={16} />
      <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide">{children}</h2>
    </div>
  )
}

export default function SubmitListing({ onSuccess }) {
  const { user } = useAuth()
  const lockedType = user?.business_type
  const [municipalities, setMunicipalities] = useState([])
  const [loading, setLoading] = useState(false)
  const [formData, setFormData] = useState({
    business_name: '',
    type: lockedType || 'accommodation',
    description: '',
    address: '',
    municipality_id: '1',
    latitude: '',
    longitude: '',
    contact_number: '',
    operating_hours: '',
    business_permit: null,
    image: null,
  })

  useEffect(() => {
    getMunicipalities().then(setMunicipalities)
  }, [])

  const handleSubmit = async (e) => {
    e.preventDefault()
    const payload = new FormData()
    Object.keys(formData).forEach((key) => {
      if (formData[key] !== null) payload.append(key, formData[key])
    })

    setLoading(true)
    try {
      await createSubmission(payload)
      toast.success('Submission created successfully')
      onSuccess?.()
    } catch (e) {
      const errors = e.response?.data?.errors
      if (errors) Object.values(errors).flat().forEach((msg) => toast.error(msg))
      else toast.error('Failed to submit listing')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div>
      <div className="mb-8">
        <p className="text-primary-600 font-medium mb-2">Business Owner Portal</p>
        <h1 className="text-3xl sm:text-4xl font-heading font-bold text-gray-900">Submit Business Listing</h1>
      </div>

      <form onSubmit={handleSubmit} className="bg-white rounded-xl p-6 sm:p-8 border border-gray-100 space-y-8 max-w-4xl">
        <div>
          <SectionLabel icon={FiInfo}>Business Details</SectionLabel>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">Business Name</label>
              <input className="input-field" value={formData.business_name} onChange={(e) => setFormData({ ...formData, business_name: e.target.value })} required />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">Listing Type</label>
              <select
                className="input-field disabled:bg-gray-50 disabled:text-gray-500"
                value={formData.type}
                onChange={(e) => setFormData({ ...formData, type: e.target.value })}
                disabled={!!lockedType}
              >
                {SUBMISSION_TYPES.map((type) => (
                  <option key={type.value} value={type.value}>{type.label}</option>
                ))}
              </select>
              {lockedType && (
                <p className="text-xs text-gray-400 mt-1.5">
                  Your account is registered for this business type and cannot submit listings of a different type.
                </p>
              )}
            </div>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">Description</label>
            <textarea rows={5} className="input-field" value={formData.description} onChange={(e) => setFormData({ ...formData, description: e.target.value })} required />
          </div>
        </div>

        <div>
          <SectionLabel icon={FiMapPin}>Location</SectionLabel>
          <div className="mb-6">
            <label className="block text-sm font-medium text-gray-700 mb-2">Address</label>
            <input className="input-field" value={formData.address} onChange={(e) => setFormData({ ...formData, address: e.target.value })} required />
          </div>
          <div className="mb-6">
            <label className="block text-sm font-medium text-gray-700 mb-2">Municipality</label>
            <select className="input-field" value={formData.municipality_id} onChange={(e) => setFormData({ ...formData, municipality_id: e.target.value })}>
              {municipalities.map((m) => (
                <option key={m.id} value={m.id}>{m.name}</option>
              ))}
            </select>
          </div>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">Latitude</label>
              <input type="number" step="0.0000001" className="input-field" value={formData.latitude} onChange={(e) => setFormData({ ...formData, latitude: e.target.value })} required />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">Longitude</label>
              <input type="number" step="0.0000001" className="input-field" value={formData.longitude} onChange={(e) => setFormData({ ...formData, longitude: e.target.value })} required />
            </div>
          </div>
        </div>

        <div>
          <SectionLabel icon={FiPhone}>Contact & Documentation</SectionLabel>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">Contact Number</label>
              <input className="input-field" value={formData.contact_number} onChange={(e) => setFormData({ ...formData, contact_number: e.target.value })} required />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">Operating Hours</label>
              <input className="input-field" value={formData.operating_hours} onChange={(e) => setFormData({ ...formData, operating_hours: e.target.value })} />
            </div>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">Business Permit</label>
            <label className="flex items-center gap-3 border border-dashed border-gray-300 rounded-lg px-4 py-3 cursor-pointer hover:border-primary-300 hover:bg-primary-50/30 transition-colors">
              <FiUpload className="text-gray-400 shrink-0" size={18} />
              <span className="text-sm text-gray-600 flex items-center gap-1.5 truncate">
                {formData.business_permit ? (
                  <><FiFile size={14} className="text-primary-600 shrink-0" />{formData.business_permit.name}</>
                ) : (
                  'Click to upload permit (PDF or Word document)'
                )}
              </span>
              <input
                type="file"
                accept=".pdf,.doc,.docx"
                onChange={(e) => setFormData({ ...formData, business_permit: e.target.files[0] })}
                className="hidden"
                required
              />
            </label>
          </div>
        </div>

          <div className="mt-6">
            <label className="block text-sm font-medium text-gray-700 mb-2">Listing Photo</label>
            <label className="flex items-center gap-3 border border-dashed border-gray-300 rounded-lg px-4 py-3 cursor-pointer hover:border-primary-300 hover:bg-primary-50/30 transition-colors">
              <FiUpload className="text-gray-400 shrink-0" size={18} />
              <span className="text-sm text-gray-600 flex items-center gap-1.5 truncate">
                {formData.image ? (
                  <><FiFile size={14} className="text-primary-600 shrink-0" />{formData.image.name}</>
                ) : (
                  'Click to upload a photo of your business (optional)'
                )}
              </span>
              <input
                type="file"
                accept="image/jpeg,image/png,image/webp"
                onChange={(e) => setFormData({ ...formData, image: e.target.files[0] })}
                className="hidden"
              />
            </label>
          </div>

        <button type="submit" className="btn-primary" disabled={loading}>
          {loading ? 'Submitting...' : 'Submit for Approval'}
        </button>
      </form>
    </div>
  )
}
