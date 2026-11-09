// frontend/src/utils/listingReturn.js
// Listing pages remember their last query string (search, filters, page) here,
// so a "Back to …" button can return to exactly what the visitor was browsing.

const key = (path) => `sosogon:return:${path}`;

/** Store the listing's current search string (e.g. "?q=beach&page=2") for `path`. */
export function rememberListing(path, search) {
  try {
    sessionStorage.setItem(key(path), search || "");
  } catch {
    // Storage can be unavailable (private mode); returning to the plain listing is fine.
  }
}

/** The listing URL to return to: `path` plus its remembered search string, if any. */
export function listingReturnUrl(path) {
  try {
    return path + (sessionStorage.getItem(key(path)) || "");
  } catch {
    return path;
  }
}
