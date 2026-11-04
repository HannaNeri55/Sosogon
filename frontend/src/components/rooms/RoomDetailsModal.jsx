// frontend/src/components/rooms/RoomDetailsModal.jsx
import { useEffect, useState } from "react";
import { FiCheck, FiX } from "react-icons/fi";
import { getImageUrl } from "../../utils/helpers";
import BackButton from "../layout/BackButton";
import RoomStatusBadge from "./RoomStatusBadge";

/**
 * Full details of one accommodation room (photos, type, status, price,
 * description, features), opened from its card. Back, the X, Escape or a
 * click outside the panel return to the room list.
 */
export default function RoomDetailsModal({ room, onClose }) {
  const [active, setActive] = useState(0);
  const images = room.images?.length ? room.images : [null];
  const features = Array.isArray(room.room_features) ? room.room_features : [];
  const hasPrice = room.price !== null && room.price !== undefined && room.price !== "";

  useEffect(() => {
    const onKey = (event) => event.key === "Escape" && onClose();
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    window.addEventListener("keydown", onKey);
    return () => {
      document.body.style.overflow = previousOverflow;
      window.removeEventListener("keydown", onKey);
    };
  }, [onClose]);

  return (
    <div
      className="fixed inset-0 z-100 bg-black/60 flex items-end sm:items-center justify-center sm:p-6"
      onClick={onClose}
      role="dialog"
      aria-modal="true"
      aria-label={`${room.room_number} details`}
    >
      <div
        className="bg-white w-full sm:max-w-3xl max-h-[92vh] overflow-y-auto rounded-t-2xl sm:rounded-2xl shadow-xl"
        onClick={(event) => event.stopPropagation()}
      >
        <div className="sticky top-0 z-10 flex items-center justify-between gap-3 bg-white/95 backdrop-blur border-b border-gray-100 px-4 sm:px-6 py-3">
          <BackButton onClick={onClose} label="Back to Rooms" />
          <button
            type="button"
            onClick={onClose}
            className="p-2 rounded-full text-gray-500 hover:bg-gray-100"
            aria-label="Close room details"
          >
            <FiX size={20} />
          </button>
        </div>

        <div className="p-4 sm:p-6 space-y-6">
          <div>
            <div className="relative aspect-video rounded-xl overflow-hidden bg-gray-100">
              <img
                src={getImageUrl(images[active])}
                alt={`${room.room_number} photo ${active + 1}`}
                className="w-full h-full object-cover"
              />
              <RoomStatusBadge status={room.occupancy_status} className="absolute top-3 right-3" />
            </div>
            {images.length > 1 && (
              <div className="mt-3 flex gap-2 overflow-x-auto pb-1">
                {images.map((image, index) => (
                  <button
                    key={image?.id ?? index}
                    type="button"
                    onClick={() => setActive(index)}
                    className={`shrink-0 w-20 h-14 rounded-lg overflow-hidden border-2 ${
                      index === active ? "border-primary-500" : "border-transparent opacity-70 hover:opacity-100"
                    }`}
                    aria-label={`Show photo ${index + 1}`}
                  >
                    <img src={getImageUrl(image)} alt="" className="w-full h-full object-cover" />
                  </button>
                ))}
              </div>
            )}
          </div>

          <div>
            <h3 className="text-2xl font-heading font-semibold text-gray-900">{room.room_number}</h3>
            <div className="mt-2 flex flex-wrap items-center gap-2 text-sm">
              {room.category && (
                <span className="px-2.5 py-1 rounded-full bg-primary-50 text-primary-700 text-xs font-medium capitalize">
                  {room.category}
                </span>
              )}
              <RoomStatusBadge status={room.occupancy_status} />
              {hasPrice && (
                <span className="font-semibold text-gray-900">₱{Number(room.price).toLocaleString()}</span>
              )}
            </div>
          </div>

          <div>
            <h4 className="text-sm font-semibold text-gray-800 mb-2">About this room</h4>
            <p className="text-gray-600 leading-relaxed whitespace-pre-line">
              {room.description || "No room description provided yet."}
            </p>
          </div>

          <div className="border-t border-gray-100 pt-5">
            <h4 className="text-sm font-semibold text-gray-800 mb-3">Room Features &amp; Amenities</h4>
            {features.length > 0 ? (
              <div className="flex flex-wrap gap-2">
                {features.map((feature, index) => (
                  <span
                    key={`${feature}-${index}`}
                    className="inline-flex items-center gap-1.5 rounded-lg bg-primary-50 border border-primary-100 px-2.5 py-1.5 text-xs font-medium text-primary-800"
                  >
                    <FiCheck size={13} className="text-primary-600 shrink-0" />
                    {feature}
                  </span>
                ))}
              </div>
            ) : (
              <p className="text-sm text-gray-500">No room features listed yet.</p>
            )}
          </div>

          <p className="text-xs text-gray-500 rounded-lg bg-gray-50 border border-gray-100 p-3">
            Room occupancy is updated manually by the property owner. Contact the property to confirm availability.
          </p>
        </div>
      </div>
    </div>
  );
}
