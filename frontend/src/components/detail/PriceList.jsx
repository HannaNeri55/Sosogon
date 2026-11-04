// frontend/src/components/detail/PriceList.jsx

const peso = (amount) =>
  Number(amount) === 0
    ? "Free"
    : `₱${Number(amount).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

/** Label / description / amount rows, e.g. entrance fees or cottage rates. */
export default function PriceList({ title, rows }) {
  if (!rows?.length) return null;

  return (
    <div>
      {title && <h3 className="text-base font-semibold text-gray-900 mb-3">{title}</h3>}
      <div className="rounded-xl border border-gray-200 divide-y divide-gray-100 overflow-hidden">
        {rows.map((row) => (
          <div key={row.id} className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1 sm:gap-4 px-4 py-3">
            <div>
              <p className="font-medium text-gray-800">{row.label}</p>
              {row.description && <p className="text-sm text-gray-500 mt-0.5">{row.description}</p>}
            </div>
            <span className="font-semibold text-primary-700 shrink-0">{peso(row.amount)}</span>
          </div>
        ))}
      </div>
    </div>
  );
}
