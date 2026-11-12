// frontend/src/components/listing/ListingLayout.jsx
import { useState } from "react";
import { FiFilter, FiSearch, FiX } from "react-icons/fi";
import LocationFilterSidebar from "../common/LocationFilterSidebar";
import Pagination from "../common/Pagination";
import EmptyState from "../common/EmptyState";
import SkeletonGrid from "../common/SkeletonCard";
import LoadError from "../common/LoadError";

/**
 * Shared body of the public listing pages (destinations, accommodations, food,
 * shops): page heading, location sidebar, extra filters, result count, results
 * grid with skeleton/empty states, and pagination. Data and filter state stay
 * in the page; this only lays them out.
 */
export default function ListingLayout({
  eyebrow,
  title,
  intro,
  filters,
  updateFilters,
  onClearAll,
  hasActiveFilters,
  extraFilters,
  loading,
  items,
  noun,
  pagination,
  onPageChange,
  renderItem,
  loadFailed = false,
  onRetry,
}) {
  const [showFilters, setShowFilters] = useState(false);
  const count = pagination?.total ?? items.length;
  const refreshing = loading && items.length > 0;

  return (
    <div className="max-w-7xl mx-auto px-4 sm:px-6 py-10 lg:py-14">
      <header className="mb-8 max-w-3xl">
        {eyebrow && <p className="text-xs font-semibold uppercase tracking-[0.18em] text-secondary-500 mb-2">{eyebrow}</p>}
        <h1 className="text-3xl sm:text-4xl font-heading font-bold text-gray-900">{title}</h1>
        {intro && <p className="mt-3 text-gray-600 leading-relaxed">{intro}</p>}
      </header>

      <button
        type="button"
        onClick={() => setShowFilters((v) => !v)}
        aria-expanded={showFilters}
        className="lg:hidden mb-4 inline-flex items-center gap-2 text-sm font-semibold text-primary-700 bg-white border border-gray-200 rounded-full px-4 py-2 shadow-sm"
      >
        {showFilters ? <FiX size={15} /> : <FiFilter size={15} />}
        {showFilters ? "Hide search & location" : "Search & filter by location"}
      </button>

      <div className="flex flex-col lg:flex-row gap-8">
        <aside
          className={`lg:w-72 shrink-0 h-fit lg:sticky lg:top-24 bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) p-5 ${
            showFilters ? "block" : "hidden"
          } lg:block`}
        >
          <LocationFilterSidebar
            municipalityId={filters.municipality_id}
            onSelectMunicipality={(id) => updateFilters({ municipality_id: id })}
            onClearLocation={() => updateFilters({ municipality_id: "" })}
            search={filters.q}
            onSearchChange={(q) => updateFilters({ q })}
            sort={filters.sort}
            onSortChange={(sort, dir) => updateFilters({ sort, dir })}
          />
        </aside>

        <div className="flex-1 min-w-0">
          {extraFilters && (
            <div className="bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) p-4 sm:p-5 mb-6 space-y-4">
              {extraFilters}
            </div>
          )}

          <div className="flex flex-wrap items-center justify-between gap-3 mb-4 min-h-8">
            <p className="text-sm text-gray-600" aria-live="polite">
              {loading && items.length === 0 ? (
                "Loading…"
              ) : loadFailed ? null : (
                <>
                  <span className="font-semibold text-gray-900">{count}</span> {count === 1 ? noun.one : noun.many} found
                </>
              )}
            </p>
            {hasActiveFilters && (
              <button
                type="button"
                onClick={onClearAll}
                className="inline-flex items-center gap-1.5 text-sm font-medium text-primary-700 hover:text-primary-800"
              >
                <FiX size={14} /> Clear all filters
              </button>
            )}
          </div>

          {loading && items.length === 0 ? (
            <SkeletonGrid className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-6" />
          ) : loadFailed ? (
            <LoadError what={noun.many} onRetry={onRetry} />
          ) : items.length === 0 ? (
            <EmptyState
              icon={FiSearch}
              title={`No ${noun.many} found`}
              action={
                hasActiveFilters && (
                  <button type="button" onClick={onClearAll} className="btn-secondary">
                    Clear all filters
                  </button>
                )
              }
            >
              Try a different search or town, or clear the filters to see everything.
            </EmptyState>
          ) : (
            <>
              <div
                className={`grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-6 transition-opacity ${refreshing ? "opacity-60" : ""}`}
                aria-busy={refreshing}
              >
                {items.map(renderItem)}
              </div>
              <Pagination
                currentPage={pagination.current_page}
                lastPage={pagination.last_page}
                onPageChange={onPageChange}
              />
            </>
          )}
        </div>
      </div>
    </div>
  );
}
