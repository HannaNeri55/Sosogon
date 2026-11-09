// frontend/src/components/rooms/RoomCard.jsx
import { FiChevronRight } from "react-icons/fi";
import { optionFacts, optionFeatures, optionImage, optionName, optionPrice, unitTypeLabel } from "../../utils/accommodationOptions";

/** One accommodation option as a clickable card: photo, name, key facts, a few amenities and its rates. */
export default function RoomCard({ room, onOpen }) {
  const name = optionName(room);
  const price = optionPrice(room);
  const facts = optionFacts(room).slice(0, 3);
  const features = optionFeatures(room);

  return (
    <button
      type="button"
      onClick={() => onOpen(room)}
      className="group text-left rounded-xl border border-gray-100 bg-white shadow-sm hover:shadow-md hover:-translate-y-0.5 transition duration-200 overflow-hidden flex flex-col focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
      aria-label={`View details for ${name}`}
    >
      <div className="aspect-4/3 w-full overflow-hidden bg-gray-100">
        <img
          src={optionImage(room.images?.[0])}
          alt={name}
          loading="lazy"
          className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
        />
      </div>

      <div className="p-4 flex flex-1 flex-col gap-2 w-full">
        <div>
          <p className="font-semibold text-gray-900">{name}</p>
          <span className="mt-1 inline-block px-2.5 py-0.5 rounded-full bg-primary-50 text-primary-700 text-xs font-medium">
            {room.category || unitTypeLabel(room.unit_type)}
          </span>
        </div>
        {room.description && <p className="text-sm text-gray-600 line-clamp-2">{room.description}</p>}
        {facts.length > 0 && <p className="text-xs text-gray-500">{facts.join(" · ")}</p>}
        {features.length > 0 && (
          <p className="text-xs text-gray-500">
            {features.slice(0, 4).join(" · ")}
            {features.length > 4 && ` · +${features.length - 4} more`}
          </p>
        )}
        <div className="mt-auto pt-2 flex items-end justify-between gap-3">
          <p className={price.amount ? "text-sm font-semibold text-gray-900" : "text-xs text-gray-500"}>
            {price.amount || price.note}
          </p>
          <span className="shrink-0 inline-flex items-center gap-0.5 text-xs font-medium text-primary-600 group-hover:text-primary-700">
            View details <FiChevronRight size={14} aria-hidden="true" />
          </span>
        </div>
      </div>
    </button>
  );
}
