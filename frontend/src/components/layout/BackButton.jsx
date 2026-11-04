// frontend/src/components/layout/BackButton.jsx
import { Link } from "react-router-dom";
import { FiArrowLeft } from "react-icons/fi";
import { listingReturnUrl } from "../../utils/listingReturn";

/**
 * The one back button used across the site: top-left, above the page title,
 * same icon, size and style everywhere. Returns to `to` with the filters and
 * page the visitor last used there, never to the homepage by surprise.
 * Pass `onClick` instead of `to` for in-page views such as room details.
 */
export default function BackButton({ to, label, onClick, className = "" }) {
  const classes = `group inline-flex items-center gap-2 rounded-full py-1.5 pl-2 pr-3.5 -ml-2 text-sm font-medium text-gray-600 hover:text-primary-700 hover:bg-primary-50 transition-colors ${className}`;
  const content = (
    <>
      <FiArrowLeft size={16} className="transition-transform group-hover:-translate-x-0.5" aria-hidden="true" />
      {label}
    </>
  );

  return onClick ? (
    <button type="button" onClick={onClick} className={classes}>
      {content}
    </button>
  ) : (
    <Link to={listingReturnUrl(to)} state={{ restoreScroll: true }} className={classes}>
      {content}
    </Link>
  );
}
