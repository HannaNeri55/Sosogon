import React from 'react'
import ReactDOM from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import { Toaster } from 'react-hot-toast'
import App from './App'
import { AuthProvider } from './contexts/AuthContext'
import ErrorBoundary from './components/common/ErrorBoundary'
import ScrollManager from './components/layout/ScrollManager'
import './index.css'

// Production only: shows saved itineraries when a page can't load for lack of signal.
if (import.meta.env.PROD && 'serviceWorker' in navigator) {
  window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}))
}

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <ErrorBoundary>
      <BrowserRouter>
        <ScrollManager />
        <AuthProvider>
          <App />
          <Toaster position="top-right" toastOptions={{ duration: 3000 }} />
        </AuthProvider>
      </BrowserRouter>
    </ErrorBoundary>
  </React.StrictMode>
)