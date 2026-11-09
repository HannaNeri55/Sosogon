// frontend/src/components/common/OwnerReply.jsx
import { useState } from 'react'
import { FiCornerDownRight, FiEdit2, FiMessageSquare } from 'react-icons/fi'
import toast from 'react-hot-toast'
import { replyToRating, updateRatingReply } from '../../api/ratings'
import { formatShortDate } from '../../utils/helpers'

const REPLY_MAX = 1000

/** Shows the owner's response under a review, and lets the listing's owner add or edit it. */
export default function OwnerReply({ review, canReply, onSaved }) {
  const reply = review.reply
  const [editing, setEditing] = useState(false)
  const [body, setBody] = useState('')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')

  const startEditing = () => {
    setBody(reply?.body || '')
    setError('')
    setEditing(true)
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    if (saving) return
    if (!body.trim()) {
      setError('Please write a response before posting.')
      return
    }

    setSaving(true)
    setError('')
    try {
      if (reply) {
        await updateRatingReply(review.id, body)
        toast.success('Response updated')
      } else {
        await replyToRating(review.id, body)
        toast.success('Response posted')
      }
      setEditing(false)
      onSaved?.()
    } catch (err) {
      const data = err.response?.data
      setError(data?.errors?.body?.[0] || data?.message || 'Could not save your response. Please try again.')
    } finally {
      setSaving(false)
    }
  }

  if (editing) {
    return (
      <form onSubmit={handleSubmit} className="mt-3 ml-4 sm:ml-6">
        <label htmlFor={`reply-${review.id}`} className="block text-sm font-medium text-gray-700 mb-1.5">
          {reply ? 'Edit your response' : 'Respond to this review'}
        </label>
        <textarea
          id={`reply-${review.id}`}
          value={body}
          onChange={(e) => setBody(e.target.value)}
          maxLength={REPLY_MAX}
          rows={3}
          autoFocus
          aria-invalid={Boolean(error)}
          className="input-field text-sm resize-y"
        />
        <div className="mt-1 flex justify-between gap-2 text-xs">
          <span className="text-red-600" role={error ? 'alert' : undefined}>{error}</span>
          <span className="text-gray-500 tabular-nums">{body.length}/{REPLY_MAX}</span>
        </div>
        <div className="mt-2 flex flex-wrap gap-2">
          <button type="submit" disabled={saving} className="btn-primary text-sm px-4 py-2 disabled:opacity-60">
            {saving ? 'Saving...' : reply ? 'Save Response' : 'Post Response'}
          </button>
          <button
            type="button"
            onClick={() => setEditing(false)}
            disabled={saving}
            className="btn-secondary text-sm px-4 py-2 disabled:opacity-50"
          >
            Cancel
          </button>
        </div>
      </form>
    )
  }

  return (
    <>
      {reply && (
        <div className="mt-3 ml-4 sm:ml-6 rounded-lg border-l-4 border-primary-500 bg-primary-50/60 px-4 py-3">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <p className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-primary-700">
              <FiCornerDownRight size={14} aria-hidden="true" />
              Owner response
            </p>
            {reply.created_at && (
              <p className="text-xs text-gray-500">{formatShortDate(reply.created_at)}</p>
            )}
          </div>
          <p className="mt-1.5 text-sm text-gray-700 whitespace-pre-line">{reply.body}</p>
        </div>
      )}

      {canReply && (
        <button
          type="button"
          onClick={startEditing}
          className="mt-2 ml-4 sm:ml-6 inline-flex min-h-11 items-center gap-1.5 text-sm font-medium text-primary-600 hover:text-primary-700"
        >
          {reply ? <FiEdit2 size={14} aria-hidden="true" /> : <FiMessageSquare size={14} aria-hidden="true" />}
          {reply ? 'Edit response' : 'Reply to review'}
        </button>
      )}
    </>
  )
}
