// frontend/src/components/detail/AmenityGrid.jsx
import { FiCheck } from "react-icons/fi";

/** Check-marked grid of amenities/features (plain strings), or a hint when there are none. */
export default function AmenityGrid({ items = [], emptyTitle, emptyText }) {
  if (items.length === 0) {
    return emptyTitle ? (
      <div className="rounded-xl border border-dashed border-gray-300 bg-gray-50 px-5 py-8 text-center">
        <p className="font-medium text-gray-700">{emptyTitle}</p>
        {emptyText && <p className="mt-1.5 text-sm text-gray-500">{emptyText}</p>}
      </div>
    ) : null;
  }

  return (
    <ul className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3">
      {items.map((item, index) => (
        <li key={`${item}-${index}`} className="flex items-center gap-3 text-sm text-gray-700">
          <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-secondary-50 text-secondary-600">
            <FiCheck size={14} aria-hidden="true" />
          </span>
          {item}
        </li>
      ))}
    </ul>
  );
}
