// frontend/src/components/layout/BackButton.jsx
import { useSyncExternalStore } from "react";
import { Link, useLocation, useNavigate } from "react-router-dom";
import { FiArrowLeft } from "react-icons/fi";
import { listingReturnUrl } from "../../utils/listingReturn";
import { backLabelFor, originOf, subscribeOrigins } from "../../utils/navHistory";

/**
 * The one back button used across the site: top-left, above the page title,
 * same icon, size and style everywhere. When the visitor opened this page from
 * another page of the site (an itinerary, a filtered listing, another detail
 * page), it steps back through the browser history, so that page comes back as
 * they left it, and its label names that page. Opened directly (a shared link,
 * a new tab), it goes to `to` with the filters the visitor last used there.
 * Pass `onClick` instead of `to` for in-page views such as room details.
 */
export default function BackButton({ to, label, onClick, className = "" }) {
  const location = useLocation();
  const navigate = useNavigate();
  const classes = `group inline-flex items-center gap-2 rounded-full py-1.5 pl-2 pr-3.5 -ml-2 text-sm font-medium text-gray-600 hover:text-primary-700 hover:bg-primary-50 transition-colors ${className}`;
  const recorded = useSyncExternalStore(subscribeOrigins, () => originOf(location.key));
  const origin = onClick ? null : recorded;
  const content = (text) => (
    <>
      <FiArrowLeft size={16} className="transition-transform group-hover:-translate-x-0.5" aria-hidden="true" />
      {text}
    </>
  );

  if (onClick) {
    return (
      <button type="button" onClick={onClick} className={classes}>
        {content(label)}
      </button>
    );
  }

  if (origin) {
    return (
      <button type="button" onClick={() => navigate(-1)} className={classes}>
        {content(backLabelFor(origin.pathname) || "Back")}
      </button>
    );
  }

  return (
    <Link to={listingReturnUrl(to)} state={{ restoreScroll: true }} className={classes}>
      {content(label)}
    </Link>
  );
}
