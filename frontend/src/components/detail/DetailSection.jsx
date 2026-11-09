// frontend/src/components/detail/DetailSection.jsx

/** White content panel with the standard section heading, used on every detail page. */
export default function DetailSection({ title, subtitle, action, children, className = "" }) {
  return (
    <section className={`bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) p-6 sm:p-8 ${className}`}>
      {(title || action) && (
        <div className="flex flex-wrap items-start justify-between gap-3 mb-5">
          <div className="flex items-start gap-3">
            <span className="mt-1.5 h-6 w-1 shrink-0 rounded-full bg-secondary-500" aria-hidden="true" />
            <div>
              <h2 className="text-xl sm:text-2xl font-heading font-bold text-gray-900">{title}</h2>
              {subtitle && <p className="mt-1 text-sm text-gray-500">{subtitle}</p>}
            </div>
          </div>
          {action}
        </div>
      )}
      {children}
    </section>
  );
}
