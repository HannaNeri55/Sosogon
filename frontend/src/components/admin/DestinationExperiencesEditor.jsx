import { FiCheck, FiCheckCircle, FiInfo } from 'react-icons/fi'
import FieldError from '../common/FieldError'
import { formatShortDate } from '../../utils/helpers'

/**
 * The destination form's Experiences section: tags from the controlled vocabulary, grouped, each
 * with its definition, and the review mark. Choosing tags never marks the destination reviewed;
 * Reviewed needs at least one tag. The itinerary planner doesn't use these yet.
 *
 * `vocabulary` is [{ slug, name, group, description }]; `review` is the saved review
 * ({ at, by }) shown while the mark stays on.
 */
export default function DestinationExperiencesEditor({ vocabulary, selected, reviewed, review, onChange, form }) {
  const groups = vocabulary.reduce((acc, tag) => {
    ;(acc[tag.group] ||= []).push(tag)
    return acc
  }, {})

  const toggle = (slug) => {
    const next = selected.includes(slug) ? selected.filter((s) => s !== slug) : [...selected, slug]
    // A destination can't stay reviewed without tags: clearing the last one clears the mark too.
    onChange({ experience_tags: next, experiences_reviewed: reviewed && next.length > 0 })
    form?.clear('experiences_reviewed')
  }

  return (
    <fieldset className="space-y-4 rounded-xl border border-gray-200 p-4">
      <legend className="px-1 text-sm font-semibold text-gray-800">Experiences</legend>
      <p className="-mt-1 text-xs text-gray-500">
        What visitors can actually do here. Choose every one that applies, from the listing's verified details only. The primary category above stays the same.
      </p>

      {vocabulary.length === 0 ? (
        <p className="text-sm text-gray-500">Loading experiences…</p>
      ) : (
        Object.entries(groups).map(([group, tags]) => (
          <div key={group}>
            <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500">{group}</p>
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
              {tags.map((tag) => {
                const on = selected.includes(tag.slug)
                return (
                  <label
                    key={tag.slug}
                    className={`flex cursor-pointer items-start gap-2.5 rounded-lg border p-2.5 transition-colors ${
                      on ? 'border-primary-400 bg-primary-50' : 'border-gray-200 bg-white hover:bg-gray-50'
                    }`}
                  >
                    <input
                      type="checkbox"
                      name="experience_tags[]"
                      value={tag.slug}
                      checked={on}
                      onChange={() => toggle(tag.slug)}
                      className="mt-0.5"
                    />
                    <span className="min-w-0">
                      <span className={`block text-sm font-medium ${on ? 'text-primary-800' : 'text-gray-800'}`}>{tag.name}</span>
                      <span className="block text-xs leading-snug text-gray-500">{tag.description}</span>
                    </span>
                  </label>
                )
              })}
            </div>
          </div>
        ))
      )}
      <FieldError form={form} name="experience_tags" />

      <div className="rounded-lg bg-gray-50 p-3">
        <label className={`flex items-start gap-2.5 text-sm ${selected.length ? 'text-gray-800' : 'text-gray-400'}`}>
          <input
            type="checkbox"
            name="experiences_reviewed"
            checked={reviewed}
            disabled={selected.length === 0}
            onChange={(e) => {
              onChange({ experiences_reviewed: e.target.checked })
              form?.clear('experiences_reviewed')
            }}
            className="mt-0.5"
          />
          <span>
            <span className="block font-medium">Reviewed: these experiences are confirmed</span>
            <span className="block text-xs text-gray-500">
              {selected.length === 0
                ? 'Choose at least one experience first.'
                : 'Leave unticked while the experiences still need checking. You can mark it Not reviewed again later.'}
            </span>
          </span>
        </label>
        <p className="mt-2 flex items-center gap-1.5 text-xs text-gray-600">
          {reviewed && review?.at ? (
            <>
              <FiCheckCircle size={13} className="text-green-600" aria-hidden="true" />
              Reviewed by {review.by || 'an admin'} on {formatShortDate(review.at)}
            </>
          ) : reviewed ? (
            <>
              <FiCheck size={13} className="text-green-600" aria-hidden="true" />
              Will be marked reviewed by you when you save.
            </>
          ) : (
            <>
              <FiInfo size={13} className="text-gray-400" aria-hidden="true" />
              Not reviewed{selected.length === 0 ? ' · no experiences chosen' : ''}
            </>
          )}
        </p>
        <FieldError form={form} name="experiences_reviewed" />
      </div>
    </fieldset>
  )
}
