// frontend/src/components/layout/PageHeader.jsx
import BackButton from "./BackButton";

/**
 * Standard top of a page: back button (always first, top-left), optional
 * eyebrow/badge, title, subtitle and right-aligned actions.
 */
export default function PageHeader({ back, eyebrow, title, subtitle, actions, className = "" }) {
  return (
    <header className={`pt-6 sm:pt-8 mb-6 ${className}`}>
      {back && <BackButton {...back} className="mb-4" />}
      <div className="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
        <div className="min-w-0">
          {eyebrow && <div className="mb-3">{eyebrow}</div>}
          <h1 className="text-3xl sm:text-4xl font-heading font-semibold text-gray-900 leading-tight wrap-break-word">
            {title}
          </h1>
          {subtitle && <div className="mt-2 text-gray-600">{subtitle}</div>}
        </div>
        {actions && <div className="flex flex-wrap gap-2 shrink-0">{actions}</div>}
      </div>
    </header>
  );
}
