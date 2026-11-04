import { Link } from 'react-router-dom'
import { FiArrowRight, FiMail, FiMapPin } from 'react-icons/fi'

const EXPLORE = [
  { to: '/destinations', label: 'Destinations' },
  { to: '/accommodations', label: 'Accommodations' },
  { to: '/food-beverage', label: 'Food & Beverage' },
  { to: '/shops', label: 'Pasalubong Shops' },
]

const INFORMATION = [
  { to: '/events', label: 'Events' },
  { to: '/about', label: 'About' },
  { to: '/plan-trip', label: 'Plan Your Trip' },
]

export default function Footer() {
  return (
    <footer className="bg-primary-900 text-white/75">
      {/* Trip-planner invitation */}
      <div className="border-b border-white/10">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 py-10 flex flex-col md:flex-row md:items-center md:justify-between gap-5">
          <div>
            <p className="font-heading text-2xl sm:text-3xl font-semibold text-white">Ready to explore Sorsogon?</p>
            <p className="mt-1 text-sm">Build a day-by-day itinerary in minutes with the AI trip planner.</p>
          </div>
          <Link to="/plan-trip" className="btn-accent shrink-0 self-start md:self-auto">
            Plan Your Trip <FiArrowRight size={16} aria-hidden="true" />
          </Link>
        </div>
      </div>

      <div className="max-w-7xl mx-auto px-4 sm:px-6 py-12">
        <div className="grid grid-cols-2 md:grid-cols-4 gap-8">
          <div className="col-span-2 md:col-span-1">
            <img src="/images/Logo.png" alt="Sorsogon" className="h-12 w-auto mb-4 brightness-0 invert" />
            <p className="text-sm leading-relaxed">
              A web-based tourism information and management system for the Province of Sorsogon.
            </p>
          </div>
          <div>
            <h4 className="font-body text-xs font-semibold uppercase tracking-[0.18em] text-accent-400 mb-4">Explore</h4>
            <ul className="space-y-2.5 text-sm">
              {EXPLORE.map((link) => (
                <li key={link.to}>
                  <Link to={link.to} className="hover:text-white transition-colors">{link.label}</Link>
                </li>
              ))}
            </ul>
          </div>
          <div>
            <h4 className="font-body text-xs font-semibold uppercase tracking-[0.18em] text-accent-400 mb-4">Information</h4>
            <ul className="space-y-2.5 text-sm">
              {INFORMATION.map((link) => (
                <li key={link.to}>
                  <Link to={link.to} className="hover:text-white transition-colors">{link.label}</Link>
                </li>
              ))}
            </ul>
          </div>
          <div className="col-span-2 md:col-span-1">
            <h4 className="font-body text-xs font-semibold uppercase tracking-[0.18em] text-accent-400 mb-4">Contact</h4>
            <ul className="space-y-2.5 text-sm">
              <li className="text-white">Tourism Office of Bulan</li>
              <li className="flex items-start gap-2">
                <FiMapPin size={15} className="mt-0.5 shrink-0" aria-hidden="true" /> Bulan, Sorsogon, Philippines
              </li>
              <li className="flex items-start gap-2">
                <FiMail size={15} className="mt-0.5 shrink-0" aria-hidden="true" />
                <a href="mailto:tourism@bulan.gov.ph" className="hover:text-white transition-colors break-all">tourism@bulan.gov.ph</a>
              </li>
            </ul>
          </div>
        </div>
        <div className="border-t border-white/10 mt-10 pt-6 flex flex-col sm:flex-row items-center justify-between gap-3 text-center text-xs text-white/60">
          <p>2026 STIM. Province of Sorsogon Tourism Information System.</p>
          <div className="flex flex-wrap items-center justify-center gap-x-5 gap-y-2">
            <Link to="/privacy-policy" className="hover:text-white transition-colors">Privacy Policy</Link>
            <Link to="/terms-and-conditions" className="hover:text-white transition-colors">Terms &amp; Conditions</Link>
            <Link to="/photo-credits" className="hover:text-white transition-colors">Photo Credits</Link>
          </div>
        </div>
      </div>
    </footer>
  )
}
