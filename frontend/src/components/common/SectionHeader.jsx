// frontend/src/components/common/SectionHeader.jsx
import { Link } from "react-router-dom";
import { FiArrowRight } from "react-icons/fi";

/** Eyebrow + serif title (+ optional intro and "View all" link) used by public page sections. */
export default function SectionHeader({ eyebrow, title, intro, linkTo, linkLabel, tone = "light", align = "left", className = "" }) {
  const dark = tone === "dark";
  const centered = align === "center";

  return (
    <div
      className={`flex flex-wrap items-end gap-x-6 gap-y-3 mb-8 sm:mb-10 ${
        centered ? "justify-center text-center" : "justify-between"
      } ${className}`}
    >
      <div className={centered ? "max-w-2xl" : "max-w-2xl"}>
        {eyebrow && (
          <p className={`text-xs font-semibold uppercase tracking-[0.18em] mb-3 ${dark ? "text-accent-400" : "text-secondary-500"}`}>
            {eyebrow}
          </p>
        )}
        <h2 className={`text-3xl sm:text-4xl font-heading font-semibold leading-tight ${dark ? "text-white" : "text-gray-900"}`}>
          {title}
        </h2>
        {intro && <p className={`mt-3 leading-relaxed ${dark ? "text-white/80" : "text-gray-600"}`}>{intro}</p>}
      </div>
      {linkTo && (
        <Link
          to={linkTo}
          className={`group inline-flex items-center gap-1.5 text-sm font-semibold ${
            dark ? "text-white hover:text-accent-400" : "text-primary-600 hover:text-primary-700"
          }`}
        >
          {linkLabel}
          <FiArrowRight size={16} className="transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
        </Link>
      )}
    </div>
  );
}
