import { FiUserCheck, FiFileText, FiCheckSquare } from 'react-icons/fi'

/**
 * The three independent approval stages, in the order a business goes through
 * them. Each is still its own page and route; approving one stage never
 * approves the next. Shared by the admin sidebar group and the stage tabs.
 */
export const APPROVALS_LABEL = 'Registration & Approvals'

export const APPROVAL_STAGES = [
  {
    to: '/admin/owner-registrations',
    icon: FiUserCheck,
    label: 'Owner Accounts',
    hint: 'Owner details and business permit',
  },
  {
    to: '/admin/submissions',
    icon: FiFileText,
    label: 'Business Listings',
    hint: 'Initial listing submissions',
  },
  {
    to: '/admin/listing-reviews',
    icon: FiCheckSquare,
    label: 'Publication Review',
    hint: 'Final check before going public',
  },
]

export const isApprovalPath = (pathname) =>
  APPROVAL_STAGES.some((stage) => pathname === stage.to || pathname.startsWith(`${stage.to}/`))
