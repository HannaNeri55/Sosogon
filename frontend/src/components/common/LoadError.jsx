import { FiAlertTriangle, FiRefreshCw } from 'react-icons/fi'
import EmptyState from './EmptyState'

/**
 * Shown in place of a page's content when it couldn't be loaded, so a failed request never
 * passes for "nothing found". The error dialog has usually explained why already.
 */
export default function LoadError({ what = 'this page', onRetry, className = '' }) {
  return (
    <EmptyState
      icon={FiAlertTriangle}
      title={`Couldn't load ${what}`}
      className={className}
      action={onRetry && (
        <button type="button" onClick={onRetry} className="btn-primary">
          <FiRefreshCw size={16} aria-hidden="true" /> Try again
        </button>
      )}
    >
      Check your internet connection, then try again.
    </EmptyState>
  )
}
