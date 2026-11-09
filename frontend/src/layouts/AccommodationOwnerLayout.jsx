import { FiGrid, FiHome, FiList, FiStar } from 'react-icons/fi'
import OwnerPortalLayout from './OwnerPortalLayout'

const navLinks = [
  { to: '/business/accommodation', icon: FiGrid, label: 'Dashboard', short: 'Overview', end: true },
  { to: '/business/accommodation/property', icon: FiHome, label: 'My Property', short: 'Property' },
  { to: '/business/accommodation/rooms', icon: FiList, label: 'Accommodation Options', short: 'Options' },
  { to: '/business/accommodation/reviews', icon: FiStar, label: 'Guest Reviews', short: 'Reviews' },
]

export default function AccommodationOwnerLayout() {
  return <OwnerPortalLayout portalLabel="Accommodation Portal" homePath="/business/accommodation" navLinks={navLinks} />
}
