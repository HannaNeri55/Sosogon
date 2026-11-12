// The signed-in account for this browser: its login token and user, in localStorage so every tab
// of the same browser profile shares one account (like Facebook), while another browser, profile
// or computer signs in on its own. Tabs follow each other through the 'storage' event
// (AuthContext). The server refuses a login that arrives with a valid token, so a second account
// can't replace the first; logging out clears it here for every tab.
export const TOKEN_KEY = 'token'
export const USER_KEY = 'user'

function storage() {
  try {
    return window.localStorage
  } catch {
    return null // Site data blocked: nothing can be remembered, every page load starts signed out.
  }
}

// Sessions from before this change lived in one tab's sessionStorage: move one over once.
try {
  const legacy = window.sessionStorage.getItem(TOKEN_KEY)
  if (legacy && storage() && !storage().getItem(TOKEN_KEY)) {
    storage().setItem(TOKEN_KEY, legacy)
    storage().setItem(USER_KEY, window.sessionStorage.getItem(USER_KEY) || 'null')
  }
  window.sessionStorage.removeItem(TOKEN_KEY)
  window.sessionStorage.removeItem(USER_KEY)
} catch {
  // Storage unavailable: nothing to move.
}

export function getToken() {
  return storage()?.getItem(TOKEN_KEY) || null
}

export function getStoredUser() {
  try {
    return JSON.parse(storage()?.getItem(USER_KEY) || 'null')
  } catch {
    return null
  }
}

export function saveSession(token, user) {
  try {
    storage()?.setItem(TOKEN_KEY, token)
    storage()?.setItem(USER_KEY, JSON.stringify(user))
  } catch {
    // Storage full or blocked: this tab stays signed in until it reloads.
  }
}

export function saveStoredUser(user) {
  try {
    storage()?.setItem(USER_KEY, JSON.stringify(user))
  } catch {
    // See saveSession.
  }
}

export function clearSession() {
  try {
    storage()?.removeItem(TOKEN_KEY)
    storage()?.removeItem(USER_KEY)
  } catch {
    // Nothing stored.
  }
}
