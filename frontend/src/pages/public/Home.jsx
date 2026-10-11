import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { FiMapPin, FiHome, FiCoffee, FiShoppingBag } from 'react-icons/fi'
import { getDestinations } from '../../api/destinations'
import { getEvents, getAnnouncements } from '../../api/events'
import { getAccommodations } from '../../api/accommodations'
import { getFoodBeverages } from '../../api/foodBeverages'
import { getRetailShops } from '../../api/retailShops'
import DestinationCard from '../../components/cards/DestinationCard'
import ListingCard from '../../components/cards/ListingCard'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import Reveal from '../../components/common/Reveal'
import HeroSlideshow from '../../components/common/HeroSlideshow'

const CATEGORIES = [
  { to: '/destinations', label: 'Destinations', description: 'Beaches, lakes, and natural wonders', icon: FiMapPin },
  { to: '/accommodations', label: 'Accommodations', description: 'Resorts, hotels, and homestays', icon: FiHome },
  { to: '/food-beverage', label: 'Food & Beverage', description: 'Local delicacies and dining', icon: FiCoffee },
  { to: '/shops', label: 'Local Products', description: 'Pili treats and abaca crafts', icon: FiShoppingBag },
]

export default function Home() {
  const [featuredDestinations, setFeaturedDestinations] = useState([])
  const [featuredAccommodations, setFeaturedAccommodations] = useState([])
  const [featuredFood, setFeaturedFood] = useState([])
  const [featuredProducts, setFeaturedProducts] = useState([])
  const [events, setEvents] = useState([])
  const [announcements, setAnnouncements] = useState([])
  const [stats, setStats] = useState({ destinations: 0, accommodations: 0, food: 0 })
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    Promise.all([
      getDestinations({ per_page: 6, sort: 'featured' }),
      getEvents({ per_page: 3, upcoming: true, featured_first: 1 }),
      getAccommodations({ per_page: 3 }),
      getFoodBeverages({ per_page: 3 }),
      getRetailShops({ per_page: 3 }),
      getAnnouncements({ per_page: 3 }),
    ])
      .then(([destData, eventData, accomData, foodData, productData, announcementData]) => {
        setFeaturedDestinations(destData.data || [])
        setEvents(eventData.data || [])
        setFeaturedAccommodations(accomData.data || [])
        setFeaturedFood(foodData.data || [])
        setFeaturedProducts(productData.data || [])
        setAnnouncements(announcementData.data || [])
        setStats({
          destinations: destData.total ?? destData.data?.length ?? 0,
          accommodations: accomData.total ?? accomData.data?.length ?? 0,
          food: foodData.total ?? foodData.data?.length ?? 0,
        })
      })
      .finally(() => setLoading(false))
  }, [])

  if (loading) return <LoadingSpinner size="lg" />

  return (
    <div>
      <HeroSlideshow />

      {/* Stats strip */}
      <section className="bg-white border-b border-gray-100 py-10">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 grid grid-cols-2 lg:grid-cols-4 gap-8 text-center">
          {[
            { label: 'Destinations', value: `${stats.destinations}+` },
            { label: 'Municipalities Covered', value: '15' },
            { label: 'Places to Stay', value: `${stats.accommodations}+` },
            { label: 'Local Food Spots', value: `${stats.food}+` },
          ].map((s, i) => (
            <Reveal key={s.label} delay={i * 100}>
              <p className="text-3xl lg:text-4xl font-heading font-bold text-primary-600">{s.value}</p>
              <p className="text-sm text-gray-500 mt-1">{s.label}</p>
            </Reveal>
          ))}
        </div>
      </section>

      {/* Explore by category */}
      <section className="py-16 bg-gray-50">
        <div className="max-w-7xl mx-auto px-4 sm:px-6">
          <Reveal className="text-center mb-10">
            <p className="text-primary-600 font-medium mb-2">Get Around</p>
            <h2 className="text-3xl font-heading font-bold text-gray-900">Explore Sorsogon by Category</h2>
          </Reveal>
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
            {CATEGORIES.map((c, i) => (
              <Reveal key={c.to} delay={i * 100}>
                <Link
                  to={c.to}
                  className="group block bg-white rounded-xl border border-gray-100 p-6 h-full hover:border-primary-300 hover:shadow-md transition-all duration-300"
                >
                  <div className="w-12 h-12 rounded-lg bg-primary-50 text-primary-600 flex items-center justify-center mb-4 group-hover:bg-primary-500 group-hover:text-white transition-colors duration-300">
                    <c.icon size={22} />
                  </div>
                  <h3 className="font-heading font-semibold text-gray-900 mb-1">{c.label}</h3>
                  <p className="text-sm text-gray-500">{c.description}</p>
                </Link>
              </Reveal>
            ))}
          </div>
        </div>
      </section>

      {/* Featured Destinations */}
      <section className="py-16 bg-white">
        <div className="max-w-7xl mx-auto px-4 sm:px-6">
          <Reveal className="flex items-end justify-between mb-8">
            <div>
              <p className="text-primary-600 font-medium mb-2">Featured Places</p>
              <h2 className="text-3xl font-heading font-bold text-gray-900">Explore Sorsogon</h2>
            </div>
            <Link to="/destinations" className="text-primary-600 font-medium hover:text-primary-700">
              View all destinations
            </Link>
          </Reveal>
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
            {featuredDestinations.map((destination, i) => (
              <Reveal key={destination.id} delay={(i % 3) * 100}>
                <DestinationCard destination={destination} />
              </Reveal>
            ))}
          </div>
        </div>
      </section>

      {/* AI Planner CTA */}
      <section className="py-16 bg-gray-50">
        <div className="max-w-5xl mx-auto px-4 sm:px-6">
          <Reveal className="bg-primary-50 rounded-2xl p-8 lg:p-12 text-center">
            <p className="text-primary-600 font-medium mb-2">AI-Based Itinerary Planning</p>
            <h2 className="text-3xl font-heading font-bold text-gray-900 mb-4">
              Let Sorsogon plan your trip
            </h2>
            <p className="text-gray-600 max-w-2xl mx-auto mb-8">
              Tell us how many days you have, what you enjoy, and your travel pace. The system will generate a personalized itinerary using verified local destinations from Bulan, Sorsogon.
            </p>
            <Link to="/plan-trip" className="btn-primary">Start Planning</Link>
          </Reveal>
        </div>
      </section>

      {/* Featured Accommodations */}
      {featuredAccommodations.length > 0 && (
        <section className="py-16 bg-white">
          <div className="max-w-7xl mx-auto px-4 sm:px-6">
            <Reveal className="flex items-end justify-between mb-8">
              <div>
                <p className="text-primary-600 font-medium mb-2">Where to Stay</p>
                <h2 className="text-3xl font-heading font-bold text-gray-900">Featured Accommodations</h2>
              </div>
              <Link to="/accommodations" className="text-primary-600 font-medium hover:text-primary-700">
                View all accommodations
              </Link>
            </Reveal>
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
              {featuredAccommodations.map((item, i) => (
                <Reveal key={item.id} delay={i * 100}>
                  <ListingCard item={item} basePath="/accommodations" typeLabel={item.type} />
                </Reveal>
              ))}
            </div>
          </div>
        </section>
      )}

      {/* Taste of Sorsogon */}
      {featuredFood.length > 0 && (
        <section className="py-16 bg-gray-50">
          <div className="max-w-7xl mx-auto px-4 sm:px-6">
            <Reveal className="flex items-end justify-between mb-8">
              <div>
                <p className="text-primary-600 font-medium mb-2">Taste</p>
                <h2 className="text-3xl font-heading font-bold text-gray-900">A Taste of Sorsogon</h2>
              </div>
              <Link to="/food-beverage" className="text-primary-600 font-medium hover:text-primary-700">
                View all food & beverage
              </Link>
            </Reveal>
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
              {featuredFood.map((item, i) => (
                <Reveal key={item.id} delay={i * 100}>
                  <ListingCard item={item} basePath="/food-beverage" typeLabel={item.cuisine_type || 'Restaurant'} />
                </Reveal>
              ))}
            </div>
          </div>
        </section>
      )}

      {/* Local Products */}
      {featuredProducts.length > 0 && (
        <section className="py-16 bg-white">
          <div className="max-w-7xl mx-auto px-4 sm:px-6">
            <Reveal className="flex items-end justify-between mb-8">
              <div>
                <p className="text-primary-600 font-medium mb-2">Shop Local</p>
                <h2 className="text-3xl font-heading font-bold text-gray-900">Local Products</h2>
              </div>
              <Link to="/shops" className="text-primary-600 font-medium hover:text-primary-700">
                View all shops
              </Link>
            </Reveal>
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
              {featuredProducts.map((item, i) => (
                <Reveal key={item.id} delay={i * 100}>
                  <ListingCard item={item} basePath="/shops" typeLabel="Pasalubong Shop" />
                </Reveal>
              ))}
            </div>
          </div>
        </section>
      )}

      {/* Events and Announcements */}
      {(events.length > 0 || announcements.length > 0) && (
        <section className="py-16 bg-gray-50">
          <div className="max-w-7xl mx-auto px-4 sm:px-6">
            <Reveal className="flex items-end justify-between mb-8">
              <div>
                <p className="text-primary-600 font-medium mb-2">Happenings</p>
                <h2 className="text-3xl font-heading font-bold text-gray-900">Events and Announcements</h2>
              </div>
              <Link to="/events" className="text-primary-600 font-medium hover:text-primary-700">
                View all
              </Link>
            </Reveal>
            {events.length > 0 && (
              <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                {events.map((event) => (
                  <Link key={event.id} to={`/events/${event.id}`} className="card p-6 block hover:shadow-md transition-shadow duration-300">
                    <p className="text-sm text-primary-600 font-medium mb-2">
                      {new Date(event.start_date).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })}
                    </p>
                    <h3 className="font-heading font-semibold text-xl text-gray-900 mb-2">{event.title}</h3>
                    <p className="text-sm text-gray-600 line-clamp-3">{event.description}</p>
                  </Link>
                ))}
              </div>
            )}
            {announcements.length > 0 && (
              <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                {announcements.map((announcement) => (
                  <div key={announcement.id} className="card p-6">
                    {announcement.is_pinned && (
                      <span className="inline-block text-xs bg-accent-100 text-accent-700 px-2 py-1 rounded mb-3">Pinned</span>
                    )}
                    <h3 className="font-heading font-semibold text-xl text-gray-900 mb-2">{announcement.title}</h3>
                    <p className="text-sm text-gray-600 line-clamp-3">{announcement.content}</p>
                  </div>
                ))}
              </div>
            )}
          </div>
        </section>
      )}
    </div>
  )
}