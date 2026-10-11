// Admins sign in on their own page; everyone else uses the main login.
export function loginPathFor(role) {
  return role === 'admin' ? '/login/admin' : '/login'
}

// Login page to send someone to when their session has ended on its own (token expired
// or idle logout); ?expired=1 makes the page explain why.
export function expiredLoginPathFor(role) {
  return `${loginPathFor(role)}?expired=1`
}
