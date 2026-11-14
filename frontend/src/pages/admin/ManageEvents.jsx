import { useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { FiSearch, FiEdit2, FiTrash2, FiStar, FiImage, FiUpload, FiX, FiMapPin } from 'react-icons/fi'
import { getAdminEvents, createEvent, updateEvent, updateEventRanking, deleteEvent, getMunicipalities } from '../../api/admin'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import { getImageUrl } from '../../utils/helpers'
import toast from 'react-hot-toast'
import useScrollToForm from '../../hooks/useScrollToForm'
import { showApiError } from '../../utils/apiErrors'
import { confirmDialog } from '../../utils/dialogs'
import useFormErrors from '../../hooks/useFormErrors'
import FieldError, { FormErrorSummary } from '../../components/common/FieldError'
import useAdminScope from '../../hooks/useAdminScope'
import ManagedBy from '../../components/admin/ManagedBy'
import MunicipalityWorkspace from '../../components/admin/MunicipalityWorkspace'
import Pagination from '../../components/common/Pagination'
import EmptyState from '../../components/common/EmptyState'

const EMPTY_FORM = {
  title: '', description: '', location: '', municipality_id: '',
  start_date: '', end_date: '', is_featured: false, status: 'published',
}

/** Events per page inside a municipality's workspace. */
const PAGE_SIZE = 10

/** The workspace of the events without a municipality. */
const PROVINCE = 'province'

// Same limits the server enforces (AdminEventController::IMAGE_RULE).
const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp']
const IMAGE_MAX_BYTES = 5 * 1024 * 1024

export default function ManageEvents() {
  // A municipal admin manages their municipality's events and sees the province-wide ones
  // read-only; Featured events show across the province, so only provincial admins set it.
  const scope = useAdminScope()
  // The selected workspace (?municipality=<id> or "province", so it survives a reload and the
  // browser's Back returns to the overview); none selected: the municipality overview.
  const [searchParams, setSearchParams] = useSearchParams()
  const [page, setPage] = useState(1)
  // A new event starts in the workspace being viewed, when this admin manages it.
  const blankForm = () => ({
    ...EMPTY_FORM,
    municipality_id: scope.municipality
      ? String(scope.municipality.id)
      : selectedKey && selectedKey !== PROVINCE && scope.canManage(selectedKey) ? selectedKey : '',
  })
  const [items, setItems] = useState([])
  const [municipalities, setMunicipalities] = useState([])
  const [loading, setLoading] = useState(true)
  const [showForm, setShowForm] = useState(false)
  const [editingId, setEditingId] = useState(null)
  const [formData, setFormData] = useState(EMPTY_FORM)
  const [search, setSearch] = useState('')
  // Cover image: a newly chosen file (with a local preview), the event's current image, or "remove".
  const [imageFile, setImageFile] = useState(null)
  const [imagePreview, setImagePreview] = useState(null)
  const [currentImage, setCurrentImage] = useState(null)
  const [removeImage, setRemoveImage] = useState(false)

  useEffect(() => () => imagePreview && URL.revokeObjectURL(imagePreview), [imagePreview])

  const formRef = useScrollToForm(showForm ? editingId ?? 'new' : null)
  const form = useFormErrors(formRef, {
    validate: () => ({
      end_date: formData.end_date && formData.start_date && formData.end_date < formData.start_date
        ? 'The end date must be on or after the start date.'
        : null,
    }),
  })

  const chooseImage = (file) => {
    if (!file) return
    if (!IMAGE_TYPES.includes(file.type)) {
      form.setErrors({ image: 'Choose a JPG, PNG or WebP image.' })
      return
    }
    if (file.size > IMAGE_MAX_BYTES) {
      form.setErrors({ image: 'The image is larger than 5 MB. Choose a smaller one.' })
      return
    }
    form.clear('image')
    setImageFile(file)
    setImagePreview(URL.createObjectURL(file))
    setRemoveImage(false)
  }

  const clearImage = () => {
    setImageFile(null)
    setImagePreview(null)
    if (currentImage) setRemoveImage(true)
  }

  const filteredItems = useMemo(() => {
    const q = search.trim().toLowerCase()
    if (!q) return items
    return items.filter((item) => item.title?.toLowerCase().includes(q))
  }, [items, search])

  // One workspace per municipality plus the province-wide events, each with its event count. A
  // municipal admin sees their municipality's events only (the province-wide ones are the
  // provincial admin's), so they go straight to its workspace.
  const keyOf = (item) => (item.municipality_id == null ? PROVINCE : String(item.municipality_id))
  const areas = useMemo(() => {
    const counts = {}
    items.forEach((item) => { counts[keyOf(item)] = (counts[keyOf(item)] || 0) + 1 })
    const towns = (scope.isMunicipal ? (scope.municipality ? [scope.municipality] : []) : [...municipalities])
      .sort((a, b) => a.name.localeCompare(b.name))
      .map((town) => ({ key: String(town.id), name: town.name, count: counts[town.id] || 0 }))
    const province = { key: PROVINCE, name: 'Province-wide', count: counts[PROVINCE] || 0, provinceWide: true }
    return scope.isMunicipal ? towns : [province, ...towns]
  }, [items, municipalities, scope.isMunicipal, scope.municipality])
  const requested = searchParams.get('municipality')
  const selectedKey = areas.some((area) => area.key === requested) ? requested : areas.length === 1 ? areas[0].key : null
  const selectedArea = areas.find((area) => area.key === selectedKey)
  const selectArea = (key) => {
    setSearchParams((params) => { params.set('municipality', key); return params })
    setSearch('')
    setPage(1)
  }
  const showOverview = () => {
    setSearchParams((params) => { params.delete('municipality'); return params })
    setSearch('')
    setPage(1)
  }

  // The selected workspace's events matching the search, a page at a time.
  const townItems = filteredItems.filter((item) => keyOf(item) === selectedKey)
  const lastPage = Math.max(1, Math.ceil(townItems.length / PAGE_SIZE))
  const currentPage = Math.min(page, lastPage)
  const pageItems = townItems.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE)
  const canAddHere = selectedKey === PROVINCE ? scope.isProvincial : Boolean(selectedKey) && scope.canManage(selectedKey)


  const loadData = async () => {
    try {
      // Every event at once (the server allows up to 200), so each municipality's group is complete.
      const data = await getAdminEvents({ per_page: 200 })
      setItems(data.data || [])
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    loadData()
    getMunicipalities().then(setMunicipalities)
  }, [])

  const startEdit = (item) => {
    setEditingId(item.id)
    setFormData({
      title: item.title || '',
      description: item.description || '',
      location: item.location || '',
      municipality_id: item.municipality_id || '',
      start_date: item.start_date || '',
      end_date: item.end_date || '',
      is_featured: !!item.is_featured,
      status: item.status || 'published',
    })
    setImageFile(null)
    setImagePreview(null)
    setCurrentImage(item.image_path || null)
    setRemoveImage(false)
    setShowForm(true)
  }

  const closeForm = () => {
    form.setErrors({})
    setShowForm(false)
    setEditingId(null)
    setFormData(blankForm())
    setImageFile(null)
    setImagePreview(null)
    setCurrentImage(null)
    setRemoveImage(false)
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    if (!form.check()) return
    const payload = new FormData()
    payload.append('title', formData.title)
    payload.append('description', formData.description)
    payload.append('location', formData.location || '')
    payload.append('municipality_id', formData.municipality_id || '')
    payload.append('start_date', formData.start_date)
    payload.append('end_date', formData.end_date || '')
    if (scope.isProvincial) payload.append('is_featured', formData.is_featured ? '1' : '0')
    payload.append('status', formData.status)
    if (imageFile) payload.append('image', imageFile)
    else if (editingId && removeImage) payload.append('remove_image', '1')
    try {
      if (editingId) {
        await updateEvent(editingId, payload)
        toast.success('Event updated')
      } else {
        await createEvent(payload)
        toast.success('Event created')
        // Open the workspace it was added to.
        const addedTo = formData.municipality_id ? String(formData.municipality_id) : PROVINCE
        if (addedTo !== selectedKey) selectArea(addedTo)
      }
      closeForm()
      loadData()
    } catch (err) {
      if (!form.fromResponse(err)) showApiError(err, editingId ? 'Failed to update event' : 'Failed to create event')
    }
  }

  // A provincial admin features any municipality's event, including one its own office manages.
  const toggleFeatured = async (item) => {
    try {
      await updateEventRanking(item.id, !item.is_featured)
      toast.success(item.is_featured ? 'Event is no longer featured' : 'Event featured')
      loadData()
    } catch (err) {
      showApiError(err, 'Could not update the event.')
    }
  }

  const handleDelete = async (id) => {
    if (!(await confirmDialog({ title: 'Delete this event?', message: 'It will be removed from the site and the calendar. This cannot be undone.', confirmLabel: 'Delete', danger: true }))) return
    try {
      await deleteEvent(id)
      toast.success('Event deleted')
      loadData()
    } catch (error) {
      showApiError(error, 'Failed to delete event')
    }
  }

  if (loading) return <LoadingSpinner size="lg" />

  return (
    <div>
      <div className="flex flex-wrap items-end justify-between gap-4 mb-8">
        <div>
          <p className="text-primary-600 font-medium mb-2">Admin</p>
          <h1 className="text-2xl sm:text-3xl font-heading font-bold text-gray-900">Manage Events</h1>
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
          {showForm ? 'Close Form' : 'Add Event'}
        </button>
      </div>

      {showForm && (
        <form
          ref={formRef} noValidate onChange={form.onChange} onSubmit={handleSubmit} className="bg-white rounded-xl p-5 sm:p-8 border border-gray-100 mb-8 space-y-5">
          <p className="text-sm font-medium text-gray-500">{editingId ? 'Editing event' : 'New event'}</p>
          <FormErrorSummary form={form} />
          <div>
            <input name="title" className="input-field" placeholder="Event title" aria-label="Event title" maxLength={255} value={formData.title} onChange={(e) => setFormData({ ...formData, title: e.target.value })} required />
            <FieldError form={form} name="title" />
          </div>
          <div>
            <textarea name="description" className="input-field" rows={4} placeholder="Description" aria-label="Description" value={formData.description} onChange={(e) => setFormData({ ...formData, description: e.target.value })} required />
            <FieldError form={form} name="description" />
          </div>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div>
              <select name="municipality_id" className="input-field disabled:bg-gray-50 disabled:text-gray-600" aria-label="Municipality" value={formData.municipality_id} disabled={scope.isMunicipal} onChange={(e) => setFormData({ ...formData, municipality_id: e.target.value })}>
                {scope.isProvincial && <option value="">Province-wide (no specific municipality)</option>}
                {municipalities.filter((m) => scope.canManage(m.id)).map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
              </select>
              {scope.isMunicipal && <p className="mt-1 text-xs text-gray-500">Your office manages events in {scope.municipality?.name} only.</p>}
              <FieldError form={form} name="municipality_id" />
            </div>
            <div>
              <input name="location" className="input-field" placeholder="Location (optional, mainly for province-wide events)" aria-label="Location" maxLength={500} value={formData.location} onChange={(e) => setFormData({ ...formData, location: e.target.value })} />
              <FieldError form={form} name="location" />
            </div>
          </div>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">Start date</label>
              <input name="start_date" type="date" className="input-field" value={formData.start_date} onChange={(e) => setFormData({ ...formData, start_date: e.target.value })} required />
              <FieldError form={form} name="start_date" />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">End date</label>
              <input name="end_date" type="date" min={formData.start_date || undefined} className="input-field" value={formData.end_date} onChange={(e) => setFormData({ ...formData, end_date: e.target.value })} />
              <FieldError form={form} name="end_date" />
            </div>
          </div>
          <div>
            <p className="block text-sm font-medium text-gray-700 mb-2">Cover image</p>
            {(() => {
              const shown = imagePreview || (!removeImage && currentImage ? getImageUrl(currentImage) : null)
              return shown ? (
                <div className="relative w-full max-w-md overflow-hidden rounded-xl border border-gray-200 bg-gray-100">
                  <img src={shown} alt="Event cover preview" className="aspect-video w-full object-cover" />
                  <div className="absolute right-2 top-2 flex gap-2">
                    <label className="inline-flex cursor-pointer items-center gap-1.5 rounded-full bg-white/95 px-3 py-1.5 text-xs font-semibold text-gray-800 shadow-sm hover:bg-white">
                      <FiUpload size={13} aria-hidden="true" /> Replace
                      <input type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" onChange={(e) => { chooseImage(e.target.files?.[0]); e.target.value = '' }} />
                    </label>
                    <button type="button" onClick={clearImage} className="inline-flex items-center gap-1 rounded-full bg-white/95 px-3 py-1.5 text-xs font-semibold text-red-700 shadow-sm hover:bg-white">
                      <FiX size={13} aria-hidden="true" /> Remove
                    </button>
                  </div>
                  {imageFile && (
                    <span className="absolute bottom-2 left-2 rounded-full bg-black/65 px-2.5 py-1 text-xs text-white">New image — saved when you submit</span>
                  )}
                </div>
              ) : (
                <label
                  className="flex w-full max-w-md cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-gray-300 bg-gray-50 px-6 py-8 text-center hover:border-primary-400 hover:bg-primary-50/40 transition-colors"
                  onDragOver={(e) => e.preventDefault()}
                  onDrop={(e) => { e.preventDefault(); chooseImage(e.dataTransfer.files?.[0]) }}
                >
                  <FiImage size={26} className="text-gray-400" aria-hidden="true" />
                  <span className="text-sm font-semibold text-gray-700">Upload a cover image or banner</span>
                  <span className="text-xs text-gray-500">JPG, PNG or WebP, up to 5 MB. A wide (landscape) photo works best.</span>
                  {removeImage && <span className="text-xs font-medium text-red-700">The current image will be removed when you save.</span>}
                  <input type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" onChange={(e) => { chooseImage(e.target.files?.[0]); e.target.value = '' }} />
                </label>
              )
            })()}
            <FieldError form={form} name="image" />
          </div>
          <div className="flex items-center gap-6">
            {scope.isProvincial ? (
              <label className="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" name="is_featured" checked={formData.is_featured} onChange={(e) => setFormData({ ...formData, is_featured: e.target.checked })} />
                Featured event
              </label>
            ) : (
              <p className="text-sm text-gray-600" title="Featured events show across the province, so the provincial administrator sets this.">
                Featured: <span className="font-medium text-gray-800">{formData.is_featured ? 'Yes' : 'No'}</span>
              </p>
            )}
            <select name="status" className="input-field w-auto" aria-label="Status" value={formData.status} onChange={(e) => setFormData({ ...formData, status: e.target.value })}>
              <option value="published">Published</option>
              <option value="draft">Draft</option>
            </select>
          </div>
          <div className="flex gap-3">
            <button type="submit" className="btn-primary">{editingId ? 'Save Changes' : 'Save Event'}</button>
            {editingId && (
              <button type="button" onClick={closeForm} className="px-4 py-2 border border-gray-200 rounded-lg text-gray-600 hover:bg-gray-50">
                Cancel
              </button>
            )}
          </div>
        </form>
      )}

      <MunicipalityWorkspace areas={areas} selectedKey={selectedKey} onSelect={selectArea} onBack={showOverview} noun="event">
      {selectedArea && (
        <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
          <div>
            <h2 className="flex items-center gap-2 text-xl font-heading font-bold text-gray-900">
              <FiMapPin size={18} className="text-primary-600" aria-hidden="true" />
              {selectedArea.name}
            </h2>
            <p className="mt-0.5 text-sm text-gray-500">{selectedArea.count} event{selectedArea.count === 1 ? '' : 's'}</p>
          </div>
          <div className="relative w-full sm:max-w-xs">
            <FiSearch className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={16} />
            <input
              type="search"
              className="input-field pl-9"
              placeholder={selectedKey === PROVINCE ? 'Search province-wide events' : `Search ${selectedArea.name} events`}
              aria-label={selectedKey === PROVINCE ? 'Search province-wide events' : `Search ${selectedArea.name} events`}
              value={search}
              onChange={(e) => { setSearch(e.target.value); setPage(1) }}
            />
          </div>
        </div>
      )}

      {pageItems.length === 0 ? (
        <EmptyState
          icon={FiMapPin}
          title={search.trim()
            ? `No events here match “${search.trim()}”`
            : selectedKey === PROVINCE ? 'No province-wide events yet' : `No events in ${selectedArea?.name ?? 'this municipality'} yet`}
        >
          {!search.trim() && canAddHere && 'Use Add Event to add the first one.'}
        </EmptyState>
      ) : (
      <div className="bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) overflow-hidden">
        <div className="overflow-x-auto">
          {/* Phones: keep columns readable and let the table scroll sideways. */}
          <table className="table-cards w-full min-w-[40rem] [&_.rounded-full]:whitespace-nowrap">
            <thead className="bg-gray-50/80 border-b border-gray-200">
              <tr>
                <th className="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-gray-500">Event</th>
                <th className="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-gray-500">Dates</th>
                <th className="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-gray-500">Status</th>
                <th className="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-gray-500">Featured</th>
                <th className="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wider text-gray-500">Actions</th>
              </tr>
            </thead>
            <tbody>
              {pageItems.map((item) => (
                <tr key={item.id} className="border-b border-gray-100 last:border-b-0 hover:bg-primary-50/40 transition-colors duration-150">
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-3">
                      {item.image_path ? (
                        <img src={getImageUrl(item.image_path)} alt="" className="h-10 w-14 shrink-0 rounded-md object-cover bg-gray-100" loading="lazy" />
                      ) : (
                        <span className="flex h-10 w-14 shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-400" title="No cover image">
                          <FiImage size={15} aria-hidden="true" />
                        </span>
                      )}
                      <p className="min-w-0 text-sm font-medium text-gray-900">{item.title}</p>
                    </div>
                  </td>
                  <td data-label="Dates" className="px-4 py-4 text-sm text-gray-600">
                    {/* Each date stays whole; a range may wrap only between the two dates. */}
                    <span className="whitespace-nowrap">{item.start_date}</span>
                    {item.end_date && item.end_date !== item.start_date && (
                      <> <span className="whitespace-nowrap">{`\u2013 ${item.end_date}`}</span></>
                    )}
                  </td>
                  <td data-label="Status" className="px-4 py-4">
                    <span className={`text-xs font-medium px-2.5 py-1 rounded-full capitalize ${item.status === 'published' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'}`}>
                      {item.status}
                    </span>
                  </td>
                  <td data-label="Featured" className="px-4 py-4 text-sm text-gray-600">
                    {item.can_manage === false && item.managed_by && scope.isProvincial ? (
                      // Managed by its municipality's own tourism office: Featured stays with the province.
                      <button
                        type="button"
                        onClick={() => toggleFeatured(item)}
                        aria-pressed={Boolean(item.is_featured)}
                        aria-label={`${item.is_featured ? 'Unfeature' : 'Feature'} ${item.title}`}
                        title={item.is_featured ? 'Featured. Click to unfeature.' : 'Not featured. Click to feature.'}
                        className="-m-1.5 rounded-lg p-1.5 text-gray-400 hover:bg-accent-50 hover:text-yellow-500"
                      >
                        <FiStar size={15} className={item.is_featured ? 'text-yellow-500 fill-yellow-500' : ''} />
                      </button>
                    ) : item.is_featured ? <FiStar className="text-yellow-500 fill-yellow-500" size={15} /> : '\u2014'}
                  </td>
                  <td data-actions className="px-4 py-4">
                    {item.can_manage === false ? (
                      // Managed by its municipality's own tourism office: no edit or delete here (a
                      // provincial admin keeps Featured, in its column).
                      item.managed_by ? null : (
                        // A province-wide event, for a municipal admin.
                        <ManagedBy className="ml-auto" />
                      )
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
      )}
      <Pagination currentPage={currentPage} lastPage={lastPage} onPageChange={setPage} />
      </MunicipalityWorkspace>
    </div>
  )
}