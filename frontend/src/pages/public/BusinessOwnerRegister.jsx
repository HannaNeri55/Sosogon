import { useState, useEffect } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import { registerUser } from '../../api/auth'
import { getMunicipalities, getBarangays } from '../../api/admin'
import toast from 'react-hot-toast'
import { FiBriefcase, FiUser, FiLock, FiMapPin, FiFileText, FiUploadCloud, FiAlertTriangle, FiCheckCircle } from 'react-icons/fi'
import { showApiError } from '../../utils/apiErrors'
import { focusFirstInvalid } from '../../utils/formErrors'
import { ACCOUNT_RULES, ACCOUNT_SHOW_NOW } from '../../utils/authValidation'
import useLiveValidation from '../../hooks/useLiveValidation'
import AuthField from '../../components/auth/AuthField'
import useEmailAvailability from '../../hooks/useEmailAvailability'
import { PasswordFields, PersonalFields } from '../../components/auth/AccountFields'

const PERMIT_TYPES = ['pdf', 'doc', 'docx']
const PERMIT_MAX_MB = 10

/** The account rules plus the business details (the server's OwnerApplicationRules). */
const RULES = {
  ...ACCOUNT_RULES,
  business_name: (v) => (!v.business_name.trim() ? 'Enter your business name.' : v.business_name.trim().length > 255 ? 'Keep the business name to 255 characters or fewer.' : undefined),
  business_address: (v) => (!v.business_address.trim() ? 'Enter the business address.' : v.business_address.trim().length > 500 ? 'Keep the address to 500 characters or fewer.' : undefined),
  municipality_id: (v) => (!v.municipality_id ? 'Choose the municipality.' : undefined),
  barangay_id: (v) => (!v.barangay_id ? 'Choose the barangay.' : undefined),
  business_permit_number: (v) => (!v.business_permit_number.trim() ? 'Enter the business permit number.' : v.business_permit_number.trim().length > 100 ? 'Keep the permit number to 100 characters or fewer.' : undefined),
  business_permit_file: (v) => {
    const file = v.business_permit_file
    if (!file) return 'Upload your business permit.'
    if (!PERMIT_TYPES.includes(file.name.split('.').pop().toLowerCase())) return 'Upload a PDF or Word document (.pdf, .doc or .docx).'
    if (file.size > PERMIT_MAX_MB * 1024 * 1024) return `The file is too large. Upload one of ${PERMIT_MAX_MB} MB or less.`
    return undefined
  },
}

// Choosing an option or a file is a finished action, so its problem shows at once.
const SHOW_NOW = { ...ACCOUNT_SHOW_NOW, business_permit_file: (v) => !!v.business_permit_file }

function SectionHeader({ icon: Icon, step, title }) {
  return (
    <div className="flex items-center gap-2.5 mb-4">
      <div className="w-6 h-6 rounded-full bg-primary-600 text-white text-xs font-semibold flex items-center justify-center shrink-0">
        {step}
      </div>
      <Icon size={16} className="text-primary-600" />
      <h3 className="text-sm font-semibold text-gray-800">{title}</h3>
    </div>
  )
}

/** A required <select> with the same label, error and hint layout as AuthField. */
function SelectField({ id, label, error, hint, children, ...selectProps }) {
  return (
    <div>
      <label htmlFor={id} className="block text-sm font-medium text-gray-700 mb-1.5">
        {label} <span className="text-red-500" aria-hidden="true">*</span>
      </label>
      <select
        id={id}
        required
        className={`input-field ${error ? 'input-error' : ''}`}
        aria-invalid={!!error}
        aria-describedby={error ? `${id}-error` : hint ? `${id}-hint` : undefined}
        {...selectProps}
      >
        {children}
      </select>
      {error ? <p id={`${id}-error`} className="field-error" role="alert">{error}</p> : hint && <p id={`${id}-hint`} className="mt-1.5 text-xs text-gray-500">{hint}</p>}
    </div>
  )
}

export default function BusinessOwnerRegister() {
  const navigate = useNavigate()
  const [municipalities, setMunicipalities] = useState([])
  const [barangays, setBarangays] = useState([])
  const [loading, setLoading] = useState(false)
  const [agreed, setAgreed] = useState(false)
  const [formData, setFormData] = useState({
    name: '',
    email: '',
    phone: '',
    password: '',
    password_confirmation: '',
    role: 'business_owner',
    business_name: '',
    business_type: 'accommodation',
    business_address: '',
    municipality_id: '',
    barangay_id: '',
    business_permit_number: '',
    business_permit_file: null,
  })
  // Each field is checked while the user types (see useLiveValidation); the server checks again.
  const live = useLiveValidation(formData, RULES, { now: SHOW_NOW })
  useEmailAvailability(formData.email, live)

  useEffect(() => {
    getMunicipalities().then(setMunicipalities)
  }, [])

  useEffect(() => {
    if (formData.municipality_id) {
      getBarangays(formData.municipality_id).then(setBarangays)
    }
  }, [formData.municipality_id])

  const handleSubmit = async (e) => {
    e.preventDefault()
    if (!live.submit()) {
      focusFirstInvalid()
      return
    }

    setLoading(true)
    try {
      const payload = new FormData()
      payload.append('name', formData.name.trim())
      payload.append('email', formData.email.trim())
      payload.append('phone', formData.phone)
      // Sent exactly as typed.
      payload.append('password', formData.password)
      payload.append('password_confirmation', formData.password_confirmation)
      payload.append('role', 'business_owner')
      payload.append('business_name', formData.business_name)
      payload.append('business_type', formData.business_type)
      payload.append('business_address', formData.business_address)
      payload.append('municipality_id', formData.municipality_id)
      payload.append('barangay_id', formData.barangay_id)
      payload.append('business_permit_number', formData.business_permit_number)
      if (formData.business_permit_file) {
        payload.append('business_permit_file', formData.business_permit_file)
      }

      await registerUser(payload)
      toast.success('Registration submitted! Your account is pending admin approval.')
      navigate('/registration-pending')
    } catch (e) {
      const serverErrors = e.response?.status === 422 && e.response.data?.errors
      if (serverErrors) {
        live.setServerErrors(serverErrors)
        focusFirstInvalid()
      } else {
        showApiError(e, 'Registration failed')
      }
    } finally {
      setLoading(false)
    }
  }

  const handleInputChange = (name, value) => {
    setFormData((current) => ({ ...current, [name]: value }))
    live.changed(name)
  }

  const text = (name) => ({
    name,
    value: formData[name],
    error: live.errors[name],
    onChange: (e) => handleInputChange(name, e.target.value),
    onBlur: () => live.blurred(name),
  })

  return (
    <div className="min-h-screen bg-gradient-to-br from-gray-50 via-gray-50 to-primary-50/40 flex items-center justify-center px-4 py-12">
      <div className="max-w-2xl w-full bg-white rounded-2xl shadow-lg shadow-gray-200/60 border border-gray-100 overflow-hidden">
        <div className="h-1.5 bg-gradient-to-r from-primary-500 to-primary-600" />
        <div className="p-6 sm:p-8">
          <div className="text-center mb-8">
            <div className="w-14 h-14 rounded-full bg-primary-50 ring-4 ring-primary-50/50 flex items-center justify-center mx-auto mb-4">
              <FiBriefcase size={26} className="text-primary-600" />
            </div>
            <h1 className="text-2xl font-heading font-bold text-gray-900 mb-2">Register your business</h1>
            <p className="text-gray-500 text-sm">Submit your details for verification — your account requires admin approval</p>
          </div>

          <form onSubmit={handleSubmit} className="space-y-8" noValidate>
            <div>
              <SectionHeader icon={FiUser} step={1} title="Personal Information" />
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <PersonalFields idPrefix="owner" values={formData} live={live} onChange={handleInputChange} />
              </div>
            </div>

            <div className="pt-8 border-t border-gray-100">
              <SectionHeader icon={FiBriefcase} step={2} title="Business Information" />
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <AuthField
                  id="owner-business-name"
                  label="Business Name"
                  required
                  icon={FiBriefcase}
                  maxLength={255}
                  placeholder="e.g. Bulan Bay Inn"
                  hint="The name customers know your business by."
                  {...text('business_name')}
                />
                <SelectField
                  id="owner-business-type"
                  label="Business Type"
                  name="business_type"
                  value={formData.business_type}
                  error={live.errors.business_type}
                  onChange={(e) => handleInputChange('business_type', e.target.value)}
                >
                  <option value="accommodation">Accommodation</option>
                  <option value="food_beverage">Food and Beverage</option>
                  <option value="retail_product">Retail / Local Products</option>
                </SelectField>
                <AuthField
                  id="owner-business-address"
                  label="Business Address"
                  required
                  icon={FiMapPin}
                  maxLength={500}
                  placeholder="e.g. Purok 3, Rizal St."
                  hint="Street, purok or landmark. Choose the municipality and barangay below."
                  {...text('business_address')}
                />
                <SelectField
                  id="owner-municipality"
                  label="Municipality"
                  name="municipality_id"
                  value={formData.municipality_id}
                  error={live.errors.municipality_id}
                  onBlur={() => live.blurred('municipality_id')}
                  onChange={(e) => {
                    setFormData((current) => ({ ...current, municipality_id: e.target.value, barangay_id: '' }))
                    live.changed('municipality_id')
                    live.blurred('municipality_id')
                  }}
                >
                  <option value="">Select Municipality</option>
                  {municipalities.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                </SelectField>
                <SelectField
                  id="owner-barangay"
                  label="Barangay"
                  name="barangay_id"
                  value={formData.barangay_id}
                  error={live.errors.barangay_id}
                  hint={!formData.municipality_id ? 'Choose the municipality first.' : undefined}
                  onBlur={() => live.blurred('barangay_id')}
                  onChange={(e) => {
                    handleInputChange('barangay_id', e.target.value)
                    live.blurred('barangay_id')
                  }}
                >
                  <option value="">Select Barangay</option>
                  {barangays.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                </SelectField>
                <AuthField
                  id="owner-permit-number"
                  label="Business Permit Number"
                  required
                  icon={FiFileText}
                  maxLength={100}
                  placeholder="As printed on your permit"
                  hint="Copy it exactly as printed on your Mayor's or Business Permit."
                  {...text('business_permit_number')}
                />
              </div>
              <div className="mt-4">
                <label htmlFor="business_permit_file" className="block text-sm font-medium text-gray-700 mb-1.5">
                  Business Permit Document <span className="text-red-500" aria-hidden="true">*</span>
                </label>
                <label
                  htmlFor="business_permit_file"
                  className={`flex items-center gap-3 px-4 py-3.5 border-2 border-dashed rounded-lg cursor-pointer transition-colors ${
                    live.errors.business_permit_file ? 'border-red-300 bg-red-50/50' : 'border-gray-200 hover:border-primary-300 hover:bg-primary-50/30'
                  }`}
                >
                  {formData.business_permit_file && !live.errors.business_permit_file ? (
                    <FiCheckCircle size={18} className="text-primary-600 shrink-0" />
                  ) : (
                    <FiUploadCloud size={18} className="text-gray-400 shrink-0" />
                  )}
                  <span className="min-w-0 text-sm text-gray-600 leading-snug wrap-anywhere">
                    {formData.business_permit_file ? formData.business_permit_file.name : 'Click to upload PDF or Word document'}
                  </span>
                  <input
                    id="business_permit_file"
                    name="business_permit_file"
                    type="file"
                    accept=".pdf,.doc,.docx"
                    className="sr-only"
                    aria-invalid={!!live.errors.business_permit_file}
                    aria-describedby={live.errors.business_permit_file ? 'business_permit_file-error' : 'business_permit_file-hint'}
                    onChange={(e) => handleInputChange('business_permit_file', e.target.files[0] || null)}
                  />
                </label>
                {live.errors.business_permit_file ? (
                  <p id="business_permit_file-error" className="field-error" role="alert">{live.errors.business_permit_file}</p>
                ) : (
                  <p id="business_permit_file-hint" className="mt-1.5 text-xs text-gray-500">PDF or Word document (.pdf, .doc, .docx), up to {PERMIT_MAX_MB} MB.</p>
                )}
              </div>
            </div>

            <div className="pt-8 border-t border-gray-100">
              <SectionHeader icon={FiLock} step={3} title="Account Security" />
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4 items-start">
                <PasswordFields idPrefix="owner" values={formData} live={live} onChange={handleInputChange} />
              </div>
            </div>

            <div className="flex gap-3 bg-amber-50 border border-amber-200 rounded-lg p-4 text-sm text-amber-800">
              <FiAlertTriangle size={18} className="shrink-0 mt-0.5" />
              <span>Your account will be set to "Pending" status after submission. You will not be able to log in until an administrator reviews and approves your application.</span>
            </div>

            <label className="flex items-start gap-2.5 text-sm text-gray-600">
              <input
                type="checkbox"
                checked={agreed}
                onChange={(e) => setAgreed(e.target.checked)}
                className="mt-0.5 h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500"
              />
              <span>
                I agree to the{' '}
                <Link to="/privacy-policy" className="text-primary-600 font-medium hover:text-primary-700">
                  Privacy Policy
                </Link>{' '}
                and{' '}
                <Link to="/terms-and-conditions" className="text-primary-600 font-medium hover:text-primary-700">
                  Terms &amp; Conditions
                </Link>
              </span>
            </label>

            <button type="submit" className="btn-primary w-full justify-center" disabled={loading || !agreed}>
              {loading ? 'Submitting...' : 'Submit Registration for Approval'}
            </button>
          </form>

          <p className="text-center text-sm text-gray-500 mt-6">
            Already have an account?{' '}
            <Link to="/login" className="text-primary-600 font-medium">Login</Link>
          </p>
        </div>
      </div>
    </div>
  )
}
