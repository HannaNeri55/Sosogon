import { Link, NavLink, Outlet, useLocation } from 'react-router-dom'
import { FiChevronRight } from 'react-icons/fi'
import { APPROVALS_LABEL, APPROVAL_STAGES } from './approvalStages'

/**
 * Breadcrumb + stage tabs above the three approval pages (Owner Accounts,
 * Business Listings, Publication Review). The pages themselves are unchanged
 * and keep their own URLs.
 */
export default function ApprovalsLayout() {
  const { pathname } = useLocation()
  const current = APPROVAL_STAGES.find((stage) => pathname.startsWith(stage.to))

  return (
    <div>
      <nav aria-label="Breadcrumb" className="mb-4">
        <ol className="flex flex-wrap items-center gap-1 text-sm text-gray-500">
          <li><Link to="/admin" className="hover:text-primary-700">Admin</Link></li>
          <li aria-hidden="true"><FiChevronRight size={14} /></li>
          <li>{APPROVALS_LABEL}</li>
          {current && (
            <>
              <li aria-hidden="true"><FiChevronRight size={14} /></li>
              <li aria-current="page" className="font-medium text-gray-900">{current.label}</li>
            </>
          )}
        </ol>
      </nav>

      <div className="mb-6">
        <div className="flex gap-2 overflow-x-auto pb-1" role="tablist" aria-label={APPROVALS_LABEL}>
          {APPROVAL_STAGES.map((stage, index) => (
            <NavLink
              key={stage.to}
              to={stage.to}
              role="tab"
              aria-selected={current?.to === stage.to}
              className={({ isActive }) =>
                `flex min-h-11 shrink-0 items-center gap-2 rounded-lg border px-4 py-2 text-sm transition-colors ${
                  isActive
                    ? 'border-primary-600 bg-primary-600 text-white'
                    : 'border-gray-200 bg-white text-gray-700 hover:border-primary-300 hover:text-primary-700'
                }`
              }
            >
              <span className="flex h-5 w-5 items-center justify-center rounded-full bg-black/10 text-xs font-semibold">
                {index + 1}
              </span>
              <span className="font-medium">{stage.label}</span>
              <span className="hidden text-xs opacity-80 lg:inline">· {stage.hint}</span>
            </NavLink>
          ))}
        </div>
        <p className="mt-2 text-xs text-gray-500">
          Each stage is approved separately: approving an owner account does not approve or publish their listing.
        </p>
      </div>

      <Outlet />
    </div>
  )
}
