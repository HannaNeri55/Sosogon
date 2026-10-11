import { useMemo, useState } from 'react'
import { FiFileText, FiClock, FiCheckCircle, FiXCircle, FiEdit2, FiTrash2, FiPlus } from 'react-icons/fi'
import { updateSubmission, deleteSubmission } from '../../api/business'
import StatCard from '../../components/common/StatCard'
import toast from 'react-hot-toast'

const STATUS_STYLES = {
  approved: 'bg-green-100 text-green-700',
  pending: 'bg-yellow-100 text-yellow-700',
  rejected: 'bg-red-100 text-red-700',
  removed: 'bg-gray-200 text-gray-700',
}

export default function SubmissionStatus({ submissions, onChange, onAddNew }) {
  const [editingId, setEditingId] = useState(null)
  const [editForm, setEditForm] = useState({})
  const [saving, setSaving] = useState(false)

  const counts = useMemo(() => ({
    total: submissions.length,
    pending: submissions.filter((s) => s.status === 'pending').length,
    approved: submissions.filter((s) => s.status === 'approved').length,
    rejected: submissions.filter((s) => s.status === 'rejected').length,
  }), [submissions])

  const startEdit = (submission) => {
    setEditingId(submission.id)
    setEditForm({
      business_name: submission.business_name,
      description: submission.description,
      address: submission.address,
      contact_number: submission.contact_number,
      operating_hours: submission.operating_hours || '',
    })
  }

  const handleUpdate = async (e) => {
    e.preventDefault()
    setSaving(true)
    try {
      await updateSubmission(editingId, editForm)
      toast.success('Submission updated')
      setEditingId(null)
      onChange?.()
    } catch (err) {
      const errors = err.response?.data?.errors
      if (errors) Object.values(errors).flat().forEach((msg) => toast.error(msg))
      else toast.error('Failed to update submission')
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async (id) => {
    if (!confirm('Delete this submission? This cannot be undone.')) return
    try {
      await deleteSubmission(id)
      toast.success('Submission deleted')
      onChange?.()
    } catch {
      toast.error('Failed to delete submission')
    }
  }

  return (
    <div>
      <div className="flex items-center justify-between flex-wrap gap-4 mb-6">
        <div>
          <p className="text-primary-600 font-medium mb-2">Business Owner Portal</p>
          <h1 className="text-4xl font-heading font-bold text-gray-900">Submission Status</h1>
        </div>
        {onAddNew && (
          <button onClick={onAddNew} className="btn-primary flex items-center gap-2">
            <FiPlus size={16} /> Add Another Listing
          </button>
        )}
      </div>

      <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-8">
        <StatCard icon={FiFileText} label="Total" value={counts.total} accent="primary" />
        <StatCard icon={FiClock} label="Pending" value={counts.pending} accent="yellow" />
        <StatCard icon={FiCheckCircle} label="Approved" value={counts.approved} accent="green" />
        <StatCard icon={FiXCircle} label="Rejected" value={counts.rejected} accent="red" />
      </div>

      {submissions.length === 0 ? (
        <div className="bg-white rounded-xl p-12 text-center border border-gray-100">
          <FiFileText className="mx-auto text-gray-300 mb-3" size={32} />
          <p className="text-gray-500">No submissions yet.</p>
        </div>
      ) : (
        <div className="space-y-4">
          {submissions.map((submission) => (
            <div key={submission.id} className="bg-white rounded-xl p-6 border border-gray-100">
              {editingId === submission.id ? (
                <form onSubmit={handleUpdate} className="space-y-4">
                  <input
                    className="input-field"
                    placeholder="Business name"
                    value={editForm.business_name}
                    onChange={(e) => setEditForm({ ...editForm, business_name: e.target.value })}
                    required
                  />
                  <textarea
                    className="input-field"
                    rows={3}
                    placeholder="Description"
                    value={editForm.description}
                    onChange={(e) => setEditForm({ ...editForm, description: e.target.value })}
                    required
                  />
                  <input
                    className="input-field"
                    placeholder="Address"
                    value={editForm.address}
                    onChange={(e) => setEditForm({ ...editForm, address: e.target.value })}
                    required
                  />
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <input
                      className="input-field"
                      placeholder="Contact number"
                      value={editForm.contact_number}
                      onChange={(e) => setEditForm({ ...editForm, contact_number: e.target.value })}
                      required
                    />
                    <input
                      className="input-field"
                      placeholder="Operating hours"
                      value={editForm.operating_hours}
                      onChange={(e) => setEditForm({ ...editForm, operating_hours: e.target.value })}
                    />
                  </div>
                  <div className="flex gap-3">
                    <button type="submit" className="btn-primary" disabled={saving}>
                      {saving ? 'Saving...' : 'Save Changes'}
                    </button>
                    <button type="button" onClick={() => setEditingId(null)} className="px-4 py-2 border border-gray-200 rounded-lg text-gray-600 hover:bg-gray-50">
                      Cancel
                    </button>
                  </div>
                </form>
              ) : (
                <div className="flex items-start justify-between gap-4">
                  <div>
                    <h3 className="font-heading font-semibold text-xl text-gray-900">{submission.business_name}</h3>
                    <p className="text-sm text-gray-500 capitalize mt-1">{submission.type.replace('_', ' ')}</p>
                    <p className="text-gray-600 mt-3">{submission.description}</p>
                    {submission.admin_notes && (
                      <div className="mt-4 p-3 bg-gray-50 rounded-lg">
                        <p className="text-sm font-medium text-gray-700 mb-1">
                          {submission.status === 'removed' ? 'Reason for removal' : 'Admin Notes'}
                        </p>
                        <p className="text-sm text-gray-600">{submission.admin_notes}</p>
                      </div>
                    )}
                    <div className="flex gap-4 mt-4">
                      <button onClick={() => startEdit(submission)} className="flex items-center gap-1.5 text-primary-600 hover:text-primary-700 text-sm font-medium">
                        <FiEdit2 size={13} /> Edit
                      </button>
                      <button onClick={() => handleDelete(submission.id)} className="flex items-center gap-1.5 text-red-600 hover:text-red-700 text-sm font-medium">
                        <FiTrash2 size={13} /> Delete
                      </button>
                    </div>
                    {submission.status === 'approved' && (
                      <p className="text-xs text-gray-400 mt-2">Editing this will require admin re-approval before changes go live.</p>
                    )}
                    {submission.status === 'removed' && (
                      <p className="text-xs text-gray-400 mt-2">This listing was removed from the platform. Update and resubmit it to request a new review.</p>
                    )}
                  </div>
                  <span className={`text-xs px-3 py-1 rounded-full font-medium shrink-0 ${STATUS_STYLES[submission.status] || 'bg-gray-100 text-gray-700'}`}>
                    {submission.status}
                  </span>
                </div>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
