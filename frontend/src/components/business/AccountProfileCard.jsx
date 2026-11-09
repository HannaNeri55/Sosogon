import { useEffect, useState } from 'react'
import { getBusinessProfile, updateBusinessProfile } from '../../api/business'
import toast from 'react-hot-toast'

export default function AccountProfileCard() {
  const [profileForm, setProfileForm] = useState({ name: '', phone: '' })
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    getBusinessProfile().then((data) => setProfileForm({ name: data.name || '', phone: data.phone || '' }))
  }, [])

  const handleSubmit = async (e) => {
    e.preventDefault()
    setSaving(true)
    try {
      await updateBusinessProfile(profileForm)
      toast.success('Profile updated successfully')
    } catch {
      toast.error('Failed to update profile')
    } finally {
      setSaving(false)
    }
  }

  return (
    <form onSubmit={handleSubmit} className="bg-white rounded-2xl p-4 sm:p-6 border border-gray-200/70 space-y-5 shadow-(--shadow-card)">
      <h2 className="text-lg font-heading font-semibold text-gray-900">Account Profile</h2>
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-2">Full Name</label>
        <input className="input-field" value={profileForm.name} onChange={(e) => setProfileForm({ ...profileForm, name: e.target.value })} />
      </div>
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-2">Phone Number</label>
        <input className="input-field" value={profileForm.phone} onChange={(e) => setProfileForm({ ...profileForm, phone: e.target.value })} />
      </div>
      <button type="submit" className="btn-primary" disabled={saving}>
        {saving ? 'Saving...' : 'Save Profile'}
      </button>
    </form>
  )
}
