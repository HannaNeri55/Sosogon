// frontend/src/components/detail/DetailLayout.jsx

/**
 * Detail page body: main content plus a side card. On desktop the side card
 * sits on the right and stays in view while scrolling; on phones it comes
 * first, right under the photos, so the key facts are seen immediately.
 */
export default function DetailLayout({ aside, children }) {
  return (
    <div className="max-w-6xl mx-auto px-4 sm:px-6 pb-16">
      <div className="grid grid-cols-1 gap-8 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start">
        <aside className="lg:order-last lg:sticky lg:top-24">{aside}</aside>
        <div className="min-w-0 space-y-8">{children}</div>
      </div>
    </div>
  );
}
