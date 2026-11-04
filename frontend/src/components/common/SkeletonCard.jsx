// frontend/src/components/common/SkeletonCard.jsx

/** Grey placeholder shaped like a listing card, shown while results load. */
export function SkeletonCard() {
  return (
    <div className="card" aria-hidden="true">
      <div className="skeleton aspect-4/3 rounded-none" />
      <div className="p-4 space-y-3">
        <div className="skeleton h-3 w-1/3" />
        <div className="skeleton h-5 w-3/4" />
        <div className="skeleton h-3 w-1/2" />
      </div>
    </div>
  );
}

/** A grid of skeleton cards matching the listing grid. */
export default function SkeletonGrid({ count = 6, className = "grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6" }) {
  return (
    <div className={className} role="status" aria-label="Loading">
      {Array.from({ length: count }, (_, i) => (
        <SkeletonCard key={i} />
      ))}
    </div>
  );
}
