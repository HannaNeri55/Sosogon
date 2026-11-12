// frontend/src/components/detail/FactsCard.jsx
import { lazy, Suspense, useState } from "react";
import { FiGlobe, FiNavigation } from "react-icons/fi";
import { normalizeUrl } from "../../utils/helpers";
import { toCoordinates } from "../maps/mapsConfig";

// The navigation map pulls in Leaflet, so it loads only when someone asks for directions.
const NavigationView = lazy(() => import("../maps/NavigationView"));

/**
 * "Plan your visit" card: the place's key facts (address, contact, hours,
 * price, …) plus Get Directions / Visit Website. Facts with no value are
 * skipped; `children` adds page-specific actions below. Get Directions opens
 * live turn-by-turn navigation on the site (the same as on itinerary stops),
 * from the visitor's GPS position to `name` at the listing's saved point.
 */
export default function FactsCard({ title = "Plan your visit", facts = [], name, latitude, longitude, website, children }) {
  // Directions go to this listing's own saved coordinates; no button without a valid point.
  const point = toCoordinates(latitude, longitude);
  const [navigating, setNavigating] = useState(false);
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

      {(point || websiteUrl || children) && (
        <div className="mt-5 flex flex-col gap-2.5">
          {point && (
            <button type="button" onClick={() => setNavigating(true)} className="btn-primary w-full">
              <FiNavigation size={16} aria-hidden="true" /> Get Directions
            </button>
          )}
          {websiteUrl && (
            <a href={websiteUrl} target="_blank" rel="noopener noreferrer" className="btn-secondary w-full">
              <FiGlobe size={16} aria-hidden="true" /> Visit Website
            </a>
          )}
          {children}
        </div>
      )}

      {navigating && (
        <Suspense fallback={null}>
          <NavigationView stops={[{ name: name || "this place", point }]} startIndex={0} onClose={() => setNavigating(false)} />
        </Suspense>
      )}
    </div>
  );
}
