// frontend/src/components/layout/ScrollManager.jsx
import { useEffect } from "react";
import { useLocation, useNavigationType } from "react-router-dom";

/**
 * Opening a new page starts at the top. Browser Back/Forward and "Back to …"
 * returns are left alone, so listing pages can restore where the visitor was.
 * Filter changes on the same page (query string only) don't jump either.
 */
export default function ScrollManager() {
  const { pathname, state } = useLocation();
  const navigationType = useNavigationType();

  useEffect(() => {
    if (navigationType !== "POP" && !state?.restoreScroll) window.scrollTo(0, 0);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pathname]);

  return null;
}
