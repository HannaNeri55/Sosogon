// frontend/src/hooks/useUrlFilters.js
import { useCallback, useEffect, useMemo, useState } from "react";
import { useLocation, useSearchParams } from "react-router-dom";
import { rememberListing } from "../utils/listingReturn";

/** Read `defaults`-shaped filters from URL params; numbers in `defaults` stay numbers. */
function parse(source, defaults) {
  const out = { ...defaults };
  for (const [key, fallback] of Object.entries(defaults)) {
    if (!source.has(key)) continue;
    const raw = source.get(key);
    out[key] = typeof fallback === "number" ? Number(raw) || fallback : raw;
  }
  return out;
}

/**
 * Drop-in replacement for `useState(filters)` on listing pages that keeps the
 * filters (search, category, page, …) in the address bar. Refreshing, sharing
 * the link or coming back with "Back to …" shows the same results. Values equal
 * to their default are left out of the URL.
 */
export default function useUrlFilters(defaults) {
  const [initial] = useState(defaults);
  const [, setParams] = useSearchParams();
  const { pathname, search } = useLocation();

  // Re-created only when the query string changes, so `useEffect(..., [filters])` fetches once per change.
  const filters = useMemo(() => parse(new URLSearchParams(search), initial), [search, initial]);

  const setFilters = useCallback(
    (update) =>
      setParams(
        (prev) => {
          const current = parse(prev, initial);
          const next = typeof update === "function" ? update(current) : update;
          const out = new URLSearchParams();
          for (const [key, fallback] of Object.entries(initial)) {
            const value = next[key];
            if (value !== "" && value != null && String(value) !== String(fallback)) out.set(key, value);
          }
          return out;
        },
        { replace: true }
      ),
    [initial, setParams]
  );

  useEffect(() => rememberListing(pathname, search), [pathname, search]);

  return [filters, setFilters];
}
