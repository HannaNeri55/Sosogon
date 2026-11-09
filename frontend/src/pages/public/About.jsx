// frontend/src/pages/public/About.jsx
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { FiTarget, FiMapPin, FiUsers, FiHome, FiCoffee, FiShoppingBag, FiArrowRight, FiCompass } from 'react-icons/fi'
import { getDestinations } from '../../api/destinations'
import { getAccommodations } from '../../api/accommodations'
import { getFoodBeverages } from '../../api/foodBeverages'
import HeroSlideshow from '../../components/common/HeroSlideshow'
import Reveal from '../../components/common/Reveal'
import SectionHeader from '../../components/common/SectionHeader'

const CATEGORIES = [
  { to: '/destinations', label: 'Destinations', text: 'Beaches, springs and heritage sites', icon: FiMapPin },
  { to: '/accommodations', label: 'Accommodations', text: 'Resorts, hotels and homestays', icon: FiHome },
  { to: '/food-beverage', label: 'Food & Beverage', text: 'Local delicacies and dining', icon: FiCoffee },
  { to: '/shops', label: 'Pasalubong Shops', text: 'Pili treats and abaca crafts', icon: FiShoppingBag },
]

const PILLARS = [
  {
    icon: FiTarget,
    title: 'Purpose of STIM',
    text: 'The system addresses the lack of a centralized digital tourism platform for Sorsogon by consolidating destinations, accommodations, food and beverage establishments, retail products, events, and announcements into one verified website. It also supports AI-based itinerary generation and administrative tourism data management.',
  },
  {
    icon: FiMapPin,
    title: 'Province of Sorsogon',
    text: "The Province of Sorsogon, located in the Bicol Region of the Philippines, is known for its natural resources, coastal destinations, historical landmarks, eco-tourism sites, and local products. Sorsogon is designed to promote the province's tourism potential through organized and accessible digital information.",
  },
  {
    icon: FiUsers,
    title: 'Role of the Tourism Office',
    text: 'The Tourism Office is responsible for maintaining accurate tourism records, approving business listings, managing events and announcements, monitoring visitor analytics, and ensuring that all information published in the system is verified and reliable.',
  },
]

export default function About() {
  const [stats, setStats] = useState({ destinations: 0, accommodations: 0, food: 0 })
  const [loaded, setLoaded] = useState(false)

  useEffect(() => {
    Promise.all([
      getDestinations({ per_page: 1 }),
      getAccommodations({ per_page: 1 }),
      getFoodBeverages({ per_page: 1 }),
    ])
      .then(([dest, accom, food]) => {
        setStats({
          destinations: dest.total ?? 0,
          accommodations: accom.total ?? 0,
          food: food.total ?? 0,
        })
      })
      .finally(() => setLoaded(true))
  }, [])

  return (
    <div className="bg-gray-50 min-h-screen">
      <HeroSlideshow />

      {/* ==================== AT A GLANCE ==================== */}
      <section className="bg-white border-b border-gray-100">
        <div className="max-w-6xl mx-auto px-4 sm:px-6 grid grid-cols-2 sm:grid-cols-4 divide-x divide-gray-100">
          {[
            { label: 'Municipalities', value: 15 },
            { label: 'Destinations', value: stats.destinations },
            { label: 'Places to Stay', value: stats.accommodations },
            { label: 'Food Spots', value: stats.food },
          ].map((s, i) => (
            <Reveal key={s.label} delay={i * 80} className="py-7 sm:py-9 text-center">
              <p className="text-3xl lg:text-4xl font-heading font-bold text-primary-700">{loaded ? s.value : '–'}</p>
              <p className="mt-1 text-xs sm:text-sm font-medium uppercase tracking-wider text-gray-500">{s.label}</p>
            </Reveal>
          ))}
        </div>
      </section>

      {/* ==================== ABOUT ==================== */}
      <section className="py-16 sm:py-20">
        <div className="max-w-6xl mx-auto px-4 sm:px-6">
          <Reveal>
            <SectionHeader
              eyebrow="About STIM"
              title="One verified home for Sorsogon tourism"
              intro="What the platform is for, the province it serves, and who keeps its information accurate."
            />
          </Reveal>
          <div className="grid gap-6 md:grid-cols-3">
            {PILLARS.map((pillar, i) => (
              <Reveal key={pillar.title} delay={i * 100} className="h-full">
                <article className="h-full bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) p-6 sm:p-7">
                  <span className="mb-5 flex h-11 w-11 items-center justify-center rounded-full bg-primary-50 text-primary-600">
                    <pillar.icon size={20} aria-hidden="true" />
                  </span>
                  <h3 className="text-xl font-heading font-bold text-gray-900 mb-3">{pillar.title}</h3>
                  <p className="text-gray-600 leading-relaxed">{pillar.text}</p>
                </article>
              </Reveal>
            ))}
          </div>
        </div>
      </section>

      {/* ==================== EXPLORE ==================== */}
      <section className="pb-16 sm:pb-20">
        <div className="max-w-6xl mx-auto px-4 sm:px-6">
          <Reveal className="relative overflow-hidden rounded-3xl bg-primary-900 px-6 py-10 sm:px-10 sm:py-12 text-white">
            <FiCompass className="pointer-events-none absolute -right-10 -bottom-10 text-white/5" size={260} aria-hidden="true" />
            <SectionHeader tone="dark" eyebrow="Explore the platform" title="Start discovering Sorsogon" className="mb-8!" />
            <div className="relative grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
              {CATEGORIES.map((c) => (
                <Link
                  key={c.to}
                  to={c.to}
                  className="group flex items-start gap-3 rounded-2xl border border-white/15 bg-white/5 p-4 hover:bg-white/10 transition-colors"
                >
                  <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-accent-400 text-gray-900">
                    <c.icon size={17} aria-hidden="true" />
                  </span>
                  <span className="min-w-0">
                    <span className="flex items-center gap-1 font-semibold">
                      {c.label}
                      <FiArrowRight size={14} className="transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
                    </span>
                    <span className="block text-sm text-white/70">{c.text}</span>
                  </span>
                </Link>
              ))}
            </div>
          </Reveal>
        </div>
      </section>
    </div>
  )
}
