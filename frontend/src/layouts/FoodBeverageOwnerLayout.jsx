import { FiGrid, FiCoffee, FiBook, FiStar } from 'react-icons/fi'
import OwnerPortalLayout from './OwnerPortalLayout'

const navLinks = [
  { to: '/business/food-beverage', icon: FiGrid, label: 'Dashboard', short: 'Overview', end: true },
  { to: '/business/food-beverage/establishment', icon: FiCoffee, label: 'My Establishment', short: 'Place' },
  { to: '/business/food-beverage/menu', icon: FiBook, label: 'Menu Management', short: 'Menu' },
  { to: '/business/food-beverage/reviews', icon: FiStar, label: 'Guest Reviews', short: 'Reviews' },
]

export default function FoodBeverageOwnerLayout() {
  return <OwnerPortalLayout portalLabel="Food & Beverage Portal" homePath="/business/food-beverage" navLinks={navLinks} />
}
