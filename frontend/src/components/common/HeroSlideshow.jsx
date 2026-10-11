import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { getHeroSlides } from '../../api/heroSlides'
import { getImageUrl } from '../../utils/helpers'

// Phones: the home page fills the visible screen (svh ignores the browser's address bar);
// other pages use a shorter banner so their content starts on the first screen.
// From sm up both are full screen, as before.
const HEIGHTS = {
  full: 'h-svh sm:h-screen',
  page: 'h-[62svh] min-h-[26rem] sm:h-screen sm:min-h-0',
}

export default function HeroSlideshow({ size = 'page' }) {
  const height = HEIGHTS[size] ?? HEIGHTS.page
  const [slides, setSlides] = useState([])
  const [active, setActive] = useState(0)

  useEffect(() => {
    getHeroSlides()
      .then((data) => setSlides(data || []))
      .catch(() => {})
  }, [])

  useEffect(() => {
    if (slides.length === 0) return
    const timer = setInterval(() => {
      setActive((i) => (i + 1) % slides.length)
    }, 6000)
    return () => clearInterval(timer)
  }, [slides])

  if (slides.length === 0) {
    return <section className={`relative ${height} overflow-hidden bg-gradient-to-br from-primary-800 via-primary-700 to-secondary-700 text-white`} />
  }

  const slide = slides[Math.min(active, slides.length - 1)]

  return (
    <section className={`relative ${height} overflow-hidden bg-gradient-to-br from-primary-800 via-primary-700 to-secondary-700 text-white`}>
      {slides.map((s, i) => (
        <img
          key={s.id}
          src={getImageUrl(s.image_path)}
          alt=""
          className={`absolute inset-0 w-full h-full object-cover transition-opacity duration-1000 ease-in-out ${
            i === active ? 'opacity-100' : 'opacity-0'
          }`}
        />
      ))}
      <div className="absolute inset-0 bg-black/45" />

      <div className="relative h-full max-w-7xl mx-auto px-4 sm:px-6 flex flex-col justify-center">
        <div className="max-w-2xl" key={active}>
          <p className="text-accent-400 font-medium mb-3 animate-[fadeSlideUp_0.6s_ease-out_both]">{slide.eyebrow}</p>
          <h1 className="text-4xl lg:text-6xl font-heading font-bold mb-4 sm:mb-6 leading-tight animate-[fadeSlideUp_0.6s_ease-out_0.1s_both]">
            {slide.title}
          </h1>
          <p className="text-base sm:text-lg text-primary-100 mb-6 sm:mb-8 leading-relaxed animate-[fadeSlideUp_0.6s_ease-out_0.2s_both]">
            {slide.subtitle}
          </p>
          <div className="flex flex-wrap gap-3 sm:gap-4 animate-[fadeSlideUp_0.6s_ease-out_0.3s_both]">
            <Link to="/destinations" className="btn-accent">Explore Destinations</Link>
            <Link to="/plan-trip" className="btn-secondary">Plan Your Trip</Link>
          </div>
        </div>
      </div>

      <div className="absolute bottom-6 left-1/2 -translate-x-1/2 flex gap-2">
        {slides.map((_, i) => (
          <button
            key={i}
            type="button"
            onClick={() => setActive(i)}
            aria-label={`Go to slide ${i + 1}`}
            className={`h-2 rounded-full transition-all duration-300 ${
              i === active ? 'w-8 bg-white' : 'w-2 bg-white/50 hover:bg-white/75'
            }`}
          />
        ))}
      </div>
    </section>
  )
}
