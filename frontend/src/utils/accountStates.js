// frontend/src/utils/accountStates.js
// Labels and colours for account enforcement, shared by the admin pages and the
// user's Account Health page so every screen describes a state the same way.

export const ACCOUNT_STATES = {
  active: { label: 'Active', className: 'bg-green-50 text-green-700 border-green-200', dot: 'bg-green-500' },
  warning: { label: 'Warning', className: 'bg-amber-50 text-amber-800 border-amber-200', dot: 'bg-amber-500' },
  suspended: { label: 'Temporarily suspended', className: 'bg-orange-50 text-orange-800 border-orange-200', dot: 'bg-orange-500' },
  deactivated: { label: 'Deactivated', className: 'bg-red-50 text-red-700 border-red-200', dot: 'bg-red-500' },
  pending: { label: 'Pending review', className: 'bg-sky-50 text-sky-800 border-sky-200', dot: 'bg-sky-500' },
  closed: { label: 'Closed', className: 'bg-gray-100 text-gray-600 border-gray-200', dot: 'bg-gray-400' },
}

export const ENFORCEMENT_ACTIONS = {
  warning: { label: 'Warning', verb: 'Send warning', className: 'bg-amber-50 text-amber-800 border-amber-200' },
  suspension: { label: 'Temporary suspension', verb: 'Suspend account', className: 'bg-orange-50 text-orange-800 border-orange-200' },
  deactivation: { label: 'Deactivation', verb: 'Deactivate account', className: 'bg-red-50 text-red-700 border-red-200' },
}

export const VIOLATION_STATUSES = {
  active: { label: 'In effect', className: 'bg-red-50 text-red-700' },
  expired: { label: 'Expired', className: 'bg-gray-100 text-gray-600' },
  lifted: { label: 'Lifted by admin', className: 'bg-sky-50 text-sky-800' },
  reversed: { label: 'Reversed on appeal', className: 'bg-green-50 text-green-700' },
}

export const APPEAL_STATUSES = {
  pending: { label: 'Appeal pending', className: 'bg-amber-50 text-amber-800' },
  approved: { label: 'Appeal approved', className: 'bg-green-50 text-green-700' },
  rejected: { label: 'Appeal rejected', className: 'bg-gray-100 text-gray-700' },
}

/** What each action does, shown before the admin confirms it. */
export const ACTION_CONSEQUENCES = {
  warning: [
    'The account stays active and the user can keep using SOSOGON.',
    'An official warning is added to their record and shown on their Account Health page.',
    'They are notified in-app and by email, with the reason, and can appeal.',
  ],
  suspension: [
    'The user is signed out of every device immediately.',
    'Until the suspension ends they can only see their Account Health page and submit an appeal; listings, reviews and other features are blocked.',
    'Access is restored automatically when the period ends. Their data is not changed or deleted.',
    'They are notified in-app and by email, with the reason and end date.',
  ],
  deactivation: [
    'The user is signed out of every device immediately.',
    'The account stays restricted until an administrator reactivates it (or an appeal is approved).',
    'They can only see their Account Health page and submit an appeal. Their data is not changed or deleted.',
    'They are notified in-app and by email, with the reason.',
  ],
}

/** "October 30, 2026, 6:05 PM" in Philippine time. */
export function formatDateTime(value) {
  if (!value) return '—'
  return new Date(value).toLocaleString('en-US', {
    month: 'long', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', timeZone: 'Asia/Manila',
  })
}

export function formatDay(value) {
  if (!value) return '—'
  return new Date(String(value).length === 10 ? `${value}T00:00:00` : value).toLocaleDateString('en-US', {
    month: 'long', day: 'numeric', year: 'numeric',
  })
}

export const roleLabel = (role) => (role === 'business_owner' ? 'Business owner' : role ? role.charAt(0).toUpperCase() + role.slice(1) : '')
