// frontend/src/components/layout/PageContainer.jsx

const widths = { narrow: "max-w-4xl", default: "max-w-6xl", wide: "max-w-7xl" };

/** The shared content column: one max width and side gutter for every public page. */
export default function PageContainer({ width = "default", className = "", children }) {
  return <div className={`${widths[width]} mx-auto px-4 sm:px-6 ${className}`}>{children}</div>;
}
