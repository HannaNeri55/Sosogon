import { useEffect, useState, useRef } from 'react'
import { getBusinessProfile, updateBusinessProfile } from '../../api/business'
import toast from 'react-hot-toast'
import { showApiError } from '../../utils/apiErrors'
import useFormErrors from '../../hooks/useFormErrors'
import FieldError, { FormErrorSummary } from '../../components/common/FieldError'

export default function AccountProfileCard() {
  const [profileForm, setProfileForm] = useState({ name: '', phone: '' })
  const [saving, setSaving] = useState(false)
  const formRef = useRef(null)
  const form = useFormErrors(formRef)

  useEffect(() => {
    getBusinessProfile().then((data) => setProfileForm({ name: data.name || '', phone: data.phone || '' }))
  }, [])

  const handleSubmit = async (e) => {
    e.preventDefault()
    if (!form.check()) return
    setSaving(true)
    try {
      await updateBusinessProfile(profileForm)
      toast.success('Profile updated successfully')
    } catch (error) {
      if (!form.fromResponse(error)) showApiError(error, 'Failed to update profile')
    } finally {
      setSaving(false)
    }
  }

  return (
    <form ref={formRef} noValidate onChange={form.onChange} onSubmit={handleSubmit} className="bg-white rounded-2xl p-4 sm:p-6 border border-gray-200/70 space-y-5 shadow-(--shadow-card)">
      <h2 className="text-lg font-heading font-semibold text-gray-900">Account Profile</h2>
      <FormErrorSummary form={form} />
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-2">Full Name</label>
        <input name="name" maxLength={255} required className="input-field" value={profileForm.name} onChange={(e) => setProfileForm({ ...profileForm, name: e.target.value })} />
        <FieldError form={form} name="name" />
      </div>
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-2">Phone Number</label>
        <input name="phone" type="tel" maxLength={20} className="input-field" value={profileForm.phone} onChange={(e) => setProfileForm({ ...profileForm, phone: e.target.value })} />
        <FieldError form={form} name="phone" />
      </div>
      <button type="submit" className="btn-primary" disabled={saving}>
        {saving ? 'Saving...' : 'Save Profile'}
      </button>
    </form>
  )
}
