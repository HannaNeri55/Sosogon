import { useAuth } from '../contexts/AuthContext'

/**
 * The signed-in admin's authority, from the `admin` profile that login and /user return
 * (backend App\Support\AdminScope):
 *  - provincial admins manage every municipality and the province-wide content (hero slides,
 *    Featured and Tourism priority, events and announcements without a municipality);
 *  - a municipal admin manages their municipality's records only.
 * The server enforces all of this; these flags only shape the screens (locked municipality,
 * hidden province-only controls). An admin whose scope isn't assigned yet keeps full access, and
 * `assigned` is false so the screens can say so. An invalid scope ('invalid') has no access at all.
 */
export default function useAdminScope() {
  const { user } = useAuth()
  const admin = user?.admin ?? null
  const isMunicipal = admin?.scope === 'municipal'
  const invalid = admin?.scope === 'invalid'
  const municipality = isMunicipal ? admin.municipality ?? null : null

  return {
    isMunicipal,
    isProvincial: !isMunicipal && !invalid,
    invalid,
    assigned: admin ? admin.assigned !== false : true,
    municipality,
    designation: admin?.designation ?? 'Tourism Office',
    /** Whether this admin may change a record of `municipalityId` (null: province-wide). */
    canManage: (municipalityId) =>
      !invalid && (!isMunicipal || (municipalityId != null && municipality != null && Number(municipalityId) === Number(municipality.id))),
  }
}
