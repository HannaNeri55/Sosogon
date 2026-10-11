// frontend/src/pages/public/About.jsx
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { FiTarget, FiMapPin, FiUsers, FiHome, FiCoffee, FiShoppingBag, FiCompass } from 'react-icons/fi'
import { getDestinations } from '../../api/destinations'
import { getAccommodations } from '../../api/accommodations'
import { getFoodBeverages } from '../../api/foodBeverages'
import HeroSlideshow from '../../components/common/HeroSlideshow'
import Reveal from '../../components/common/Reveal'

const CATEGORIES = [
  { to: '/destinations', label: 'Destinations', icon: FiMapPin },
  { to: '/accommodations', label: 'Accommodations', icon: FiHome },
  { to: '/food-beverage', label: 'Food & Beverage', icon: FiCoffee },
  { to: '/shops', label: 'Pasalubong Shops', icon: FiShoppingBag },
]

export default function About() {
  const [stats, setStats] = useState({ destinations: 0, accommodations: 0, food: 0 })

  useEffect(() => {
    Promise.all([
      getDestinations({ per_page: 1 }),
      getAccommodations({ per_page: 1 }),
      getFoodBeverages({ per_page: 1 }),
    ]).then(([dest, accom, food]) => {
      setStats({
        destinations: dest.total ?? 0,
        accommodations: accom.total ?? 0,
        food: food.total ?? 0,
      })
    })
  }, [])

  return (
    <div className="bg-gray-50 min-h-screen">
      <HeroSlideshow />

      {/* Stats strip */}
      <section className="bg-white border-b border-gray-100 py-10">
        <div className="max-w-5xl mx-auto px-4 sm:px-6 grid grid-cols-2 sm:grid-cols-4 gap-8 text-center">
          {[
            { label: 'Municipalities Covered', value: '15' },
            { label: 'Destinations', value: `${stats.destinations}+` },
            { label: 'Places to Stay', value: `${stats.accommodations}+` },
            { label: 'Food Spots', value: `${stats.food}+` },
          ].map((s, i) => (
            <Reveal key={s.label} delay={i * 100}>
              <p className="text-3xl lg:text-4xl font-heading font-bold text-primary-600">{s.value}</p>
              <p className="text-sm text-gray-500 mt-1">{s.label}</p>
            </Reveal>
          ))}
        </div>
      </section>

      <div className="max-w-5xl mx-auto px-4 sm:px-6 py-16 space-y-8">
        <Reveal className="bg-white rounded-xl p-8 border border-gray-100">
          <div className="flex items-center gap-2 mb-4">
            <FiTarget className="text-primary-600" size={20} />
            <h2 className="text-2xl font-heading font-semibold text-gray-900">Purpose of STIM</h2>
          </div>
          <p className="text-gray-600 leading-relaxed">
            The system addresses the lack of a centralized digital tourism platform for Sorsogon by consolidating destinations, accommodations, food and beverage establishments, retail products, events, and announcements into one verified website. It also supports AI-based itinerary generation and administrative tourism data management.
          </p>
        </Reveal>

        <Reveal className="bg-white rounded-xl p-8 border border-gray-100">
          <div className="flex items-center gap-2 mb-4">
            <FiMapPin className="text-primary-600" size={20} />
            <h2 className="text-2xl font-heading font-semibold text-gray-900">Province of Sorsogon</h2>
          </div>
          <p className="text-gray-600 leading-relaxed">
            The Province of Sorsogon, located in the Bicol Region of the Philippines, is known for its natural resources, coastal destinations, historical landmarks, eco-tourism sites, and local products. Sorsogon is designed to promote the province's tourism potential through organized and accessible digital information.
          </p>
        </Reveal>

        <Reveal className="bg-white rounded-xl p-8 border border-gray-100">
          <div className="flex items-center gap-2 mb-4">
            <FiUsers className="text-primary-600" size={20} />
            <h2 className="text-2xl font-heading font-semibold text-gray-900">Role of the Tourism Office</h2>
          </div>
          <p className="text-gray-600 leading-relaxed">
            The Tourism Office is responsible for maintaining accurate tourism records, approving business listings, managing events and announcements, monitoring visitor analytics, and ensuring that all information published in the system is verified and reliable.
          </p>
        </Reveal>

        <Reveal>
          <div className="bg-white rounded-xl p-8 border border-gray-100">
            <div className="flex items-center gap-2 mb-6">
              <FiCompass className="text-primary-600" size={20} />
              <h2 className="text-2xl font-heading font-semibold text-gray-900">Explore the Platform</h2>
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
              {CATEGORIES.map((c) => (
                <Link
                  key={c.to}
                  to={c.to}
                  className="group flex items-center gap-3 p-4 rounded-lg border border-gray-100 hover:border-primary-300 hover:bg-primary-50/40 transition-all duration-300"
                >
                  <div className="w-9 h-9 rounded-lg bg-primary-50 text-primary-600 flex items-center justify-center shrink-0 group-hover:bg-primary-500 group-hover:text-white transition-colors duration-300">
                    <c.icon size={16} />
                  </div>
                  <span className="text-sm font-medium text-gray-700">{c.label}</span>
                </Link>
              ))}
            </div>
          </div>
        </Reveal>
      </div>
    </div>
  )
}