import { useEffect, useMemo, useState } from 'react'
import { FiSearch, FiEdit2, FiTrash2, FiStar } from 'react-icons/fi'
import { getAdminAnnouncements, createAnnouncement, updateAnnouncement, deleteAnnouncement, getMunicipalities } from '../../api/admin'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import { truncate } from '../../utils/helpers'
import toast from 'react-hot-toast'
import useScrollToForm from '../../hooks/useScrollToForm'
import { showApiError } from '../../utils/apiErrors'
import { confirmDialog } from '../../utils/dialogs'
import useFormErrors from '../../hooks/useFormErrors'
import FieldError, { FormErrorSummary } from '../../components/common/FieldError'
import useAdminScope from '../../hooks/useAdminScope'

// municipality_id: '' is province-wide.
const EMPTY_FORM = { title: '', content: '', is_pinned: false, status: 'published', municipality_id: '' }

export default function ManageAnnouncements() {
  // An announcement is for one municipality or the whole province. A municipal admin writes for
  // their municipality and sees the province-wide ones read-only.
  const scope = useAdminScope()
  const blankForm = () => ({ ...EMPTY_FORM, municipality_id: scope.municipality ? String(scope.municipality.id) : '' })
  const [municipalities, setMunicipalities] = useState([])
  const [items, setItems] = useState([])
  const [loading, setLoading] = useState(true)
  const [showForm, setShowForm] = useState(false)
  const [editingId, setEditingId] = useState(null)
  const [formData, setFormData] = useState(EMPTY_FORM)
  const [search, setSearch] = useState('')

  const filteredItems = useMemo(() => {
    const q = search.trim().toLowerCase()
    if (!q) return items
    return items.filter((item) => item.title?.toLowerCase().includes(q))
  }, [items, search])

  const formRef = useScrollToForm(showForm ? editingId ?? 'new' : null)
  const form = useFormErrors(formRef)

  const loadData = async () => {
    try {
      const data = await getAdminAnnouncements({ per_page: 100 })
      setItems(data.data || [])
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    loadData()
    getMunicipalities().then(setMunicipalities).catch(() => {})
  }, [])

  const startEdit = (item) => {
    setEditingId(item.id)
    setFormData({
      title: item.title || '',
      content: item.content || '',
      is_pinned: !!item.is_pinned,
      status: item.status || 'published',
      municipality_id: item.municipality_id ? String(item.municipality_id) : '',
    })
    setShowForm(true)
  }

  const closeForm = () => {
    form.setErrors({})
    setShowForm(false)
    setEditingId(null)
    setFormData(blankForm())
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    if (!form.check()) return
    const payload = { ...formData, municipality_id: formData.municipality_id || null }
    try {
      if (editingId) {
        await updateAnnouncement(editingId, payload)
        toast.success('Announcement updated')
      } else {
        await createAnnouncement(payload)
        toast.success('Announcement created')
      }
      closeForm()
      loadData()
    } catch (err) {
      if (!form.fromResponse(err)) showApiError(err, editingId ? 'Failed to update announcement' : 'Failed to create announcement')
    }
  }

  const handleDelete = async (id) => {
    if (!(await confirmDialog({ title: 'Delete this announcement?', message: 'It will be removed from the site. This cannot be undone.', confirmLabel: 'Delete', danger: true }))) return
    try {
      await deleteAnnouncement(id)
      toast.success('Announcement deleted')
      loadData()
    } catch (error) {
      showApiError(error, 'Failed to delete announcement')
    }
  }

  if (loading) return <LoadingSpinner size="lg" />

  return (
    <div>
      <div className="flex flex-wrap items-end justify-between gap-4 mb-8">
        <div>
          <p className="text-primary-600 font-medium mb-2">Admin</p>
          <h1 className="text-2xl sm:text-3xl font-heading font-bold text-gray-900">Manage Announcements</h1>
        </div>
        <button
          onClick={() => {
            if (showForm) {
              closeForm()
            } else {
              setFormData(blankForm())
              setShowForm(true)
            }
          }}
          className="btn-primary"
        >
          {showForm ? 'Close Form' : 'Add Announcement'}
        </button>
      </div>

      {showForm && (
        <form
          ref={formRef} noValidate onChange={form.onChange} onSubmit={handleSubmit} className="bg-white rounded-xl p-5 sm:p-8 border border-gray-100 mb-8 space-y-5">
          <p className="text-sm font-medium text-gray-500">{editingId ? 'Editing announcement' : 'New announcement'}</p>
          <FormErrorSummary form={form} />
          <div>
            <input name="title" className="input-field" placeholder="Title" aria-label="Title" maxLength={255} value={formData.title} onChange={(e) => setFormData({ ...formData, title: e.target.value })} required />
            <FieldError form={form} name="title" />
          </div>
          <div>
            <textarea name="content" className="input-field" rows={4} placeholder="Content" aria-label="Content" value={formData.content} onChange={(e) => setFormData({ ...formData, content: e.target.value })} required />
            <FieldError form={form} name="content" />
          </div>
          <div>
            <select name="municipality_id" className="input-field disabled:bg-gray-50 disabled:text-gray-600" aria-label="For which municipality" value={formData.municipality_id} disabled={scope.isMunicipal} onChange={(e) => setFormData({ ...formData, municipality_id: e.target.value })}>
              {scope.isProvincial && <option value="">The whole province</option>}
              {municipalities.filter((m) => scope.canManage(m.id)).map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
            </select>
            {scope.isMunicipal && <p className="mt-1 text-xs text-gray-500">Your office's announcements are for {scope.municipality?.name}.</p>}
            <FieldError form={form} name="municipality_id" />
          </div>
          <div className="flex items-center gap-6">
            <label className="flex items-center gap-2 text-sm text-gray-700">
              <input type="checkbox" name="is_pinned" checked={formData.is_pinned} onChange={(e) => setFormData({ ...formData, is_pinned: e.target.checked })} />
              Pinned announcement
            </label>
            <select name="status" className="input-field w-auto" aria-label="Status" value={formData.status} onChange={(e) => setFormData({ ...formData, status: e.target.value })}>
              <option value="published">Published</option>
              <option value="draft">Draft</option>
            </select>
          </div>
          <div className="flex gap-3">
            <button type="submit" className="btn-primary">{editingId ? 'Save Changes' : 'Save Announcement'}</button>
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
        <input
          className="input-field pl-9"
          placeholder="Search announcements..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
      </div>

      <div className="bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) overflow-hidden">
        <div className="overflow-x-auto">
          {/* Phones: keep columns readable and let the table scroll sideways. */}
          <table className="table-cards w-full min-w-[40rem] [&_.rounded-full]:whitespace-nowrap">
            <thead className="bg-gray-50/80 border-b border-gray-200">
              <tr>
                <th className="text-left px-6 py-3 text-xs font-semibold uppercase tracking-wider text-gray-500">Announcement</th>
                <th className="text-left px-6 py-3 text-xs font-semibold uppercase tracking-wider text-gray-500">Status</th>
                <th className="text-left px-6 py-3 text-xs font-semibold uppercase tracking-wider text-gray-500">Pinned</th>
                <th className="text-right px-6 py-3 text-xs font-semibold uppercase tracking-wider text-gray-500">Actions</th>
              </tr>
            </thead>
            <tbody>
              {filteredItems.map((item) => (
                <tr key={item.id} className="border-b border-gray-100 last:border-b-0 hover:bg-primary-50/40 transition-colors duration-150">
                  <td className="px-6 py-3">
                    <p className="text-sm font-medium text-gray-900">{item.title}</p>
                    <p className="text-xs text-gray-500 mt-0.5">{item.municipality?.name || 'Province-wide'}</p>
                    <p className="text-xs text-gray-400 mt-0.5">{truncate(item.content, 80)}</p>
                  </td>
                  <td data-label="Status" className="px-6 py-4">
                    <span className={`text-xs font-medium px-2.5 py-1 rounded-full capitalize ${item.status === 'published' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'}`}>
                      {item.status}
                    </span>
                  </td>
                  <td data-label="Pinned" className="px-6 py-4 text-sm text-gray-600">
                    {item.is_pinned ? <FiStar className="text-yellow-500 fill-yellow-500" size={15} /> : '—'}
                  </td>
                  <td data-actions className="px-6 py-4">
                    {item.can_manage === false ? (
                      <p className="text-right text-xs text-gray-400">Managed by the provincial administrator</p>
                    ) : (
                    <div className="flex gap-3 justify-end">
                      <button onClick={() => startEdit(item)} className="p-2 text-gray-400 hover:text-primary-600 hover:bg-primary-50 rounded-lg transition-colors" title="Edit">
                        <FiEdit2 size={15} />
                      </button>
                      <button onClick={() => handleDelete(item.id)} className="p-2 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Delete">
                        <FiTrash2 size={15} />
                      </button>
                    </div>
                    )}
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
