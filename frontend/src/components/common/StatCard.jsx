import { Link } from 'react-router-dom'

const ACCENTS = {
  primary: 'bg-primary-50 text-primary-600',
  secondary: 'bg-secondary-50 text-secondary-600',
  yellow: 'bg-accent-50 text-accent-700',
  green: 'bg-secondary-50 text-secondary-600',
  red: 'bg-red-50 text-red-600',
}

/** Number tile for dashboards; pass `to` to make it a link to the related admin page. */
export default function StatCard({ icon: Icon, label, value, accent = 'primary', to }) {
  const className =
    'bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) p-4 sm:p-5 flex flex-col sm:flex-row items-start sm:items-center gap-3 sm:gap-4'
  const content = (
    <>
      <div className={`w-10 h-10 sm:w-12 sm:h-12 rounded-xl flex items-center justify-center shrink-0 ${ACCENTS[accent] ?? ACCENTS.primary}`}>
        <Icon size={20} aria-hidden="true" />
      </div>
      <div className="min-w-0">
        <p className="text-2xl sm:text-3xl font-heading font-bold text-gray-900 leading-tight tabular-nums">{value}</p>
        <p className="text-xs sm:text-sm font-medium text-gray-500 leading-snug sm:truncate">{label}</p>
      </div>
    </>
  )

  // Phones (two tiles per row): icon above the number and the label may wrap, so it isn't cut off.
  return to ? (
    <Link to={to} className={`${className} hover:shadow-(--shadow-card-hover) hover:border-primary-200 transition-[box-shadow,border-color]`}>
      {content}
    </Link>
  ) : (
    <div className={className}>{content}</div>
  )
}
