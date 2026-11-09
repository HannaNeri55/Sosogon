import { useEffect, useMemo, useState } from 'react'
import { FiCheck, FiEdit2, FiPlus, FiX } from 'react-icons/fi'
import toast from 'react-hot-toast'
import { getAmenitySuggestions } from '../../api/accommodations'

const same = (a, b) => a.trim().toLowerCase() === b.trim().toLowerCase()

/**
 * Amenities for a property or one accommodation option: checkboxes for common ones, and the
 * owner's own wording for anything else. What's selected belongs to this record only.
 * scope: 'property' | 'unit'. value: selected names. onChange: receives the new list.
 */
export default function AmenityPicker({ scope, value, onChange, disabled = false, hint }) {
  const [suggestions, setSuggestions] = useState([])
  const [draft, setDraft] = useState('')
  const [editing, setEditing] = useState(null) // { name, text }

  useEffect(() => {
    let active = true
    getAmenitySuggestions(scope).then((names) => active && setSuggestions(names)).catch(() => {})
    return () => { active = false }
  }, [scope])

  // Names already chosen count as custom only once the suggestions are known not to include them.
  const custom = useMemo(() => value.filter((name) => !suggestions.some((s) => same(s, name))), [value, suggestions])
  const isChosen = (name) => value.some((v) => same(v, name))

  const toggle = (name) => onChange(isChosen(name) ? value.filter((v) => !same(v, name)) : [...value, name])

  const clean = (text) => text.replace(/\s+/g, ' ').trim()

  const addCustom = () => {
    const name = clean(draft)
    if (!name) return
    if (isChosen(name)) {
      toast.error(`"${name}" is already on the list.`)
      return
    }
    onChange([...value, suggestions.find((s) => same(s, name)) || name])
    setDraft('')
  }

  const saveEdit = () => {
    const name = clean(editing.text)
    if (!name) return
    if (!same(name, editing.name) && isChosen(name)) {
      toast.error(`"${name}" is already on the list.`)
      return
    }
    onChange(value.map((v) => (v === editing.name ? name : v)))
    setEditing(null)
  }

  return (
    <div>
      {hint && <p className="text-xs text-gray-500 mb-3">{hint}</p>}

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
        {suggestions.map((name) => {
          const selected = isChosen(name)
          return (
            <label
              key={name}
              className={`flex items-center gap-3 rounded-lg border px-3 py-2.5 transition-colors ${
                disabled ? 'cursor-not-allowed opacity-70' : 'cursor-pointer'
              } ${selected ? 'border-primary-300 bg-primary-50' : 'border-gray-200 bg-white hover:bg-gray-50'}`}
            >
              <input type="checkbox" checked={selected} disabled={disabled} onChange={() => toggle(name)} className="w-4 h-4 accent-green-700 shrink-0" />
              <span className="text-sm text-gray-700 flex-1">{name}</span>
              {selected && <FiCheck size={15} className="text-primary-600 shrink-0" aria-hidden="true" />}
            </label>
          )
        })}
      </div>

      {custom.length > 0 && (
        <div className="mt-4">
          <p className="text-xs font-semibold text-gray-600 mb-2">Your own entries</p>
          <ul className="flex flex-wrap gap-2">
            {custom.map((name) => (
              <li key={name}>
                {editing?.name === name ? (
                  <span className="inline-flex items-center gap-1">
                    <input
                      className="input-field py-1 text-sm"
                      aria-label={`Edit ${name}`}
                      maxLength={100}
                      value={editing.text}
                      autoFocus
                      onChange={(e) => setEditing({ ...editing, text: e.target.value })}
                      onKeyDown={(e) => {
                        if (e.key === 'Enter') { e.preventDefault(); saveEdit() }
                        if (e.key === 'Escape') setEditing(null)
                      }}
                    />
                    <button type="button" onClick={saveEdit} className="p-2 text-primary-600" aria-label="Save amenity"><FiCheck size={15} /></button>
                  </span>
                ) : (
                  <span className="inline-flex items-center gap-1 rounded-full bg-secondary-50 pl-3 pr-1 py-1 text-sm text-secondary-800">
                    {name}
                    {!disabled && (
                      <>
                        <button type="button" onClick={() => setEditing({ name, text: name })} className="p-2 hover:text-primary-700" aria-label={`Edit ${name}`}><FiEdit2 size={12} /></button>
                        <button type="button" onClick={() => toggle(name)} className="p-2 hover:text-red-600" aria-label={`Remove ${name}`}><FiX size={13} /></button>
                      </>
                    )}
                  </span>
                )}
              </li>
            ))}
          </ul>
        </div>
      )}

      {!disabled && (
        <div className="mt-4">
          <label htmlFor={`custom-amenity-${scope}`} className="block text-xs font-semibold text-gray-600 mb-2">Add Custom Amenity</label>
          <div className="flex gap-2">
            <input
              id={`custom-amenity-${scope}`}
              className="input-field"
              placeholder="Something not listed above"
              maxLength={100}
              value={draft}
              onChange={(e) => setDraft(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter') { e.preventDefault(); addCustom() }
              }}
            />
            <button type="button" onClick={addCustom} disabled={!clean(draft)} className="inline-flex items-center gap-1.5 shrink-0 rounded-lg border border-primary-200 px-3 text-sm font-medium text-primary-700 hover:bg-primary-50 disabled:opacity-50">
              <FiPlus size={14} aria-hidden="true" /> Add
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
