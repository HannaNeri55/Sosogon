// frontend/src/components/detail/FactsCard.jsx
import { FiGlobe, FiNavigation } from "react-icons/fi";
import { normalizeUrl } from "../../utils/helpers";
import { googleMapsDirectionsUrl, toCoordinates } from "../maps/mapsConfig";

/**
 * "Plan your visit" card: the place's key facts (address, contact, hours,
 * price, …) plus Get Directions / Visit Website. Facts with no value are
 * skipped; `children` adds page-specific actions below.
 */
export default function FactsCard({ title = "Plan your visit", facts = [], latitude, longitude, website, children }) {
  // Directions go to this listing's own saved coordinates; no button without a valid point.
  const point = toCoordinates(latitude, longitude);
  const directionsUrl = point ? googleMapsDirectionsUrl(point) : null;
  const websiteUrl = normalizeUrl(website);
  const shown = facts.filter((fact) => fact.value !== null && fact.value !== undefined && fact.value !== "");

  return (
    <div className="bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) p-6">
      <h2 className="font-heading text-lg font-bold text-gray-900">{title}</h2>

      {shown.length > 0 && (
        <dl className="mt-4 divide-y divide-gray-100">
          {shown.map(({ icon: Icon, label, value }) => (
            <div key={label} className="flex items-start gap-3 py-3 first:pt-0">
              {Icon && (
                <span className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary-50 text-primary-600">
                  <Icon size={15} aria-hidden="true" />
                </span>
              )}
              <div className="min-w-0">
                <dt className="text-xs font-semibold uppercase tracking-wider text-gray-500">{label}</dt>
                <dd className="mt-0.5 text-sm text-gray-800 wrap-break-word">{value}</dd>
              </div>
            </div>
          ))}
        </dl>
      )}

      {(directionsUrl || websiteUrl || children) && (
        <div className="mt-5 flex flex-col gap-2.5">
          {directionsUrl && (
            <a href={directionsUrl} target="_blank" rel="noopener noreferrer" className="btn-primary w-full">
              <FiNavigation size={16} aria-hidden="true" /> Get Directions
            </a>
          )}
          {websiteUrl && (
            <a href={websiteUrl} target="_blank" rel="noopener noreferrer" className="btn-secondary w-full">
              <FiGlobe size={16} aria-hidden="true" /> Visit Website
            </a>
          )}
          {children}
        </div>
      )}
    </div>
  );
}
