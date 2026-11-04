import { FiGrid, FiShoppingBag, FiPackage, FiStar } from 'react-icons/fi'
import OwnerPortalLayout from './OwnerPortalLayout'

const navLinks = [
  { to: '/business/retail-shop', icon: FiGrid, label: 'Dashboard', short: 'Overview', end: true },
  { to: '/business/retail-shop/shop', icon: FiShoppingBag, label: 'My Shop', short: 'Shop' },
  { to: '/business/retail-shop/products', icon: FiPackage, label: 'Product Catalog', short: 'Products' },
  { to: '/business/retail-shop/reviews', icon: FiStar, label: 'Guest Reviews', short: 'Reviews' },
]

export default function RetailShopOwnerLayout() {
  return <OwnerPortalLayout portalLabel="Local Products Portal" homePath="/business/retail-shop" navLinks={navLinks} />
}
