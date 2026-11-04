import { useEffect, useState } from 'react'
import { FiAlertTriangle, FiClock, FiFileText, FiUploadCloud } from 'react-icons/fi'
import toast from 'react-hot-toast'
import { getOwnerApplication, downloadOwnerPermit, resubmitOwnerApplication } from '../../api/business'
import { getMunicipalities, getBarangays } from '../../api/admin'
import LoadingSpinner from '../../components/common/LoadingSpinner'

/**
 * Shown instead of the dashboard while a Business Owner's registration is not
 * approved. A rejected owner sees the reason and can correct and resubmit.
 */
export default function ApplicationStatus() {
  const [data, setData] = useState(null)
  const [form, setForm] = useState(null)
  const [permit, setPermit] = useState(null)
  const [municipalities, setMunicipalities] = useState([])
  const [barangays, setBarangays] = useState([])
  const [errors, setErrors] = useState({})
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    getOwnerApplication().then((res) => {
      setData(res)
      const a = res.application
      if (a) {
        setForm({
          business_name: a.business_name,
          business_address: a.business_address,
          municipality_id: String(a.municipality_id ?? ''),
          barangay_id: a.barangay_id ? String(a.barangay_id) : '',
          business_permit_number: a.business_permit_number,
        })
      }
    })
    getMunicipalities().then(setMunicipalities)
  }, [])

  useEffect(() => {
    if (form?.municipality_id) getBarangays(form.municipality_id).then(setBarangays)
  }, [form?.municipality_id])

  const viewPermit = async () => {
    try {
      const blob = await downloadOwnerPermit()
      window.open(URL.createObjectURL(blob), '_blank', 'noopener')
    } catch {
      toast.error('Could not open your business permit.')
    }
  }

  const resubmit = async (e) => {
    e.preventDefault()
    setSaving(true)
    setErrors({})
    try {
      const payload = new FormData()
      Object.entries(form).forEach(([key, value]) => value !== '' && payload.append(key, value))
      if (permit) payload.append('business_permit_file', permit)
      const res = await resubmitOwnerApplication(payload)
      toast.success(res.message)
      setData((current) => ({ ...current, application: res.application }))
    } catch (err) {
      setErrors(err.response?.data?.errors || {})
      toast.error(err.response?.data?.message || 'Could not resubmit your application.')
    } finally {
      setSaving(false)
    }
  }

  if (!data) return <LoadingSpinner size="lg" />

  const application = data.application
  const rejected = application?.status === 'rejected'
  const field = (name, label, props = {}) => (
    <label className="block">
      <span className="block text-sm font-medium text-gray-700 mb-1">{label}</span>
      <input className="input-field" value={form[name]} onChange={(e) => setForm({ ...form, [name]: e.target.value })} {...props} />
      {errors[name] && <span className="text-red-600 text-xs mt-1 block">{errors[name][0]}</span>}
    </label>
  )

  return (
    <div className="bg-white rounded-2xl border border-gray-200 p-6 sm:p-8 space-y-6">
      <div className="flex items-start gap-3">
        {rejected ? <FiAlertTriangle className="text-red-600 shrink-0 mt-1" size={22} /> : <FiClock className="text-amber-600 shrink-0 mt-1" size={22} />}
        <div>
          <h1 className="text-xl font-heading font-bold text-gray-900">
            {rejected ? 'Your registration needs changes' : 'Your registration is under review'}
          </h1>
          <p className="text-gray-600 text-sm mt-1">
            {rejected
              ? 'The Tourism Office could not approve your registration yet. Correct the details below and resubmit.'
              : 'The Tourism Office is reviewing your business details and permit. You can manage your listing once approved.'}
          </p>
        </div>
      </div>

      {rejected && application.rejection_reason && (
        <div role="alert" className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
          <p className="font-semibold mb-1">Reason from the Tourism Office</p>
          <p className="whitespace-pre-line">{application.rejection_reason}</p>
        </div>
      )}

      {application?.has_permit && (
        <button type="button" onClick={viewPermit} className="inline-flex items-center gap-2 text-sm font-medium text-primary-700 hover:underline">
          <FiFileText aria-hidden="true" /> View the permit you submitted
        </button>
      )}

      {rejected && form && (
        <form onSubmit={resubmit} className="space-y-4 border-t border-gray-100 pt-6">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            {field('business_name', 'Business name', { required: true })}
            {field('business_permit_number', 'Business permit number', { required: true })}
            <div className="md:col-span-2">{field('business_address', 'Business address', { required: true })}</div>
            <label className="block">
              <span className="block text-sm font-medium text-gray-700 mb-1">Municipality</span>
              <select className="input-field" value={form.municipality_id} required
                onChange={(e) => setForm({ ...form, municipality_id: e.target.value, barangay_id: '' })}>
                <option value="">Select municipality</option>
                {municipalities.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
              </select>
            </label>
            <label className="block">
              <span className="block text-sm font-medium text-gray-700 mb-1">Barangay</span>
              <select className="input-field" value={form.barangay_id} onChange={(e) => setForm({ ...form, barangay_id: e.target.value })}>
                <option value="">Select barangay</option>
                {barangays.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
              </select>
              {errors.barangay_id && <span className="text-red-600 text-xs mt-1 block">{errors.barangay_id[0]}</span>}
            </label>
          </div>
          <label className="block">
            <span className="block text-sm font-medium text-gray-700 mb-1">Updated business permit (optional, PDF or Word, max 10 MB)</span>
            <span className="flex items-center gap-2 text-sm text-gray-600">
              <FiUploadCloud aria-hidden="true" />
              <input type="file" accept=".pdf,.doc,.docx" onChange={(e) => setPermit(e.target.files[0] || null)} />
            </span>
            {errors.business_permit_file && <span className="text-red-600 text-xs mt-1 block">{errors.business_permit_file[0]}</span>}
          </label>
          <button type="submit" disabled={saving} className="btn-primary min-h-11">
            {saving ? 'Resubmitting…' : 'Resubmit for review'}
          </button>
        </form>
      )}
    </div>
  )
}
