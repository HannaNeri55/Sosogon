// Admins sign in on their own page; everyone else uses the main login.
export function loginPathFor(role) {
  return role === 'admin' ? '/login/admin' : '/login'
}

// Login page to send someone to when their session has ended on its own (token expired
// or idle logout); ?expired=1 makes the page explain why.
export function expiredLoginPathFor(role) {
  return `${loginPathFor(role)}?expired=1`
}

// Where a signed-in account belongs: its dashboard, or Account Health while it is restricted.
export function homePathFor(user) {
  if (['suspended', 'deactivated'].includes(user?.account_state)) return '/account/status'
  if (user?.role === 'admin') return '/admin'
  if (user?.role === 'business_owner') return '/business'
  return '/dashboard'
}
