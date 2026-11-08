// frontend/src/components/events/AnnouncementBoard.jsx
import { useCallback, useState } from 'react'
import { FiAlertTriangle, FiArrowRight, FiBell, FiCompass, FiMusic } from 'react-icons/fi'
import AnnouncementModal from './AnnouncementModal'

const KINDS = {
  safety: { label: 'Safety', icon: FiAlertTriangle, panel: 'from-red-700 to-red-900' },
  festival: { label: 'Festivals', icon: FiMusic, panel: 'from-accent-700 to-amber-900' },
  travel: { label: 'Travel Tips', icon: FiCompass, panel: 'from-primary-500 to-primary-800' },
  notice: { label: 'Notice', icon: FiBell, panel: 'from-secondary-500 to-secondary-800' },
}

/** Announcements have no category of their own, so the title decides their color and icon. */
function kindOf(announcement) {
  const title = announcement.title
  if (/safety|advisory|danger|warning|alert|volcano|typhoon|closure/i.test(title)) return KINDS.safety
  if (/fiesta|festival|celebration/i.test(title)) return KINDS.festival
  if (/plan|trip|book|visit|travel|tips|guide|whale/i.test(title)) return KINDS.travel
  return KINDS.notice
}

const formatDate = (value) =>
  new Date(value).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })

/** The text with its paragraphs close together, and "•" lines as a list that uses the full width. */
function AnnouncementText({ content }) {
  return (
    <div className="space-y-3 text-gray-700 leading-relaxed">
      {content.split(/\n\s*\n/).map((block) => {
        const lines = block.split('\n')
        if (!lines.every((line) => line.trim().startsWith('•'))) {
          return <p key={block} className="whitespace-pre-line">{block}</p>
        }
        return (
          <ul key={block} className="grid gap-2 sm:grid-cols-2">
            {lines.map((line) => (
              <li key={line} className="rounded-lg bg-gray-50 border border-gray-200/70 px-3.5 py-2 text-sm font-medium text-gray-800">
                {line.replace(/^\s*•\s*/, '')}
              </li>
            ))}
          </ul>
        )
      })}
    </div>
  )
}

/** The latest announcement in full across the page, then the rest as cards that open in a dialog. */
export default function AnnouncementBoard({ announcements }) {
  const [open, setOpen] = useState(null)
  const close = useCallback(() => setOpen(null), [])

  const [lead, ...rest] = announcements
  const leadKind = kindOf(lead)
  const LeadIcon = leadKind.icon

  return (
    <>
      <article className="card overflow-hidden grid md:grid-cols-[260px_1fr] mb-6">
        <div className={`relative overflow-hidden bg-linear-to-br ${leadKind.panel} text-white p-6 sm:p-7 flex flex-col justify-between gap-6`}>
          <LeadIcon className="absolute -right-6 -bottom-6 opacity-15" size={150} aria-hidden="true" />
          <span className="relative text-xs font-semibold uppercase tracking-[0.18em]">
            {lead.is_pinned ? 'Pinned' : 'Latest'} · {leadKind.label}
          </span>
          <time className="relative font-heading text-2xl font-bold leading-tight" dateTime={lead.created_at}>
            {formatDate(lead.created_at)}
          </time>
        </div>
        <div className="p-6 sm:p-7">
          <h3 className="text-2xl sm:text-3xl font-heading font-bold text-gray-900 leading-tight mb-3">{lead.title}</h3>
          <AnnouncementText content={lead.content} />
        </div>
      </article>

      {rest.length > 0 && (
        <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
          {rest.map((announcement) => {
            const kind = kindOf(announcement)
            const Icon = kind.icon
            return (
              <button
                key={announcement.id}
                type="button"
                onClick={() => setOpen(announcement)}
                className="group flex h-full flex-col overflow-hidden rounded-(--radius-card) border border-gray-200/70 bg-white text-left shadow-(--shadow-card) hover:shadow-(--shadow-card-hover) motion-safe:hover:-translate-y-0.5 transition duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
              >
                <div className={`relative h-24 overflow-hidden bg-linear-to-br ${kind.panel} text-white px-5 py-4 flex flex-col justify-between`}>
                  <Icon className="absolute -right-3 -bottom-5 opacity-20" size={96} aria-hidden="true" />
                  <span className="relative text-xs font-semibold uppercase tracking-[0.18em]">
                    {announcement.is_pinned ? `Pinned · ${kind.label}` : kind.label}
                  </span>
                  <time className="relative text-sm font-medium" dateTime={announcement.created_at}>
                    {formatDate(announcement.created_at)}
                  </time>
                </div>
                <div className="flex flex-1 flex-col p-5">
                  <h3 className="font-heading text-lg font-bold text-gray-900 leading-snug group-hover:text-primary-700 transition-colors">
                    {announcement.title}
                  </h3>
                  <p className="mt-2 text-sm text-gray-600 leading-relaxed line-clamp-3">{announcement.content.replace(/\s*•\s*/g, ' ')}</p>
                  <span className="mt-auto pt-4 inline-flex items-center gap-1 text-sm font-semibold text-primary-700">
                    Read more <FiArrowRight size={14} className="transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
                  </span>
                </div>
              </button>
            )
          })}
        </div>
      )}

      {open && <AnnouncementModal announcement={open} onClose={close} />}
    </>
  )
}
