import { Link } from 'react-router-dom'

export default function Footer() {
  return (
    <footer className="bg-primary-800 text-primary-100">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 py-12">
        <div className="grid grid-cols-1 md:grid-cols-4 gap-8">
          <div>
            <img src="/images/Logo.png" alt="Sorsogon" className="h-12 w-auto mb-3 brightness-0 invert" />
            <p className="text-sm text-primary-200 leading-relaxed">
              A web-based tourism information and management system for the Province of Sorsogon.
            </p>
          </div>
          <div>
            <h4 className="text-white font-medium mb-3">Explore</h4>
            <div className="space-y-2 text-sm">
              <Link to="/destinations" className="block hover:text-white">Destinations</Link>
              <Link to="/accommodations" className="block hover:text-white">Accommodations</Link>
              <Link to="/food-beverage" className="block hover:text-white">Food & Beverage</Link>
              <Link to="/shops" className="block hover:text-white">Pasalubong Shops</Link>
            </div>
          </div>
          <div>
            <h4 className="text-white font-medium mb-3">Information</h4>
            <div className="space-y-2 text-sm">
              <Link to="/events" className="block hover:text-white">Events</Link>
              <Link to="/about" className="block hover:text-white">About</Link>
              <Link to="/plan-trip" className="block hover:text-white">Plan Your Trip</Link>
            </div>
          </div>
          <div>
            <h4 className="text-white font-medium mb-3">Contact</h4>
            <div className="space-y-2 text-sm">
              <p>Tourism Office of Bulan</p>
              <p>Bulan, Sorsogon, Philippines</p>
              <p>tourism@bulan.gov.ph</p>
            </div>
          </div>
        </div>
        <div className="border-t border-primary-700 mt-8 pt-8 flex flex-col sm:flex-row items-center justify-center gap-2 sm:gap-6 text-center text-sm text-primary-300">
          <p>2026 STIM. Province of Sorsogon Tourism Information System.</p>
          <div className="flex items-center gap-4">
            <Link to="/privacy-policy" className="hover:text-white">Privacy Policy</Link>
            <Link to="/terms-and-conditions" className="hover:text-white">Terms &amp; Conditions</Link>
          </div>
        </div>
      </div>
    </footer>
  )
}