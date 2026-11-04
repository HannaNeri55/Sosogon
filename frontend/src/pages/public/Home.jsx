import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  FiArrowRight,
  FiBell,
  FiCalendar,
  FiCoffee,
  FiCpu,
  FiHome,
  FiMapPin,
  FiShoppingBag,
  FiSliders,
  FiSun,
  FiMap,
} from 'react-icons/fi'
import { getDestinations } from '../../api/destinations'
import { getEvents, getAnnouncements } from '../../api/events'
import { getAccommodations } from '../../api/accommodations'
import { getFoodBeverages } from '../../api/foodBeverages'
import { getRetailShops } from '../../api/retailShops'
import ListingCard from '../../components/cards/ListingCard'
import EventCard from '../../components/events/EventCard'
import AnnouncementModal from '../../components/events/AnnouncementModal'
import Reveal from '../../components/common/Reveal'
import HeroSlideshow from '../../components/common/HeroSlideshow'
import SectionHeader from '../../components/common/SectionHeader'
import SkeletonGrid from '../../components/common/SkeletonCard'
import EmptyState from '../../components/common/EmptyState'
import { StarDisplay } from '../../components/common/StarRating'
import { getImageUrl } from '../../utils/helpers'

const primaryImage = (item) => item?.images?.find((img) => img.is_primary) || item?.images?.[0]

// ==================== HOMEPAGE PIECES ====================

/** Category tile backed by a real photo from that module (falls back to a colour panel). */
function CategoryTile({ to, label, description, icon: Icon, image }) {
  return (
    <Link
      to={to}
      className="group relative block h-44 sm:h-56 overflow-hidden rounded-(--radius-card) bg-linear-to-br from-primary-700 to-secondary-700 shadow-(--shadow-card) hover:shadow-(--shadow-card-hover) transition-shadow"
    >
      {image && (
        <img
          src={getImageUrl(image, { thumbnail: true })}
          alt=""
          loading="lazy"
          className="absolute inset-0 h-full w-full object-cover transition-transform duration-700 group-hover:scale-110"
        />
      )}
      <div className="absolute inset-0 bg-linear-to-t from-black/80 via-black/30 to-transparent" />
      <div className="absolute inset-x-0 bottom-0 p-4 sm:p-5 text-white">
        <span className="mb-3 inline-flex h-9 w-9 items-center justify-center rounded-full bg-white/15 backdrop-blur-sm">
          <Icon size={17} aria-hidden="true" />
        </span>
        <h3 className="font-heading text-lg sm:text-xl font-semibold leading-tight">{label}</h3>
        <p className="mt-1 text-xs sm:text-sm text-white/80 line-clamp-2">{description}</p>
      </div>
    </Link>
  )
}

/** Photo-led destination tile used in the featured mosaic; `large` spans two grid rows. */
function DestinationTile({ destination, large = false }) {
  return (
    <Link
      to={`/destinations/${destination.id}`}
      className={`group relative block overflow-hidden rounded-(--radius-card) bg-gray-200 shadow-(--shadow-card) hover:shadow-(--shadow-card-hover) transition-shadow ${
        large ? 'h-80 sm:h-auto sm:col-span-2 sm:row-span-2' : 'h-64 sm:h-auto'
      }`}
    >
      <img
        src={getImageUrl(primaryImage(destination), { thumbnail: !large })}
        alt={destination.name}
        loading="lazy"
        className="absolute inset-0 h-full w-full object-cover transition-transform duration-700 group-hover:scale-105"
      />
      <div className="absolute inset-0 bg-linear-to-t from-black/80 via-black/20 to-transparent" />
      <div className="absolute inset-x-0 bottom-0 p-5 text-white">
        {destination.category?.name && (
          <span className="mb-2 inline-block rounded-full bg-white/20 px-2.5 py-0.5 text-xs font-medium backdrop-blur-sm">
            {destination.category.name}
          </span>
        )}
        <h3 className={`font-heading font-semibold leading-tight ${large ? 'text-2xl sm:text-3xl' : 'text-xl'}`}>
          {destination.name}
        </h3>
        <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-white/85">
          {destination.municipality?.name && (
            <span className="inline-flex items-center gap-1">
              <FiMapPin size={13} aria-hidden="true" /> {destination.municipality.name}
            </span>
          )}
          {Number(destination.average_rating) > 0 && <StarDisplay rating={destination.average_rating} size={13} />}
        </div>
        {large && destination.description && (
          <p className="mt-3 max-w-lg text-sm text-white/80 line-clamp-2">{destination.description}</p>
        )}
      </div>
    </Link>
  )
}

/** Compact row (thumbnail + name) for the side-by-side food and shop lists. */
function CompactListing({ item, basePath, label }) {
  return (
    <Link
      to={`${basePath}/${item.id}`}
      className="group flex items-center gap-4 rounded-xl p-2 -mx-2 hover:bg-white transition-colors"
    >
      <span className="h-20 w-24 shrink-0 overflow-hidden rounded-lg bg-gray-200">
        <img
          src={getImageUrl(primaryImage(item), { thumbnail: true })}
          alt=""
          loading="lazy"
          className="h-full w-full object-cover transition-transform duration-500 group-hover:scale-110"
        />
      </span>
      <span className="min-w-0">
        {label && <span className="text-xs font-medium text-secondary-500">{label}</span>}
        <span className="block font-heading font-semibold text-gray-900 group-hover:text-primary-600 transition-colors truncate">
          {item.name}
        </span>
        {item.municipality?.name && (
          <span className="mt-0.5 flex items-center gap-1 text-xs text-gray-500">
            <FiMapPin size={11} aria-hidden="true" /> {item.municipality.name}
          </span>
        )}
      </span>
    </Link>
  )
}

const PLANNER_STEPS = [
  { icon: FiSliders, title: 'Tell us your trip', text: 'Days, interests, budget and travel pace.' },
  { icon: FiCpu, title: 'AI builds the plan', text: 'Day by day, using verified Sorsogon listings.' },
  { icon: FiMap, title: 'Adjust and save', text: 'Swap places, then keep it in My Itineraries.' },
]

// ==================== PAGE ====================

export default function Home() {
  const [featuredDestinations, setFeaturedDestinations] = useState([])
  const [featuredAccommodations, setFeaturedAccommodations] = useState([])
  const [featuredFood, setFeaturedFood] = useState([])
  const [featuredShops, setFeaturedShops] = useState([])
  const [events, setEvents] = useState([])
  const [announcements, setAnnouncements] = useState([])
  const [stats, setStats] = useState({ destinations: 0, accommodations: 0, food: 0 })
  const [loading, setLoading] = useState(true)
  const [openAnnouncement, setOpenAnnouncement] = useState(null)
  const closeAnnouncement = useCallback(() => setOpenAnnouncement(null), [])

  useEffect(() => {
    Promise.all([
      getDestinations({ per_page: 5, sort: 'featured' }),
      getEvents({ per_page: 3, upcoming: 1 }), // soonest first; past events excluded by the API
      getAccommodations({ per_page: 3 }),
      getFoodBeverages({ per_page: 3 }),
      getRetailShops({ per_page: 3 }),
      getAnnouncements({ per_page: 3 }),
    ])
      .then(([destData, eventData, accomData, foodData, shopData, announcementData]) => {
        setFeaturedDestinations(destData.data || [])
        setEvents(eventData.data || [])
        setFeaturedAccommodations(accomData.data || [])
        setFeaturedFood(foodData.data || [])
        setFeaturedShops(shopData.data || [])
        setAnnouncements(announcementData.data || [])
        setStats({
          destinations: destData.total ?? destData.data?.length ?? 0,
          accommodations: accomData.total ?? accomData.data?.length ?? 0,
          food: foodData.total ?? foodData.data?.length ?? 0,
        })
      })
      .finally(() => setLoading(false))
  }, [])

  const categories = [
    { to: '/destinations', label: 'Destinations', description: 'Beaches, lakes, and natural wonders', icon: FiSun, image: primaryImage(featuredDestinations[1] || featuredDestinations[0]) },
    { to: '/accommodations', label: 'Places to Stay', description: 'Resorts, hotels, and homestays', icon: FiHome, image: primaryImage(featuredAccommodations[0]) },
    { to: '/food-beverage', label: 'Food & Beverage', description: 'Local delicacies and dining', icon: FiCoffee, image: primaryImage(featuredFood[0]) },
    { to: '/shops', label: 'Local Products', description: 'Pili treats and abaca crafts', icon: FiShoppingBag, image: primaryImage(featuredShops[0]) },
  ]
  const plannerImage = primaryImage(featuredDestinations[2] || featuredDestinations[0])

  return (
    <div>
      <HeroSlideshow size="full" />

      {/* ==================== AT A GLANCE ==================== */}
      <section className="bg-white border-b border-gray-100">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 grid grid-cols-2 lg:grid-cols-4 divide-x divide-gray-100">
          {[
            { label: 'Destinations', value: stats.destinations },
            { label: 'Municipalities', value: 15 },
            { label: 'Places to Stay', value: stats.accommodations },
            { label: 'Food Spots', value: stats.food },
          ].map((s, i) => (
            <Reveal key={s.label} delay={i * 80} className="py-7 sm:py-9 text-center">
              <p className="text-3xl lg:text-4xl font-heading font-semibold text-primary-700">
                {loading ? '–' : s.value}
              </p>
              <p className="mt-1 text-xs sm:text-sm font-medium uppercase tracking-wider text-gray-500">{s.label}</p>
            </Reveal>
          ))}
        </div>
      </section>

      {/* ==================== EXPLORE BY CATEGORY ==================== */}
      <section className="py-16 sm:py-20">
        <div className="max-w-7xl mx-auto px-4 sm:px-6">
          <Reveal>
            <SectionHeader
              eyebrow="Start exploring"
              title="Everything Sorsogon has to offer"
              intro="From volcano-fed springs to quiet island coves — find places to see, stay, eat and shop across the province."
            />
          </Reveal>
          <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-5">
            {categories.map((c, i) => (
              <Reveal key={c.to} delay={i * 80}>
                <CategoryTile {...c} />
              </Reveal>
            ))}
          </div>
        </div>
      </section>

      {/* ==================== FEATURED DESTINATIONS (MOSAIC) ==================== */}
      <section className="pb-16 sm:pb-20">
        <div className="max-w-7xl mx-auto px-4 sm:px-6">
          <Reveal>
            <SectionHeader
              eyebrow="Featured places"
              title="Destinations worth the trip"
              linkTo="/destinations"
              linkLabel="View all destinations"
            />
          </Reveal>
          {loading ? (
            <SkeletonGrid count={3} />
          ) : featuredDestinations.length > 0 ? (
            <Reveal className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-5 sm:auto-rows-[16rem]">
              {featuredDestinations.map((destination, i) => (
                <DestinationTile key={destination.id} destination={destination} large={i === 0} />
              ))}
            </Reveal>
          ) : null}
        </div>
      </section>

      {/* ==================== AI PLANNER ==================== */}
      <section className="relative overflow-hidden bg-primary-900 text-white">
        {plannerImage && (
          <img
            src={getImageUrl(plannerImage)}
            alt=""
            loading="lazy"
            className="absolute inset-0 h-full w-full object-cover opacity-30"
          />
        )}
        <div className="absolute inset-0 bg-linear-to-r from-primary-900 via-primary-900/90 to-primary-900/60" />
        <div className="relative max-w-7xl mx-auto px-4 sm:px-6 py-16 sm:py-24 grid gap-10 lg:grid-cols-2 lg:items-center">
          <Reveal>
            <SectionHeader
              tone="dark"
              eyebrow="AI itinerary planner"
              title="Let us plan your Sorsogon trip"
              intro="Tell us how many days you have, what you enjoy and how you like to travel. The planner builds a day-by-day itinerary from verified local destinations, stays, food spots and shops."
              className="mb-6!"
            />
            <Link to="/plan-trip" className="btn-accent">
              Start Planning <FiArrowRight size={16} aria-hidden="true" />
            </Link>
          </Reveal>
          <ol className="grid gap-4">
            {PLANNER_STEPS.map((step, i) => (
              <Reveal key={step.title} delay={i * 120}>
                <li className="flex items-start gap-4 rounded-2xl border border-white/15 bg-white/5 p-5 backdrop-blur-sm">
                  <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-accent-400 text-gray-900">
                    <step.icon size={19} aria-hidden="true" />
                  </span>
                  <span>
                    <span className="block text-xs font-semibold uppercase tracking-wider text-white/60">Step {i + 1}</span>
                    <span className="block font-heading text-lg font-semibold">{step.title}</span>
                    <span className="block text-sm text-white/75">{step.text}</span>
                  </span>
                </li>
              </Reveal>
            ))}
          </ol>
        </div>
      </section>

      {/* ==================== WHERE TO STAY ==================== */}
      {(loading || featuredAccommodations.length > 0) && (
        <section className="py-16 sm:py-20">
          <div className="max-w-7xl mx-auto px-4 sm:px-6">
            <Reveal>
              <SectionHeader
                eyebrow="Where to stay"
                title="Featured accommodations"
                linkTo="/accommodations"
                linkLabel="View all accommodations"
              />
            </Reveal>
            {loading ? (
              <SkeletonGrid count={3} />
            ) : (
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                {featuredAccommodations.map((item, i) => (
                  <Reveal key={item.id} delay={i * 100}>
                    <ListingCard item={item} basePath="/accommodations" typeLabel={item.type} />
                  </Reveal>
                ))}
              </div>
            )}
          </div>
        </section>
      )}

      {/* ==================== TASTE & SHOP LOCAL ==================== */}
      {!loading && (featuredFood.length > 0 || featuredShops.length > 0) && (
        <section className="py-16 sm:py-20 bg-gray-100/70">
          <div className="max-w-7xl mx-auto px-4 sm:px-6 grid gap-12 lg:grid-cols-2">
            {featuredFood.length > 0 && (
              <Reveal>
                <SectionHeader
                  eyebrow="Taste"
                  title="A taste of Sorsogon"
                  linkTo="/food-beverage"
                  linkLabel="All food & beverage"
                  className="mb-6!"
                />
                <div className="space-y-2">
                  {featuredFood.map((item) => (
                    <CompactListing key={item.id} item={item} basePath="/food-beverage" label={item.cuisine_type || 'Restaurant'} />
                  ))}
                </div>
              </Reveal>
            )}
            {featuredShops.length > 0 && (
              <Reveal delay={120}>
                <SectionHeader
                  eyebrow="Shop local"
                  title="Pasalubong & local products"
                  linkTo="/shops"
                  linkLabel="All shops"
                  className="mb-6!"
                />
                <div className="space-y-2">
                  {featuredShops.map((item) => (
                    <CompactListing key={item.id} item={item} basePath="/shops" label="Pasalubong Shop" />
                  ))}
                </div>
              </Reveal>
            )}
          </div>
        </section>
      )}

      {/* ==================== UPCOMING EVENTS ==================== */}
      {!loading && (
        <section className="py-16 sm:py-20">
          <div className="max-w-7xl mx-auto px-4 sm:px-6">
            <Reveal>
              <SectionHeader
                eyebrow="Happenings"
                title="Upcoming events"
                intro="Festivals, fiestas and community celebrations coming up across the province."
                linkTo="/events"
                linkLabel="View event calendar"
              />
            </Reveal>
            {events.length > 0 ? (
              <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                {events.map((event, i) => (
                  <Reveal key={event.id} delay={i * 100}>
                    <EventCard event={event} />
                  </Reveal>
                ))}
              </div>
            ) : (
              <EmptyState icon={FiCalendar} title="No upcoming events at the moment">
                New festivals and activities will appear here as soon as the tourism office schedules them.
              </EmptyState>
            )}
          </div>
        </section>
      )}

      {/* ==================== ANNOUNCEMENTS ==================== */}
      {!loading && announcements.length > 0 && (
        <section className="py-16 sm:py-20 bg-gray-100/70 border-t border-gray-200/70">
          <div className="max-w-7xl mx-auto px-4 sm:px-6">
            <Reveal>
              <SectionHeader
                eyebrow="From the tourism office"
                title="Announcements"
                intro="News, advisories and reminders for visitors. Select one to read it in full."
                linkTo="/events?tab=announcements"
                linkLabel="All announcements"
              />
            </Reveal>
            <div className="grid gap-5 md:grid-cols-3">
              {announcements.map((announcement, i) => (
                <Reveal key={announcement.id} delay={i * 100} className="h-full">
                  <button
                    type="button"
                    onClick={() => setOpenAnnouncement(announcement)}
                    className="group flex h-full w-full flex-col rounded-(--radius-card) border border-gray-200/70 bg-white p-6 text-left shadow-(--shadow-card) hover:shadow-(--shadow-card-hover) hover:-translate-y-0.5 transition-[box-shadow,transform] duration-200"
                  >
                    <div className="mb-4 flex items-center justify-between gap-3">
                      <span className="flex h-10 w-10 items-center justify-center rounded-full bg-secondary-50 text-secondary-600">
                        <FiBell size={17} aria-hidden="true" />
                      </span>
                      {announcement.is_pinned && (
                        <span className="text-xs font-semibold bg-accent-100 text-accent-700 px-2.5 py-1 rounded-full">Pinned</span>
                      )}
                    </div>
                    {announcement.created_at && (
                      <time className="text-xs font-medium uppercase tracking-wider text-gray-500" dateTime={announcement.created_at}>
                        {new Date(announcement.created_at).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })}
                      </time>
                    )}
                    <h3 className="mt-1 font-heading text-lg font-bold text-gray-900 group-hover:text-primary-700 transition-colors">
                      {announcement.title}
                    </h3>
                    <p className="mt-2 text-sm text-gray-600 line-clamp-3">{announcement.content}</p>
                    <span className="mt-auto pt-4 inline-flex items-center gap-1 text-sm font-semibold text-primary-700">
                      Read more <FiArrowRight size={14} className="transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
                    </span>
                  </button>
                </Reveal>
              ))}
            </div>
          </div>
        </section>
      )}

      {openAnnouncement && <AnnouncementModal announcement={openAnnouncement} onClose={closeAnnouncement} />}
    </div>
  )
}
