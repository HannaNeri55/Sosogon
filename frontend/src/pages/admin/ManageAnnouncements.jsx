import { useEffect, useMemo, useState } from 'react'
import { FiSearch, FiEdit2, FiTrash2, FiStar, FiMapPin, FiCalendar, FiBell } from 'react-icons/fi'
import { getAdminAnnouncements, createAnnouncement, updateAnnouncement, deleteAnnouncement, getMunicipalities } from '../../api/admin'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import { formatShortDate } from '../../utils/helpers'
import toast from 'react-hot-toast'
import useScrollToForm from '../../hooks/useScrollToForm'
import { showApiError } from '../../utils/apiErrors'
import { confirmDialog } from '../../utils/dialogs'
import useFormErrors from '../../hooks/useFormErrors'
import FieldError, { FormErrorSummary } from '../../components/common/FieldError'
import useAdminScope from '../../hooks/useAdminScope'
import ManagedBy from '../../components/admin/ManagedBy'
import EmptyState from '../../components/common/EmptyState'

// municipality_id: '' is province-wide.
const EMPTY_FORM = { title: '', content: '', is_pinned: false, status: 'published', municipality_id: '' }

export default function ManageAnnouncements() {
  // An announcement is for one municipality or the whole province. A municipal admin sees and
  // writes their municipality's only (the server lists nothing else for them).
  const scope = useAdminScope()
  const blankForm = () => ({ ...EMPTY_FORM, municipality_id: scope.municipality ? String(scope.municipality.id) : '' })
  const [municipalities, setMunicipalities] = useState([])
  const [items, setItems] = useState([])
  const [loading, setLoading] = useState(true)
  const [showForm, setShowForm] = useState(false)
  const [editingId, setEditingId] = useState(null)
  const [formData, setFormData] = useState(EMPTY_FORM)
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('all')

  // Pinned first, then newest; narrowed by the search and the status filter.
  const filteredItems = useMemo(() => {
    const q = search.trim().toLowerCase()
    return items
      .filter((item) => status === 'all' || item.status === status)
      .filter((item) => !q || item.title?.toLowerCase().includes(q) || item.content?.toLowerCase().includes(q))
      .sort((a, b) => Number(!!b.is_pinned) - Number(!!a.is_pinned) || new Date(b.created_at) - new Date(a.created_at))
  }, [items, search, status])
  const statusCounts = {
    all: items.length,
    published: items.filter((item) => item.status === 'published').length,
    draft: items.filter((item) => item.status === 'draft').length,
  }

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

      <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap gap-2" role="group" aria-label="Filter by status">
          {[['all', 'All'], ['published', 'Published'], ['draft', 'Drafts']].map(([value, label]) => (
            <button
              key={value}
              type="button"
              onClick={() => setStatus(value)}
              aria-pressed={status === value}
              className={`min-h-9 rounded-full border px-3.5 text-sm font-medium transition-colors ${status === value ? 'border-primary-600 bg-primary-600 text-white' : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50'}`}
            >
              {label} <span className="tabular-nums opacity-80">({statusCounts[value]})</span>
            </button>
          ))}
        </div>
        <div className="relative w-full sm:max-w-xs">
          <FiSearch className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={16} aria-hidden="true" />
          <input
            type="search"
            className="input-field pl-9"
            placeholder="Search announcements"
            aria-label="Search announcements"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>
      </div>

      {filteredItems.length === 0 ? (
        <EmptyState
          icon={FiBell}
          title={items.length === 0 ? 'No announcements yet' : 'No announcements match'}
        >
          {items.length === 0 ? 'Use Add Announcement to post the first one.' : 'Try another search or status.'}
        </EmptyState>
      ) : (
        <ul className="grid grid-cols-1 gap-5 lg:grid-cols-2">
          {filteredItems.map((item) => (
            <li key={item.id}>
              <article className="flex h-full flex-col rounded-2xl border border-gray-200/70 bg-white p-5 shadow-(--shadow-card)">
                <div className="flex flex-wrap items-center gap-2">
                  {item.is_pinned && (
                    <span className="inline-flex items-center gap-1 rounded-full bg-accent-50 px-2.5 py-0.5 text-xs font-semibold text-accent-700">
                      <FiStar size={12} className="fill-current" aria-hidden="true" /> Pinned
                    </span>
                  )}
                  <span className={`rounded-full px-2.5 py-0.5 text-xs font-medium capitalize ${item.status === 'published' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'}`}>
                    {item.status}
                  </span>
                  {scope.isProvincial && (
                    <span className="inline-flex items-center gap-1 text-xs text-gray-500">
                      <FiMapPin size={12} aria-hidden="true" /> {item.municipality?.name || 'Province-wide'}
                    </span>
                  )}
                </div>
                <h3 className="mt-3 font-heading text-lg font-semibold leading-snug text-gray-900 wrap-anywhere">{item.title}</h3>
                <p className="mb-4 mt-1.5 line-clamp-3 text-sm text-gray-600 wrap-anywhere">{item.content?.replace(/\s+/g, ' ')}</p>
                <div className="mt-auto flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-3">
                  <p className="inline-flex items-center gap-1.5 text-xs text-gray-500">
                    <FiCalendar size={12} aria-hidden="true" />
                    Posted {formatShortDate(item.created_at)}
                    {item.updated_at && item.updated_at !== item.created_at && <> · Updated {formatShortDate(item.updated_at)}</>}
                  </p>
                  {item.can_manage === false ? (
                    <ManagedBy office={item.managed_by} />
                  ) : (
                    <div className="flex gap-1">
                      <button type="button" onClick={() => startEdit(item)} className="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm font-medium text-gray-600 hover:bg-primary-50 hover:text-primary-700">
                        <FiEdit2 size={14} aria-hidden="true" /> Edit
                      </button>
                      <button type="button" onClick={() => handleDelete(item.id)} className="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm font-medium text-gray-600 hover:bg-red-50 hover:text-red-700">
                        <FiTrash2 size={14} aria-hidden="true" /> Delete
                      </button>
                    </div>
                  )}
                </div>
              </article>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
