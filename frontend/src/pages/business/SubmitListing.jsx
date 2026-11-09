
import { useEffect, useState } from 'react'
import {
  FiInfo,
  FiMapPin,
  FiPhone,
  FiUpload,
  FiFile,
  FiSend,
  FiLoader,
} from 'react-icons/fi'
import { createSubmission, getOwnerApplication } from '../../api/business'
import { getMunicipalities } from '../../api/admin'
import { SUBMISSION_TYPES } from '../../utils/constants'
import { useAuth } from '../../contexts/AuthContext'
import toast from 'react-hot-toast'
import MapLocationPicker from '../../components/maps/MapLocationPicker'

function SectionLabel({ icon: Icon, children }) {
  return (
    <div className="flex items-center gap-2 pb-3 mb-5 border-b border-gray-100">
      <Icon className="text-primary-600" size={16} />
      <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide">
        {children}
      </h2>
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

  // Approved registration: its permit covers this submission, and its details prefill the form.
  const [registration, setRegistration] = useState(null)
  const permitOnFile = registration?.status === 'approved' && registration?.has_permit

  useEffect(() => {
    getMunicipalities().then(setMunicipalities)
    getOwnerApplication()
      .then(({ application }) => {
        if (application?.status !== 'approved') return
        setRegistration(application)
        setFormData((current) => ({
          ...current,
          business_name: current.business_name || application.business_name || '',
          address: current.address || application.business_address || '',
          municipality_id: application.municipality_id ? String(application.municipality_id) : current.municipality_id,
        }))
      })
      .catch(() => {})
  }, [])

  const handleSubmit = async (e) => {
    e.preventDefault()

    const payload = new FormData()

    Object.keys(formData).forEach((key) => {
      if (formData[key] !== null) {
        payload.append(key, formData[key])
      }
    })

    setLoading(true)

    try {
      await createSubmission(payload)

      toast.success('Submission created successfully')

      onSuccess?.()
    } catch (e) {
      const errors = e.response?.data?.errors

      if (errors) {
        Object.values(errors)
          .flat()
          .forEach((msg) => toast.error(msg))
      } else {
        toast.error('Failed to submit listing')
      }
    } finally {
      setLoading(false)
    }
  }

  return (
    <div>
      <div className="mb-8">
        <p className="text-xs font-semibold uppercase tracking-wider text-secondary-500 mb-2">
          Business Owner Portal
        </p>

        <h1 className="text-2xl sm:text-3xl font-heading font-bold text-gray-900">
          Submit Business Listing
        </h1>
      </div>

      {registration && (
        <p className="mb-6 max-w-4xl rounded-lg border border-primary-100 bg-primary-50 px-4 py-3 text-sm text-primary-800">
          We filled in some details from your approved registration. Please review and confirm them before submitting.
        </p>
      )}

      <form
        onSubmit={handleSubmit}
        className="bg-white rounded-2xl p-6 sm:p-8 border border-gray-200/70 space-y-8 max-w-4xl shadow-(--shadow-card)"
      >
        {/* BUSINESS DETAILS */}
        <div>
          <SectionLabel icon={FiInfo}>
            Business Details
          </SectionLabel>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Business Name
              </label>

              <input
                className="input-field"
                value={formData.business_name}
                onChange={(e) =>
                  setFormData({
                    ...formData,
                    business_name: e.target.value,
                  })
                }
                required
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Listing Type
              </label>

              <select
                className="input-field disabled:bg-gray-50 disabled:text-gray-500"
                value={formData.type}
                onChange={(e) =>
                  setFormData({
                    ...formData,
                    type: e.target.value,
                  })
                }
                disabled={!!lockedType}
              >
                {SUBMISSION_TYPES.map((type) => (
                  <option
                    key={type.value}
                    value={type.value}
                  >
                    {type.label}
                  </option>
                ))}
              </select>

              {lockedType && (
                <p className="text-xs text-gray-400 mt-1.5">
                  Your account is registered for this business
                  type and cannot submit listings of a
                  different type.
                </p>
              )}
            </div>
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Description
            </label>

            <textarea
              rows={5}
              className="input-field"
              value={formData.description}
              onChange={(e) =>
                setFormData({
                  ...formData,
                  description: e.target.value,
                })
              }
              required
            />
          </div>
        </div>

        {/* LOCATION */}
        <div>
          <SectionLabel icon={FiMapPin}>
            Location
          </SectionLabel>

          <div className="mb-6">
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Address
            </label>

            <input
              className="input-field"
              value={formData.address}
              onChange={(e) =>
                setFormData({
                  ...formData,
                  address: e.target.value,
                })
              }
              required
            />
          </div>

          <div className="mb-6">
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Municipality
            </label>

            <select
              className="input-field"
              value={formData.municipality_id}
              onChange={(e) =>
                setFormData({
                  ...formData,
                  municipality_id: e.target.value,
                })
              }
            >
              {municipalities.map((m) => (
                <option
                  key={m.id}
                  value={m.id}
                >
                  {m.name}
                </option>
              ))}
            </select>
          </div>

          <div>
            <p className="block text-sm font-medium text-gray-700 mb-2">Exact location of your business</p>
            <MapLocationPicker
              required
              latitude={formData.latitude}
              longitude={formData.longitude}
              searchHint={formData.business_name}
              onChange={({ latitude, longitude }) => setFormData((prev) => ({ ...prev, latitude, longitude }))}
              onAddressFound={(found) => setFormData((prev) => (prev.address ? prev : { ...prev, address: found }))}
            />
          </div>
        </div>

        {/* CONTACT & DOCUMENTATION */}
        <div>
          <SectionLabel icon={FiPhone}>
            Contact & Documentation
          </SectionLabel>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Contact Number
              </label>

              <input
                className="input-field"
                value={formData.contact_number}
                onChange={(e) =>
                  setFormData({
                    ...formData,
                    contact_number: e.target.value,
                  })
                }
                required
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Operating Hours
              </label>

              <input
                className="input-field"
                value={formData.operating_hours}
                onChange={(e) =>
                  setFormData({
                    ...formData,
                    operating_hours: e.target.value,
                  })
                }
              />
            </div>
          </div>

          {permitOnFile ? (
          <div className="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 flex items-start gap-2">
            <FiFile className="shrink-0 mt-0.5" size={16} aria-hidden="true" />
            <span>
              Business permit: the permit you submitted at registration (no. {registration.business_permit_number}) is already on file. You don't need to upload it again.
            </span>
          </div>
          ) : (
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Business Permit
            </label>

            <label className="flex items-center gap-3 border border-dashed border-gray-300 rounded-lg px-4 py-3 cursor-pointer hover:border-primary-300 hover:bg-primary-50/30 transition-colors">
              <FiUpload
                className="text-gray-400 shrink-0"
                size={18}
              />

              <span className="min-w-0 text-sm text-gray-600 flex items-center gap-1.5 leading-snug wrap-anywhere">
                {formData.business_permit ? (
                  <>
                    <FiFile
                      size={14}
                      className="text-primary-600 shrink-0"
                    />
                    {formData.business_permit.name}
                  </>
                ) : (
                  'Click to upload permit (PDF or Word document)'
                )}
              </span>

              <input
                type="file"
                accept=".pdf,.doc,.docx"
                onChange={(e) =>
                  setFormData({
                    ...formData,
                    business_permit: e.target.files[0],
                  })
                }
                className="hidden"
                required
              />
            </label>
          </div>
          )}
        </div>

        {/* LISTING PHOTO */}
        <div className="mt-6">
          <label className="block text-sm font-medium text-gray-700 mb-2">
            Listing Photo
          </label>

          <label className="flex items-center gap-3 border border-dashed border-gray-300 rounded-lg px-4 py-3 cursor-pointer hover:border-primary-300 hover:bg-primary-50/30 transition-colors">
            <FiUpload
              className="text-gray-400 shrink-0"
              size={18}
            />

            <span className="min-w-0 text-sm text-gray-600 flex items-center gap-1.5 leading-snug wrap-anywhere">
              {formData.image ? (
                <>
                  <FiFile
                    size={14}
                    className="text-primary-600 shrink-0"
                  />
                  {formData.image.name}
                </>
              ) : (
                'Click to upload a photo of your business (optional)'
              )}
            </span>

            <input
              type="file"
              accept="image/jpeg,image/png,image/webp"
              onChange={(e) =>
                setFormData({
                  ...formData,
                  image: e.target.files[0],
                })
              }
              className="hidden"
            />
          </label>
        </div>

        {/* SUBMIT BUTTON */}
        <div className="pt-2">
          <button
            type="submit"
            disabled={loading}
            className="btn-primary w-full sm:w-auto min-h-[46px] px-6 py-3 inline-flex items-center justify-center gap-2 text-sm font-semibold disabled:opacity-60 disabled:cursor-not-allowed"
          >
            {loading ? (
              <>
                <FiLoader
                  size={17}
                  className="animate-spin"
                />
                Submitting Listing...
              </>
            ) : (
              <>
                <FiSend size={17} />
                Submit Business Listing
              </>
            )}
          </button>
        </div>
      </form>
    </div>
  )
}
