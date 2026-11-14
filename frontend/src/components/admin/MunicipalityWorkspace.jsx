import { useEffect, useMemo, useRef, useState } from 'react'
import { FiArrowLeft, FiArrowRight, FiGlobe, FiMapPin, FiSearch } from 'react-icons/fi'

/**
 * A municipality-by-municipality admin area (Manage Destinations, Manage Events). With no area
 * selected it shows the overview: one card per area (a municipality, or "Province-wide") with
 * its record count, and a search above the grid. A selected area shows its own workspace
 * (`children`) with a way back to the overview. With a single area (a municipal admin's own
 * municipality) the workspace is shown straight away.
 *
 * `areas`: [{ key, name, count, provinceWide? }], in display order. `selectedKey` null: overview.
 */
export default function MunicipalityWorkspace({ areas, selectedKey, onSelect, onBack, noun, children }) {
  const [query, setQuery] = useState('')
  const topRef = useRef(null)
  const shown = useMemo(() => {
    const q = query.trim().toLowerCase()
    return q ? areas.filter((area) => area.name.toLowerCase().includes(q)) : areas
  }, [areas, query])

  // Switching between the overview and a workspace starts at the top of this area, not wherever
  // the card that was clicked happened to be.
  const previousKey = useRef(selectedKey)
  useEffect(() => {
    if (previousKey.current !== selectedKey && topRef.current && topRef.current.getBoundingClientRect().top < 0) {
      topRef.current.scrollIntoView({ block: 'start' })
    }
    previousKey.current = selectedKey
  }, [selectedKey])

  const plural = (count) => `${noun}${count === 1 ? '' : 's'}`

  if (areas.length <= 1) return <section className="min-w-0">{children}</section>

  if (selectedKey) {
    return (
      <section ref={topRef} aria-label={areas.find((area) => area.key === selectedKey)?.name} className="min-w-0 scroll-mt-24">
        <button
          type="button"
          onClick={onBack}
          className="mb-4 inline-flex items-center gap-2 rounded-lg px-2 py-1.5 -ml-2 text-sm font-semibold text-primary-700 hover:bg-primary-50"
        >
          <FiArrowLeft size={15} aria-hidden="true" /> All municipalities
        </button>
        {children}
      </section>
    )
  }

  const total = areas.reduce((sum, area) => sum + area.count, 0)

  return (
    <section ref={topRef} aria-labelledby="municipality-overview" className="min-w-0 scroll-mt-24">
      <div className="mb-5 flex flex-wrap items-end justify-between gap-4">
        <div>
          <h2 id="municipality-overview" className="text-xl font-heading font-bold text-gray-900">Municipalities</h2>
          <p className="mt-0.5 text-sm text-gray-500">
            {areas.filter((area) => !area.provinceWide).length} municipalities · {total} {plural(total)}. Choose one to manage its {noun}s.
          </p>
        </div>
        <div className="relative w-full sm:w-72">
          <FiSearch className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={16} aria-hidden="true" />
          <input
            type="search"
            className="input-field pl-9"
            placeholder="Search municipalities"
            aria-label="Search municipalities"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
          />
        </div>
      </div>

      {shown.length === 0 ? (
        <p className="rounded-2xl border border-dashed border-gray-300 bg-white/60 px-6 py-10 text-center text-sm text-gray-600">
          No municipality matches “{query}”.
        </p>
      ) : (
        <ul className="grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
          {shown.map((area) => {
            const Icon = area.provinceWide ? FiGlobe : FiMapPin
            return (
              <li key={area.key}>
                <button
                  type="button"
                  onClick={() => onSelect(area.key)}
                  className="group flex h-full w-full flex-col rounded-2xl border border-gray-200/70 bg-white p-5 text-left shadow-(--shadow-card) transition-[border-color,box-shadow,transform] hover:border-primary-300 hover:shadow-(--shadow-card-hover) motion-safe:hover:-translate-y-0.5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500"
                >
                  <span className="flex items-start justify-between gap-3">
                    <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-600">
                      <Icon size={20} aria-hidden="true" />
                    </span>
                    <FiArrowRight size={18} className="mt-1 text-gray-300 transition-colors group-hover:text-primary-600" aria-hidden="true" />
                  </span>
                  <span className="mt-4 block font-heading text-lg font-semibold leading-snug text-gray-900">{area.name}</span>
                  <span className="mb-4 mt-1 block text-sm text-gray-500">
                    {area.count > 0 ? (
                      <>
                        <span className="font-semibold tabular-nums text-gray-900">{area.count}</span> {plural(area.count)}
                      </>
                    ) : (
                      `No ${noun}s yet`
                    )}
                  </span>
                  <span className="mt-auto flex items-center gap-1.5 border-t border-gray-100 pt-3 text-sm font-semibold text-primary-700">
                    View {noun}s
                    <FiArrowRight size={14} className="transition-transform motion-safe:group-hover:translate-x-0.5" aria-hidden="true" />
                  </span>
                </button>
              </li>
            )
          })}
        </ul>
      )}
    </section>
  )
}
