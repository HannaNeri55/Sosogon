// frontend/src/components/rooms/RoomDetailsModal.jsx
import { useEffect, useState } from "react";
import { FiCheck, FiX } from "react-icons/fi";
import BackButton from "../layout/BackButton";
import { optionFacts, optionFeatures, optionImage, optionName, optionPrice, unitTypeLabel } from "../../utils/accommodationOptions";

/**
 * Full details of one accommodation option (photos, type, facts, rates, description, its own
 * amenities), opened from its card. Back, the X, Escape or a click outside the panel return to
 * the list.
 */
export default function RoomDetailsModal({ room, onClose }) {
  const [active, setActive] = useState(0);
  const images = room.images?.length ? room.images : [null];
  const name = optionName(room);
  const features = optionFeatures(room);
  const facts = optionFacts(room);
  const price = optionPrice(room);

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
      aria-label={`${name} details`}
    >
      <div
        className="bg-white w-full sm:max-w-3xl max-h-[92vh] overflow-y-auto rounded-t-2xl sm:rounded-2xl shadow-xl"
        onClick={(event) => event.stopPropagation()}
      >
        <div className="sticky top-0 z-10 flex items-center justify-between gap-3 bg-white/95 backdrop-blur border-b border-gray-100 px-4 sm:px-6 py-3">
          <BackButton onClick={onClose} label="Back to options" />
          <button
            type="button"
            onClick={onClose}
            className="p-2 rounded-full text-gray-500 hover:bg-gray-100"
            aria-label="Close details"
          >
            <FiX size={20} />
          </button>
        </div>

        <div className="p-4 sm:p-6 space-y-6">
          <div>
            <div className="aspect-video rounded-xl overflow-hidden bg-gray-100">
              <img
                src={optionImage(images[active])}
                alt={`${name} photo ${active + 1}`}
                className="w-full h-full object-cover"
              />
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
                    <img src={optionImage(image)} alt="" className="w-full h-full object-cover" />
                  </button>
                ))}
              </div>
            )}
          </div>

          <div>
            <h3 className="text-2xl font-heading font-semibold text-gray-900">{name}</h3>
            <span className="mt-2 inline-block px-2.5 py-1 rounded-full bg-primary-50 text-primary-700 text-xs font-medium">
              {room.category || unitTypeLabel(room.unit_type)}
            </span>
            {facts.length > 0 && <p className="mt-2 text-sm text-gray-600">{facts.join(" · ")}</p>}
          </div>

          <div className="rounded-lg bg-gray-50 border border-gray-100 p-3">
            {price.amount && <p className="font-semibold text-gray-900">{price.amount}</p>}
            <p className="text-sm text-gray-600">{price.note}</p>
            {room.breakfast_included && <p className="mt-1 text-sm text-gray-600">Breakfast included, as listed.</p>}
          </div>

          <div>
            <h4 className="text-sm font-semibold text-gray-800 mb-2">About</h4>
            <p className="text-gray-600 leading-relaxed whitespace-pre-line">
              {room.description || "No description provided yet."}
            </p>
            {room.notes && <p className="mt-2 text-sm text-gray-600 whitespace-pre-line">{room.notes}</p>}
          </div>

          <div className="border-t border-gray-100 pt-5">
            <h4 className="text-sm font-semibold text-gray-800 mb-3">Amenities in this {unitTypeLabel(room.unit_type).toLowerCase()}</h4>
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
              <p className="text-sm text-gray-500">None listed yet. Ask the property.</p>
            )}
            <p className="mt-3 text-xs text-gray-500">Facilities shared by the whole property are listed on its page.</p>
          </div>
        </div>
      </div>
    </div>
  );
}
