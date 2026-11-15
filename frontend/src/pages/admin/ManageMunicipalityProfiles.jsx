import { useEffect, useMemo, useRef, useState } from 'react'
import toast from 'react-hot-toast'
import { FiArrowLeft, FiBookOpen, FiCheckCircle, FiEdit2, FiEye, FiImage, FiMap, FiPhone, FiSearch } from 'react-icons/fi'
import { getAdminMunicipalityProfile, getAdminMunicipalityProfiles, updateMunicipalityProfile } from '../../api/admin'
import { getImageUrl, formatShortDate } from '../../utils/helpers'
import { showApiError } from '../../utils/apiErrors'
import { confirmDialog } from '../../utils/dialogs'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import Modal from '../../components/common/Modal'
import FieldError, { FormErrorSummary } from '../../components/common/FieldError'
import MunicipalityProfileView from '../../components/about/MunicipalityProfileView'
import useFormErrors from '../../hooks/useFormErrors'
import useAdminScope from '../../hooks/useAdminScope'

const FIELDS = ['contact_number', 'email', 'address', 'other_contacts', 'geography', 'boundaries', 'history', 'name_origin', 'sources']
const SECTION_LABELS = { contact: 'Contact', geography: 'Geography', history: 'History' }

const formFrom = (profile) => ({
  ...Object.fromEntries(FIELDS.map((field) => [field, profile[field] ?? ''])),
  image: null,
  remove_image: false,
})

function Group({ icon: Icon, title, hint, children }) {
  return (
    <fieldset className="rounded-2xl border border-gray-200/70 bg-white p-5 sm:p-6">
      <legend className="sr-only">{title}</legend>
      <div className="mb-4">
        <h2 className="flex items-center gap-2 font-heading text-lg font-semibold text-gray-900">
          <Icon className="text-primary-600" size={17} aria-hidden="true" /> {title}
        </h2>
        {hint && <p className="mt-1 text-sm text-gray-500">{hint}</p>}
      </div>
      <div className="space-y-4">{children}</div>
    </fieldset>
  )
}

function Field({ form, name, label, hint, children }) {
  return (
    <label className="block">
      <span className="mb-1 block text-sm font-medium text-gray-700">{label}</span>
      {children}
      {hint && <span className="mt-1 block text-xs text-gray-500">{hint}</span>}
      <FieldError form={form} name={name} />
    </label>
  )
}

/**
 * The About page's "Discover the Municipalities of Sorsogon": each town's contact details,
 * geography, history and photo. They describe the whole province, so provincial admins keep them.
 */
export default function ManageMunicipalityProfiles() {
  const { isProvincial } = useAdminScope()
  const [towns, setTowns] = useState(null)
  const [search, setSearch] = useState('')
  const [profile, setProfile] = useState(null)
  const [formData, setFormData] = useState(null)
  const [dirty, setDirty] = useState(false)
  const [previewing, setPreviewing] = useState(false)
  const [saving, setSaving] = useState(false)
  const formRef = useRef(null)

  const form = useFormErrors(formRef, {
    validate: () => ({
      image: formData?.image && formData.image.size > 5 * 1024 * 1024 ? 'Choose an image of 5 MB or less.' : null,
    }),
  })

  const loadTowns = () =>
    getAdminMunicipalityProfiles()
      .then(setTowns)
      .catch((error) => {
        setTowns([])
        showApiError(error, 'Could not load the municipalities.')
      })

  useEffect(() => {
    if (isProvincial) loadTowns()
  }, [isProvincial])

  // A chosen photo is shown before it is saved; its preview link is released when replaced.
  const newImage = formData?.image
  const newPhotoUrl = useMemo(() => (newImage ? URL.createObjectURL(newImage) : null), [newImage])
  useEffect(() => () => newPhotoUrl && URL.revokeObjectURL(newPhotoUrl), [newPhotoUrl])

  const shown = useMemo(() => {
    const q = search.trim().toLowerCase()
    return (towns || []).filter((town) => !q || town.name.toLowerCase().includes(q))
  }, [towns, search])

  const edit = async (town) => {
    try {
      const data = await getAdminMunicipalityProfile(town.id)
      setProfile(data)
      setFormData(formFrom(data))
      setDirty(false)
      form.setErrors({})
      window.scrollTo({ top: 0, behavior: 'smooth' })
    } catch (error) {
      showApiError(error, `Could not open ${town.name}.`)
    }
  }

  const backToList = async () => {
    if (dirty && !(await confirmDialog({ title: 'Discard your changes?', message: `Your changes to ${profile.name} haven't been saved.`, confirmLabel: 'Discard', danger: true }))) return
    setProfile(null)
    setFormData(null)
    setPreviewing(false)
  }

  const set = (field, value) => {
    setFormData((current) => ({ ...current, [field]: value }))
    setDirty(true)
  }

  // What visitors will see: the form as it stands, with the new or kept photo.
  const previewProfile = formData && profile ? { ...profile, ...formData } : null
  const current = profile?.photo
  const photo = newPhotoUrl
    ? { url: newPhotoUrl, note: 'New photo, saved when you save the changes.' }
    : formData?.remove_image
      ? { url: null, note: `After saving, the card uses a photo of a destination in ${profile.name}.` }
      : current?.uploaded
        ? { url: getImageUrl(current.image_path), note: 'Uploaded photo.' }
        : current
          ? { url: getImageUrl(current.image_path), note: `Photo of ${current.caption} from Destinations. Upload a photo to replace it.` }
          : null

  const save = async (event) => {
    event?.preventDefault()
    if (!form.check()) {
      setPreviewing(false)
      return
    }
    const payload = new FormData()
    FIELDS.forEach((field) => payload.append(field, formData[field]))
    if (formData.image) payload.append('image', formData.image)
    if (formData.remove_image) payload.append('remove_image', '1')

    setSaving(true)
    try {
      const { message, profile: saved } = await updateMunicipalityProfile(profile.id, payload)
      toast.success(`${message} The About page now shows it.`)
      setPreviewing(false)
      setProfile(saved)
      setFormData(formFrom(saved))
      setDirty(false)
      loadTowns()
    } catch (error) {
      setPreviewing(false)
      if (!form.fromResponse(error)) showApiError(error, 'Could not save the changes.')
    } finally {
      setSaving(false)
    }
  }

  if (!isProvincial) {
    return (
      <div>
        <p className="text-primary-600 font-medium mb-2">Admin</p>
        <h1 className="text-2xl sm:text-3xl font-heading font-bold text-gray-900 mb-6">Municipality Profiles</h1>
        <p className="rounded-xl border border-gray-200 bg-white p-4 text-sm text-gray-600">
          The About page's municipality profiles describe the whole province, so only the provincial administrator can change them.
        </p>
      </div>
    )
  }

  if (towns === null) return <LoadingSpinner size="lg" />

  if (profile && formData) {
    return (
      <form ref={formRef} noValidate onChange={form.onChange} onSubmit={save}>
        <button type="button" onClick={backToList} className="mb-4 inline-flex items-center gap-1.5 text-sm font-medium text-primary-700 hover:underline">
          <FiArrowLeft size={15} aria-hidden="true" /> All municipalities
        </button>
        <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
          <div>
            <p className="text-primary-600 font-medium mb-2">Municipality Profiles</p>
            <h1 className="text-2xl sm:text-3xl font-heading font-bold text-gray-900">{profile.name}</h1>
            <p className="mt-2 max-w-2xl text-sm text-gray-500">
              Use official sources only. Leave a box empty when nothing has been verified: visitors then see "Not available".
              {profile.updated_by && ` Last saved by ${profile.updated_by} on ${formatShortDate(profile.updated_at)}.`}
            </p>
          </div>
        </div>

        <FormErrorSummary form={form} />

        <div className="grid grid-cols-1 gap-5 xl:grid-cols-2">
          <div className="space-y-5">
            <Group icon={FiPhone} title="Contact Information">
              <Field form={form} name="contact_number" label={profile.lgu_type === 'city' ? 'City Hall contact number' : 'Municipal Hall contact number'}>
                <input name="contact_number" className="input-field" maxLength={255} value={formData.contact_number} onChange={(e) => set('contact_number', e.target.value)} />
              </Field>
              <Field form={form} name="email" label="Official email address">
                <input name="email" type="email" className="input-field" maxLength={255} value={formData.email} onChange={(e) => set('email', e.target.value)} />
              </Field>
              <Field form={form} name="address" label="Official address or office location">
                <input name="address" className="input-field" maxLength={255} value={formData.address} onChange={(e) => set('address', e.target.value)} />
              </Field>
              <Field form={form} name="other_contacts" label="Other official contacts" hint="One per line, e.g. Website: …, Facebook: …, Tourism Office: …">
                <textarea name="other_contacts" rows={3} className="input-field" maxLength={2000} value={formData.other_contacts} onChange={(e) => set('other_contacts', e.target.value)} />
              </Field>
            </Group>

            <Group icon={FiMap} title="Geography">
              <Field form={form} name="geography" label="Location and characteristics" hint="Where it is, land area, terrain, coast, bodies of water.">
                <textarea name="geography" rows={5} className="input-field" maxLength={5000} value={formData.geography} onChange={(e) => set('geography', e.target.value)} />
              </Field>
              <Field form={form} name="boundaries" label="Boundaries and neighbouring municipalities">
                <textarea name="boundaries" rows={2} className="input-field" maxLength={2000} value={formData.boundaries} onChange={(e) => set('boundaries', e.target.value)} />
              </Field>
            </Group>
          </div>

          <div className="space-y-5">
            <Group icon={FiBookOpen} title="History">
              <Field form={form} name="history" label="Historical background and significant events" hint="Leave a blank line between paragraphs.">
                <textarea name="history" rows={9} className="input-field" maxLength={10000} value={formData.history} onChange={(e) => set('history', e.target.value)} />
              </Field>
              <Field form={form} name="name_origin" label="Origin or meaning of the name" hint="Only if documented. Where accounts differ, say so.">
                <textarea name="name_origin" rows={3} className="input-field" maxLength={3000} value={formData.name_origin} onChange={(e) => set('name_origin', e.target.value)} />
              </Field>
            </Group>

            <Group icon={FiImage} title="Representative photo" hint="Shown on the municipality's card. JPG, PNG or WebP, up to 5 MB.">
              {photo ? (
                <figure>
                  {photo.url && <img src={photo.url} alt="" className="aspect-4/3 w-full max-w-sm rounded-xl object-cover" />}
                  <figcaption className="mt-1.5 text-xs text-gray-500">{photo.note}</figcaption>
                </figure>
              ) : (
                <p className="text-sm text-gray-500">No photo yet: the card shows the municipality's name only.</p>
              )}
              <Field form={form} name="image" label={profile.photo?.uploaded ? 'Replace the photo' : 'Upload a photo'}>
                <input name="image" type="file" accept="image/jpeg,image/png,image/webp" className="input-field" onChange={(e) => set('image', e.target.files[0] || null)} />
              </Field>
              {profile.photo?.uploaded && !formData.image && (
                <label className="flex items-center gap-2 text-sm text-gray-700">
                  <input type="checkbox" checked={formData.remove_image} onChange={(e) => set('remove_image', e.target.checked)} />
                  Remove the uploaded photo (the card then uses a photo of a destination in {profile.name})
                </label>
              )}
            </Group>

            <Group icon={FiCheckCircle} title="Sources" hint="For the Tourism Office only; visitors don't see this.">
              <Field form={form} name="sources" label="Where this information comes from">
                <textarea name="sources" rows={3} className="input-field" maxLength={5000} value={formData.sources} onChange={(e) => set('sources', e.target.value)} />
              </Field>
            </Group>
          </div>
        </div>

        <div className="sticky bottom-0 z-10 -mx-4 mt-6 flex flex-wrap justify-end gap-3 border-t border-gray-200 bg-gray-50/95 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-xl sm:border">
          <button type="button" onClick={backToList} className="px-4 py-2 border border-gray-200 rounded-lg bg-white text-gray-600 hover:bg-gray-50">
            Cancel
          </button>
          <button type="button" onClick={() => setPreviewing(true)} className="inline-flex items-center gap-1.5 rounded-lg border border-primary-200 bg-white px-4 py-2 font-medium text-primary-700 hover:bg-primary-50">
            <FiEye size={16} aria-hidden="true" /> Preview
          </button>
          <button type="submit" disabled={saving} className="btn-primary">
            {saving ? 'Saving…' : 'Save changes'}
          </button>
        </div>

        {previewing && previewProfile && (
          <Modal
            title={profile.name}
            subtitle={`Preview: what visitors see on the About page · ${profile.lgu_type === 'city' ? 'Component city' : 'Municipality'} · Province of Sorsogon`}
            size="lg"
            busy={saving}
            onClose={() => setPreviewing(false)}
            footer={
              <>
                <button type="button" onClick={() => setPreviewing(false)} className="px-4 py-2 border border-gray-200 rounded-lg text-gray-600 hover:bg-gray-50">
                  Keep editing
                </button>
                <button type="button" onClick={save} disabled={saving} className="btn-primary">
                  {saving ? 'Saving…' : 'Save changes'}
                </button>
              </>
            }
          >
            {photo?.url && <img src={photo.url} alt="" className="mb-4 aspect-video w-full rounded-xl object-cover" />}
            <MunicipalityProfileView profile={previewProfile} />
          </Modal>
        )}
      </form>
    )
  }

  return (
    <div>
      <div className="mb-8">
        <p className="text-primary-600 font-medium mb-2">Admin</p>
        <h1 className="text-2xl sm:text-3xl font-heading font-bold text-gray-900">Municipality Profiles</h1>
        <p className="mt-2 max-w-3xl text-sm text-gray-500">
          The About page's "Discover the Municipalities of Sorsogon": each municipality's and Sorsogon City's contact information, geography, history and photo.
        </p>
      </div>

      <div className="relative mb-5 max-w-sm">
        <FiSearch className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={16} aria-hidden="true" />
        <input className="input-field pl-9" placeholder="Search municipalities..." aria-label="Search municipalities" value={search} onChange={(e) => setSearch(e.target.value)} />
      </div>

      {shown.length === 0 ? (
        <p className="rounded-xl border border-gray-200 bg-white p-6 text-center text-sm text-gray-500">No municipality matches "{search}".</p>
      ) : (
        <ul className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
          {shown.map((town) => (
            <li key={town.id}>
              <article className="flex h-full gap-4 rounded-2xl border border-gray-200/70 bg-white p-4 shadow-(--shadow-card)">
                <span className="h-20 w-20 shrink-0 overflow-hidden rounded-xl bg-gray-100">
                  {town.photo && <img src={getImageUrl(town.photo.thumbnail_path || town.photo.image_path)} alt="" className="h-full w-full object-cover" loading="lazy" />}
                </span>
                <div className="flex min-w-0 flex-1 flex-col">
                  <h2 className="font-heading text-lg font-semibold leading-snug text-gray-900">
                    {town.name}
                    {town.lgu_type === 'city' && <span className="ml-2 rounded-full bg-accent-100 px-2 py-0.5 align-middle text-xs font-semibold text-accent-700">City</span>}
                  </h2>
                  {town.missing.length > 0 ? (
                    <p className="mt-1 text-xs font-medium text-amber-700">Missing: {town.missing.map((key) => SECTION_LABELS[key]).join(', ')}</p>
                  ) : (
                    <p className="mt-1 text-xs font-medium text-green-700">All three sections filled in</p>
                  )}
                  <p className="mt-0.5 text-xs text-gray-500">
                    {town.updated_by ? `Saved by ${town.updated_by}, ${formatShortDate(town.updated_at)}` : 'Researched profile, not yet reviewed'}
                  </p>
                  <button type="button" onClick={() => edit(town)} className="mt-auto inline-flex w-fit items-center gap-1.5 pt-3 text-sm font-semibold text-primary-700 hover:underline">
                    <FiEdit2 size={14} aria-hidden="true" /> Edit {town.name}
                  </button>
                </div>
              </article>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
