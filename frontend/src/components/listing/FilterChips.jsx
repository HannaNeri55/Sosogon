// frontend/src/components/listing/FilterChips.jsx

/**
 * One labelled row of pill filters ("All" + options). On phones it is a single
 * swipeable row instead of many wrapped lines.
 */
export default function FilterChips({ label, options, value, onChange, allLabel = "All" }) {
  const chip = (active) =>
    `shrink-0 whitespace-nowrap px-3.5 py-2 sm:py-1.5 rounded-full text-sm font-medium border transition-colors ${
      active
        ? "bg-primary-500 text-white border-primary-500 shadow-sm"
        : "bg-white text-gray-700 border-gray-200 hover:border-primary-300 hover:text-primary-700"
    }`;

  return (
    <div role="group" aria-label={label}>
      {label && <p className="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">{label}</p>}
      <div className="flex items-center gap-2 overflow-x-auto -mx-4 px-4 pb-1 sm:flex-wrap sm:overflow-visible sm:mx-0 sm:px-0 sm:pb-0">
        <button type="button" onClick={() => onChange("")} className={chip(!value)} aria-pressed={!value}>
          {allLabel}
        </button>
        {options.map((option) => {
          const active = String(value) === String(option.value);
          return (
            <button
              key={option.value}
              type="button"
              onClick={() => onChange(option.value)}
              className={chip(active)}
              aria-pressed={active}
            >
              {option.label}
            </button>
          );
        })}
      </div>
    </div>
  );
}
