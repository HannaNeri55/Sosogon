// frontend/src/components/admin/accounts/AddOfficeAdminModal.jsx
import { useEffect, useRef, useState } from 'react'
import toast from 'react-hot-toast'
import Modal from '../../common/Modal'
import { createOfficeAdmin, getMunicipalities } from '../../../api/admin'
import useFormErrors from '../../../hooks/useFormErrors'
import useAdminScope from '../../../hooks/useAdminScope'
import FieldError, { FormErrorSummary } from '../../../components/common/FieldError'

/**
 * The provincial administrator gives a municipality's tourism office its admin account. The
 * account manages that municipality only; the officer sets their own password from the emailed
 * link, so nobody else ever knows it.
 */
export default function AddOfficeAdminModal({ onClose, onDone }) {
  const { officeFor } = useAdminScope()
  const [towns, setTowns] = useState([])
  const [fields, setFields] = useState({ name: '', email: '', phone: '', municipality_id: '', title: '', confirm_municipality: '' })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const town = towns.find((t) => String(t.id) === String(fields.municipality_id)) ?? null
  const existingOffice = town ? officeFor(town.id) : null

  useEffect(() => {
    getMunicipalities().then((list) => setTowns(Array.isArray(list) ? list : list?.data ?? [])).catch(() => {})
  }, [])

  const set = (name) => (event) => setFields((current) => ({ ...current, [name]: event.target.value }))

  const formRef = useRef(null)
  const form = useFormErrors(formRef, {
    validate: () => ({
      confirm_municipality: town && fields.confirm_municipality.trim().toLowerCase() !== town.name.toLowerCase()
        ? `Type "${town.name}" to confirm.`
        : null,
    }),
  })

  const submit = async (event) => {
    event.preventDefault()
    if (busy || !form.check()) return
    setBusy(true)
    setError(null)
    try {
      const data = await createOfficeAdmin({ ...fields, municipality_id: Number(fields.municipality_id) })
      if (data.email_sent) toast.success(data.message)
      else toast(data.message, { duration: 8000 })
      onDone()
    } catch (err) {
      if (!form.fromResponse(err) && !err.errorDialogShown) setError(err.response?.data?.message || 'The account could not be created.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      title="Add tourism office admin"
      subtitle="An administrator account for one municipality's tourism office"
      onClose={onClose}
      busy={busy}
      footer={
        <>
          <button type="button" onClick={onClose} disabled={busy} className="btn-secondary">Cancel</button>
          <button type="submit" form="office-admin-form" disabled={busy} className="btn-primary">
            {busy ? 'Creating…' : 'Create account'}
          </button>
        </>
      }
    >
      <form id="office-admin-form" ref={formRef} noValidate onChange={form.onChange} onSubmit={submit} className="space-y-4">
        <FormErrorSummary form={form} />
        <div className="rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-800">
          <p className="font-semibold">Create this only after the tourism office's official request, once you have confirmed the officer's identity.</p>
          <ul className="mt-2 list-disc space-y-1 pl-4">
            <li>The account manages the chosen municipality only. From then on, that municipality is managed by its own office.</li>
            <li>The officer gets an email to set their own password. You never see or choose it.</li>
            <li>The creation is recorded in the account activity log. You can deactivate the access later from this page.</li>
          </ul>
        </div>
        <div>
          <label htmlFor="office-municipality" className="block text-sm font-semibold text-gray-800 mb-1.5">Municipality</label>
          <select id="office-municipality" name="municipality_id" required className="input-field" value={fields.municipality_id} onChange={set('municipality_id')}>
            <option value="">Choose a municipality</option>
            {towns.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
          </select>
          <FieldError form={form} name="municipality_id" />
          {existingOffice && (
            <p className="mt-1 text-xs text-amber-700">{town.name} already has an active account ({existingOffice}). This adds another one.</p>
          )}
        </div>
        <div>
          <label htmlFor="office-name" className="block text-sm font-semibold text-gray-800 mb-1.5">Officer's full name</label>
          <input id="office-name" name="name" required minLength={2} maxLength={100} className="input-field" autoComplete="off" value={fields.name} onChange={set('name')} />
          <FieldError form={form} name="name" />
        </div>
        <div>
          <label htmlFor="office-email" className="block text-sm font-semibold text-gray-800 mb-1.5">Officer's email address</label>
          <input id="office-email" name="email" type="email" required maxLength={255} className="input-field" autoComplete="off" value={fields.email} onChange={set('email')} />
          <FieldError form={form} name="email" />
          <p className="mt-1 text-xs text-gray-500">The password setup link is sent here.</p>
        </div>
        <div>
          <label htmlFor="office-phone" className="block text-sm font-semibold text-gray-800 mb-1.5">Phone number <span className="font-normal text-gray-500">(optional)</span></label>
          <input id="office-phone" name="phone" maxLength={20} className="input-field" autoComplete="off" value={fields.phone} onChange={set('phone')} />
          <FieldError form={form} name="phone" />
        </div>
        <div>
          <label htmlFor="office-title" className="block text-sm font-semibold text-gray-800 mb-1.5">Designation <span className="font-normal text-gray-500">(optional)</span></label>
          <input id="office-title" name="title" maxLength={100} className="input-field" placeholder={town ? `${town.name} Tourism Office` : 'e.g. Bulan Tourism Office'} value={fields.title} onChange={set('title')} />
          <FieldError form={form} name="title" />
        </div>
        {town && (
          <div>
            <label htmlFor="office-confirm" className="block text-sm font-semibold text-gray-800 mb-1.5">
              Type <span className="font-mono text-red-700">{town.name}</span> to confirm
            </label>
            <input id="office-confirm" name="confirm_municipality" required className="input-field" autoComplete="off" value={fields.confirm_municipality} onChange={set('confirm_municipality')} />
            <FieldError form={form} name="confirm_municipality" />
          </div>
        )}
        {error && <p className="rounded-lg bg-red-50 px-3 py-2 text-sm font-medium text-red-700">{error}</p>}
      </form>
    </Modal>
  )
}
