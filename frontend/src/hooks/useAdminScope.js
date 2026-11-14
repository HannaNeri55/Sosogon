import { useAuth } from '../contexts/AuthContext'

/**
 * The signed-in admin's authority, from the `admin` profile that login and /user return
 * (backend App\Support\AdminScope):
 *  - a municipal admin manages their municipality's records only;
 *  - a municipality with its own tourism office (`ownOffices`) is managed by that office alone:
 *    provincial admins see its records but don't change them (Featured and Tourism priority
 *    aside);
 *  - provincial admins manage the province-wide content (hero slides, Featured and Tourism
 *    priority, events and announcements without a municipality) and every municipality without
 *    an office of its own.
 * The server enforces all of this; these flags only shape the screens (locked municipality,
 * hidden actions). Lists also mark each record with can_manage and managed_by, which screens
 * prefer when they have them. An admin whose scope isn't assigned yet keeps provincial access,
 * and `assigned` is false so the screens can say so. An invalid scope ('invalid') has no access.
 */
export default function useAdminScope() {
  const { user } = useAuth()
  const admin = user?.admin ?? null
  const isMunicipal = admin?.scope === 'municipal'
  const invalid = admin?.scope === 'invalid'
  const municipality = isMunicipal ? admin.municipality ?? null : null
  const ownOffices = isMunicipal || invalid ? [] : admin?.own_offices ?? []
  const officeOf = (municipalityId) =>
    ownOffices.find((town) => municipalityId != null && Number(town.id) === Number(municipalityId)) ?? null

  return {
    isMunicipal,
    isProvincial: !isMunicipal && !invalid,
    invalid,
    assigned: admin ? admin.assigned !== false : true,
    municipality,
    designation: admin?.designation ?? 'Tourism Office',
    /** Municipalities whose own tourism office manages them: [{ id, name, office }]. */
    ownOffices,
    /** The office that manages `municipalityId` instead of this admin (e.g. "Bulan Tourism Office"), or null. */
    officeFor: (municipalityId) => officeOf(municipalityId)?.office ?? null,
    /** Whether this admin may change a record of `municipalityId` (null: province-wide). */
    canManage: (municipalityId) => {
      if (invalid) return false
      if (isMunicipal) return municipalityId != null && municipality != null && Number(municipalityId) === Number(municipality.id)
      return officeOf(municipalityId) === null
    },
  }
}
