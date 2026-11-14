import { useMemo, useState } from 'react'
import { FiMapPin, FiSearch } from 'react-icons/fi'

/**
 * A municipality-by-municipality admin workspace (Manage Destinations, Manage Events): every
 * area (a municipality, or "Province-wide") as a selectable chip with its record count, with a
 * search to narrow them, above the selected area's own panel (`children`), which keeps the full
 * width for its records. Phones get a dropdown instead of the chips. With a single area (a
 * municipal admin's own municipality) the selector is left out.
 *
 * `areas`: [{ key, name, count }], in display order.
 */
export default function MunicipalityWorkspace({ areas, selectedKey, onSelect, noun, children }) {
  const [query, setQuery] = useState('')
  const shown = useMemo(() => {
    const q = query.trim().toLowerCase()
    return q ? areas.filter((area) => area.name.toLowerCase().includes(q)) : areas
  }, [areas, query])

  if (areas.length <= 1) return <section className="min-w-0">{children}</section>

  const plural = (count) => `${count} ${noun}${count === 1 ? '' : 's'}`
  const selectedName = areas.find((area) => area.key === selectedKey)?.name

  return (
    <div className="space-y-6">
      <nav aria-label="Municipalities" className="min-w-0">
        {/* Phones: a dropdown keeps the records right below it. */}
        <label className="block sm:hidden">
          <span className="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-gray-500">Municipality</span>
          <select className="input-field" value={selectedKey ?? ''} onChange={(e) => onSelect(e.target.value)}>
            {areas.map((area) => (
              <option key={area.key} value={area.key}>{area.name} ({plural(area.count)})</option>
            ))}
          </select>
        </label>

        <div className="hidden rounded-2xl border border-gray-200/70 bg-white p-4 shadow-(--shadow-card) sm:block">
          <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
            <p className="text-xs font-semibold uppercase tracking-wider text-gray-500">
              Municipalities <span className="font-medium normal-case tracking-normal text-gray-400">· choose one to manage its {noun}s</span>
            </p>
            <div className="relative w-full sm:w-60">
              <FiSearch className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={14} aria-hidden="true" />
              <input
                type="search"
                className="input-field py-2 pl-8 text-sm"
                placeholder="Find a municipality"
                aria-label="Find a municipality"
                value={query}
                onChange={(e) => setQuery(e.target.value)}
              />
            </div>
          </div>
          <ul className="flex flex-wrap gap-2">
            {shown.map((area) => {
              const selected = area.key === selectedKey
              return (
                <li key={area.key}>
                  <button
                    type="button"
                    onClick={() => onSelect(area.key)}
                    aria-current={selected ? 'true' : undefined}
                    className={`inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm transition-colors ${
                      selected
                        ? 'border-primary-600 bg-primary-600 font-semibold text-white'
                        : 'border-gray-200 bg-white text-gray-700 hover:border-primary-300 hover:bg-primary-50'
                    }`}
                  >
                    <FiMapPin size={13} className={selected ? 'text-white' : 'text-gray-400'} aria-hidden="true" />
                    {area.name}
                    <span
                      className={`rounded-full px-1.5 text-xs tabular-nums ${selected ? 'bg-white/20 text-white' : 'bg-gray-100 text-gray-600'}`}
                    >
                      {area.count}
                    </span>
                  </button>
                </li>
              )
            })}
            {shown.length === 0 && <li className="py-1.5 text-sm text-gray-500">No municipality matches “{query}”.</li>}
          </ul>
        </div>
      </nav>

      <section aria-label={selectedName} className="min-w-0">
        {children}
      </section>
    </div>
  )
}
