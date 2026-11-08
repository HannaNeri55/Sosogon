// frontend/src/components/layout/ScrollManager.jsx
import { useEffect, useLayoutEffect, useRef } from "react";
import { useLocation, useNavigationType } from "react-router-dom";
import { rememberOrigin } from "../../utils/navHistory";

/**
 * Opening a new page starts at the top. Browser Back/Forward and "Back to …"
 * returns are left alone, so listing pages can restore where the visitor was.
 * Filter changes on the same page (query string only) don't jump either.
 * Also records which page each new page was opened from, for the back button.
 */
export default function ScrollManager() {
  const location = useLocation();
  const { pathname, state } = location;
  const navigationType = useNavigationType();
  const previous = useRef(null);

  useEffect(() => {
    if (navigationType !== "POP" && !state?.restoreScroll) window.scrollTo(0, 0);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pathname]);

  // A layout effect, so the new page's back button (subscribed to navHistory) picks
  // up where it was opened from before the browser paints.
  useLayoutEffect(() => {
    const prev = previous.current;
    if (navigationType === "PUSH" && prev && prev.pathname !== location.pathname) {
      rememberOrigin(location.key, { pathname: prev.pathname, search: prev.search });
    }
    previous.current = location;
  }, [location, navigationType]);

  return null;
}
