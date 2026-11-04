// frontend/src/components/rooms/RoomCard.jsx
import { FiChevronRight } from "react-icons/fi";
import { getImageUrl } from "../../utils/helpers";
import RoomStatusBadge from "./RoomStatusBadge";

/** Compact, fully clickable room card: main photo, name, type and occupancy only. */
export default function RoomCard({ room, onOpen }) {
  return (
    <button
      type="button"
      onClick={() => onOpen(room)}
      className="group text-left rounded-xl border border-gray-100 bg-white shadow-sm hover:shadow-md hover:-translate-y-0.5 transition duration-200 overflow-hidden flex flex-col focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
      aria-label={`View details for ${room.room_number}`}
    >
      <div className="relative aspect-4/3 overflow-hidden bg-gray-100">
        <img
          src={getImageUrl(room.images?.[0])}
          alt={room.room_number}
          className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
        />
        <RoomStatusBadge status={room.occupancy_status} className="absolute top-2 right-2" />
      </div>

      <div className="p-4 flex items-center justify-between gap-3">
        <div className="min-w-0">
          <p className="font-semibold text-gray-900 truncate">{room.room_number}</p>
          {room.category && (
            <span className="mt-1.5 inline-block px-2.5 py-1 rounded-full bg-primary-50 text-primary-700 text-xs font-medium capitalize">
              {room.category}
            </span>
          )}
        </div>
        <span className="shrink-0 inline-flex items-center gap-0.5 text-xs font-medium text-primary-600 group-hover:text-primary-700">
          View details <FiChevronRight size={14} />
        </span>
      </div>
    </button>
  );
}
