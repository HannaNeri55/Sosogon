// frontend/src/hooks/useListingScroll.js
import { useEffect, useRef } from "react";
import { useLocation, useNavigationType } from "react-router-dom";

const storageKey = (location) => `sosogon:scroll:${location.pathname}${location.search}`;

/**
 * Remembers how far down a listing page the visitor scrolled and, when they
 * come back (browser Back, or a "Back to …" button), returns them there once
 * the results have loaded (`ready`). Fresh visits start at the top as usual.
 */
export default function useListingScroll(ready) {
  const location = useLocation();
  const navigationType = useNavigationType();
  const keyRef = useRef(storageKey(location));
  const yRef = useRef(0);
  const restoreRef = useRef(navigationType === "POP" || location.state?.restoreScroll === true);

  useEffect(() => {
    keyRef.current = storageKey(location);
  }, [location]);

  useEffect(() => {
    const onScroll = () => {
      yRef.current = window.scrollY;
    };
    window.addEventListener("scroll", onScroll, { passive: true });
    return () => {
      window.removeEventListener("scroll", onScroll);
      try {
        sessionStorage.setItem(keyRef.current, String(yRef.current));
      } catch {
        // Without storage the visitor simply starts at the top.
      }
    };
  }, []);

  useEffect(() => {
    if (!ready || !restoreRef.current) return;
    restoreRef.current = false;
    let y = 0;
    try {
      y = Number(sessionStorage.getItem(keyRef.current)) || 0;
    } catch {
      return;
    }
    if (y > 0) requestAnimationFrame(() => window.scrollTo({ top: y }));
  }, [ready]);
}
