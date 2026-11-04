// frontend/src/components/common/EmptyState.jsx
import { FiCompass } from "react-icons/fi";

/** Friendly "nothing here" panel with an optional icon, hint and action. */
export default function EmptyState({ icon: Icon = FiCompass, title, children, action, className = "" }) {
  return (
    <div className={`rounded-2xl border border-dashed border-gray-300 bg-white/60 px-6 py-12 text-center ${className}`}>
      <span className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-primary-50 text-primary-600">
        <Icon size={22} aria-hidden="true" />
      </span>
      <p className="font-heading text-lg font-semibold text-gray-900">{title}</p>
      {children && <div className="mx-auto mt-2 max-w-md text-sm text-gray-600">{children}</div>}
      {action && <div className="mt-5">{action}</div>}
    </div>
  );
}
