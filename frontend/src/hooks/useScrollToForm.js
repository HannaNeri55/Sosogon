// frontend/src/hooks/useScrollToForm.js
import { useEffect, useRef } from "react";

/**
 * For admin pages whose add/edit form sits above the table: whenever `key`
 * changes to a truthy value (the record being edited, or "new"), smoothly bring
 * the form into view and put the cursor in its first field. Attach the
 * returned ref to the form.
 */
export default function useScrollToForm(key) {
  const ref = useRef(null);

  useEffect(() => {
    if (!key || !ref.current) return;
    const form = ref.current;
    const reduce = window.matchMedia?.("(prefers-reduced-motion: reduce)").matches;
    form.scrollIntoView({ behavior: reduce ? "auto" : "smooth", block: "start" });
    const field = form.querySelector("input:not([type=hidden]):not([disabled]), textarea, select");
    field?.focus({ preventScroll: true });
  }, [key]);

  return ref;
}
