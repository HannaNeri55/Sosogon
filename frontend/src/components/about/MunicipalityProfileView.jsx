import { FiBookOpen, FiMap, FiPhone } from 'react-icons/fi'

/** A value, or "Not available" when the Tourism Office has nothing verified to show. */
function Value({ children, multiline = false }) {
  if (!children || !String(children).trim()) return <span className="italic text-gray-400">Not available</span>
  return <span className={multiline ? 'whitespace-pre-line' : undefined}>{children}</span>
}

function Row({ label, children }) {
  return (
    <div className="grid gap-0.5 sm:grid-cols-[9.5rem_1fr] sm:gap-4">
      <dt className="text-xs font-semibold uppercase tracking-wider text-gray-500 sm:pt-0.5">{label}</dt>
      <dd className="text-sm leading-relaxed text-gray-700 wrap-anywhere">{children}</dd>
    </div>
  )
}

function Section({ icon: Icon, title, children }) {
  return (
    <section className="rounded-2xl border border-gray-200/70 bg-white p-4 sm:p-5">
      <h3 className="mb-3 flex items-center gap-2 font-heading text-lg font-semibold text-gray-900">
        <span className="flex h-8 w-8 items-center justify-center rounded-full bg-primary-50 text-primary-600">
          <Icon size={15} aria-hidden="true" />
        </span>
        {title}
      </h3>
      <dl className="space-y-3">{children}</dl>
    </section>
  )
}

/**
 * A municipality's or Sorsogon City's profile: contact information, geography and history only.
 * Shown in the About page's popup and, before saving, in the provincial admin's preview.
 */
export default function MunicipalityProfileView({ profile }) {
  const email = profile.email?.trim()

  return (
    <div className="space-y-4">
      <Section icon={FiPhone} title="Contact Information">
        <Row label={profile.lgu_type === 'city' ? 'City Hall' : 'Municipal Hall'}>
          <Value>{profile.contact_number}</Value>
        </Row>
        <Row label="Email">
          {email ? (
            <a href={`mailto:${email}`} className="font-medium text-primary-700 hover:underline">{email}</a>
          ) : (
            <Value />
          )}
        </Row>
        <Row label="Address">
          <Value>{profile.address}</Value>
        </Row>
        {profile.other_contacts?.trim() && (
          <Row label="Other contacts">
            <Value multiline>{profile.other_contacts}</Value>
          </Row>
        )}
      </Section>

      <Section icon={FiMap} title="Geography">
        <Row label="Location">
          <Value multiline>{profile.geography}</Value>
        </Row>
        <Row label="Boundaries">
          <Value multiline>{profile.boundaries}</Value>
        </Row>
      </Section>

      <Section icon={FiBookOpen} title="History">
        <Row label="Background">
          <Value multiline>{profile.history}</Value>
        </Row>
        <Row label="Origin of the name">
          <Value multiline>{profile.name_origin}</Value>
        </Row>
      </Section>
    </div>
  )
}
