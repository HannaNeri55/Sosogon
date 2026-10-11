import { Component } from 'react'
import { FiAlertTriangle } from 'react-icons/fi'

const CHUNK_ERROR_PATTERN = /dynamically imported module|Failed to fetch|Loading chunk/i
const RELOAD_FLAG = 'stim_chunk_reload_attempted'

export default class ErrorBoundary extends Component {
  constructor(props) {
    super(props)
    this.state = { hasError: false }
  }

  static getDerivedStateFromError(error) {
    return { hasError: true, error }
  }

  componentDidCatch(error, info) {
    console.error('Unhandled render error:', error, info)

    // A stale chunk from a previous deploy — most users just need a fresh
    // page load to pick up the new build. Try that once automatically
    // before falling back to the manual reload button below.
    if (CHUNK_ERROR_PATTERN.test(error?.message || '')) {
      let alreadyTried = false
      try {
        alreadyTried = sessionStorage.getItem(RELOAD_FLAG) === '1'
        if (!alreadyTried) sessionStorage.setItem(RELOAD_FLAG, '1')
      } catch {
        // sessionStorage unavailable — fall through to the manual button
      }
      if (!alreadyTried) {
        window.location.reload()
      }
    }
  }

  handleReload = () => {
    try {
      sessionStorage.removeItem(RELOAD_FLAG)
    } catch {
      // ignore
    }
    window.location.reload()
  }

  render() {
    if (this.state.hasError) {
      return (
        <div className="min-h-screen bg-gray-50 flex items-center justify-center px-4 py-12">
          <div className="max-w-md w-full bg-white rounded-2xl shadow-sm border border-gray-100 p-8 text-center">
            <div className="w-16 h-16 rounded-full bg-red-50 flex items-center justify-center mx-auto mb-6">
              <FiAlertTriangle size={32} className="text-red-600" />
            </div>
            <h1 className="text-2xl font-heading font-bold text-gray-900 mb-3">Something went wrong</h1>
            <p className="text-gray-600 mb-8 leading-relaxed">
              This page ran into an unexpected error. Reloading usually fixes it, especially right after a
              site update.
            </p>
            <button type="button" onClick={this.handleReload} className="btn-primary">
              Reload Page
            </button>
          </div>
        </div>
      )
    }

    return this.props.children
  }
}
