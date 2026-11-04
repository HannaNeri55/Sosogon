// frontend/src/components/rooms/RoomStatusBadge.jsx

/** Occupied (red) / Unoccupied (green) pill for an accommodation room; nothing when unset. */
export default function RoomStatusBadge({ status, className = "" }) {
  if (!status) return null;
  const occupied = status === "occupied";

  return (
    <span
      className={`text-xs font-medium px-2 py-1 rounded-full capitalize ${
        occupied ? "bg-red-100 text-red-800" : "bg-green-100 text-green-800"
      } ${className}`}
    >
      {status}
    </span>
  );
}
