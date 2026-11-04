// frontend/src/components/common/NearbySection.jsx
import ListingCard from "../cards/ListingCard";

/**
 * A grid of nearby listings. Hidden when empty, unless an emptyMessage is
 * given, in which case the heading stays with that message instead.
 */
export default function NearbySection({ title, items, basePath, intro, typeLabel, emptyMessage }) {
  if (!items?.length && !emptyMessage) return null;

  const hasDistances = items?.some((item) => item.distance_km != null);

  return (
    <section>
      <h2 className="text-2xl font-heading font-bold text-gray-900">{title}</h2>
      {items?.length ? (
        <>
          <p className="text-sm text-gray-500 mt-1 mb-6">
            {intro}
            {hasDistances && " Distances are approximate and measured in a straight line."}
          </p>
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
            {items.map((item) => (
              <ListingCard
                key={item.id}
                item={item}
                basePath={basePath}
                typeLabel={typeLabel(item)}
                distanceKm={item.distance_km}
              />
            ))}
          </div>
        </>
      ) : (
        <p className="mt-3 rounded-xl border border-dashed border-gray-200 bg-white px-4 py-6 text-center text-sm text-gray-500">
          {emptyMessage}
        </p>
      )}
    </section>
  );
}
