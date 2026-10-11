import { useState } from 'react'

export default function PageHero({ eyebrow, title, subtitle, image, children }) {
  const [imageFailed, setImageFailed] = useState(false)

  return (
    <section className="relative h-screen overflow-hidden bg-gradient-to-br from-primary-800 via-primary-700 to-secondary-700 text-white">
      {image && !imageFailed && (
        <img
          src={image}
          alt=""
          onError={() => setImageFailed(true)}
          className="absolute inset-0 w-full h-full object-cover"
        />
      )}
      <div className="absolute inset-0 bg-black/45" />
      <div className="relative h-full max-w-7xl mx-auto px-4 sm:px-6 flex flex-col justify-center">
        <div className="max-w-2xl">
          {eyebrow && (
            <p className="text-accent-400 font-medium mb-3 animate-[fadeSlideUp_0.6s_ease-out_both]">
              {eyebrow}
            </p>
          )}
          <h1 className="text-4xl lg:text-6xl font-heading font-bold mb-6 leading-tight animate-[fadeSlideUp_0.6s_ease-out_0.1s_both]">
            {title}
          </h1>
          {subtitle && (
            <p className="text-lg text-primary-100 mb-8 leading-relaxed animate-[fadeSlideUp_0.6s_ease-out_0.2s_both]">
              {subtitle}
            </p>
          )}
          {children && (
            <div className="animate-[fadeSlideUp_0.6s_ease-out_0.3s_both]">
              {children}
            </div>
          )}
        </div>
      </div>
    </section>
  )
}