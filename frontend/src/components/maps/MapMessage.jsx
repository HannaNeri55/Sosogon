import { FiExternalLink, FiMapPin } from 'react-icons/fi'

/** Grey panel used while the map loads or when it can't be shown; optional link to Google Maps. */
export default function MapMessage({ height, children, link }) {
  return (
    <div className="bg-gray-100 rounded-xl flex flex-col items-center justify-center gap-3 px-6 text-center text-sm text-gray-600" style={{ height }}>
      <FiMapPin size={22} className="text-gray-400" aria-hidden="true" />
      <div>{children}</div>
      {link && (
        <a href={link} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1.5 font-semibold text-primary-700 hover:text-primary-800">
          View this location on Google Maps <FiExternalLink size={13} aria-hidden="true" />
        </a>
      )}
    </div>
  )
}
