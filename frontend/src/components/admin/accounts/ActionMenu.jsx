// frontend/src/components/admin/accounts/ActionMenu.jsx
import { useEffect, useRef, useState } from 'react'
import { FiChevronDown } from 'react-icons/fi'

/**
 * "Manage ▾" dropdown. The menu is positioned on screen (fixed), so a table's
 * horizontal scroll area can't clip it. Items: { label, icon, onClick, tone, divider }.
 */
export default function ActionMenu({ items, label = 'Manage' }) {
  const [open, setOpen] = useState(false)
  const [position, setPosition] = useState({ top: 0, right: 0 })
  const buttonRef = useRef(null)

  const toggle = () => {
    if (!open && buttonRef.current) {
      const rect = buttonRef.current.getBoundingClientRect()
      const menuHeight = items.length * 40 + 16
      const below = rect.bottom + 6
      setPosition({
        top: below + menuHeight > window.innerHeight ? Math.max(8, rect.top - menuHeight - 6) : below,
        right: Math.max(8, window.innerWidth - rect.right),
      })
    }
    setOpen((value) => !value)
  }

  useEffect(() => {
    if (!open) return
    const close = () => setOpen(false)
    const onKey = (event) => event.key === 'Escape' && close()
    window.addEventListener('scroll', close, true)
    window.addEventListener('resize', close)
    window.addEventListener('keydown', onKey)
    return () => {
      window.removeEventListener('scroll', close, true)
      window.removeEventListener('resize', close)
      window.removeEventListener('keydown', onKey)
    }
  }, [open])

  return (
    <>
      <button
        ref={buttonRef}
        type="button"
        onClick={toggle}
        aria-haspopup="menu"
        aria-expanded={open}
        className="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 hover:border-primary-300 hover:text-primary-700"
      >
        {label} <FiChevronDown size={14} aria-hidden="true" />
      </button>
      {open && (
        <>
          <button type="button" className="fixed inset-0 z-95 cursor-default" onClick={() => setOpen(false)} aria-label="Close menu" />
          <div
            role="menu"
            className="fixed z-95 w-60 rounded-xl border border-gray-200 bg-white py-1.5 shadow-(--shadow-card-hover)"
            style={{ top: position.top, right: position.right }}
          >
            {items.map((item, index) =>
              item.divider ? (
                <div key={`divider-${index}`} className="my-1 border-t border-gray-100" role="separator" />
              ) : (
                <button
                  key={item.label}
                  type="button"
                  role="menuitem"
                  onClick={() => { setOpen(false); item.onClick() }}
                  className={`flex w-full items-center gap-2.5 px-4 py-2 text-left text-sm ${
                    item.tone === 'danger' ? 'text-red-700 hover:bg-red-50' : item.tone === 'warn' ? 'text-amber-800 hover:bg-amber-50' : item.tone === 'good' ? 'text-green-700 hover:bg-green-50' : 'text-gray-700 hover:bg-gray-50'
                  }`}
                >
                  {item.icon && <item.icon size={15} className="shrink-0" aria-hidden="true" />}
                  {item.label}
                </button>
              )
            )}
          </div>
        </>
      )}
    </>
  )
}
