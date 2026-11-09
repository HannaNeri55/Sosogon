
import { useEffect, useState } from 'react'
import { FiChevronLeft, FiChevronRight, FiMaximize2, FiX } from 'react-icons/fi'
import { getImageUrl, IMAGE_FALLBACK_SVG } from '../../utils/helpers'

export default function PhotoGallery({ images = [], title = 'Photo gallery' }) {
  const [activeIndex, setActiveIndex] = useState(0)
  const [isOpen, setIsOpen] = useState(false)

  const photos = Array.isArray(images)
    ? images.filter((image) => image && (typeof image === 'string' || image.image_path))
    : []

  const displayPhotos = photos.length > 0 ? photos : [null]
  const total = displayPhotos.length
  const currentIndex = Math.min(activeIndex, total - 1)
  const currentPhoto = displayPhotos[currentIndex]

  const nextPhoto = () => {
    setActiveIndex((index) => (index + 1) % total)
  }

  const previousPhoto = () => {
    setActiveIndex((index) => (index - 1 + total) % total)
  }

  useEffect(() => {
    if (!isOpen) return

    const handleKeyDown = (event) => {
      if (event.key === 'Escape') setIsOpen(false)
      if (event.key === 'ArrowRight' && total > 1) {
        setActiveIndex((index) => (index + 1) % total)
      }
      if (event.key === 'ArrowLeft' && total > 1) {
        setActiveIndex((index) => (index - 1 + total) % total)
      }
    }

    document.addEventListener('keydown', handleKeyDown)
    return () => document.removeEventListener('keydown', handleKeyDown)
  }, [isOpen, total])

  useEffect(() => {
    if (!isOpen) return

    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    return () => {
      document.body.style.overflow = previousOverflow
    }
  }, [isOpen])

  const imageError = (event) => {
    event.currentTarget.onerror = null
    event.currentTarget.src = IMAGE_FALLBACK_SVG
  }

  const openAt = (index) => {
    setActiveIndex(index)
    setIsOpen(true)
  }

  // Desktop with 3+ photos: one large photo and up to four smaller ones; "+N" on the last tile.
  const mosaic = photos.slice(0, 5)
  const hiddenCount = photos.length - mosaic.length

  return (
    <div className="mb-8">
      {photos.length >= 3 && (
        <div className="hidden lg:grid grid-cols-4 grid-rows-2 gap-2 h-112 overflow-hidden rounded-2xl">
          {mosaic.map((photo, index) => (
            <button
              key={photo.id ?? index}
              type="button"
              onClick={() => openAt(index)}
              className={`group relative overflow-hidden bg-gray-100 cursor-zoom-in ${
                index === 0 ? 'col-span-2 row-span-2' : mosaic.length === 3 && index > 0 ? 'col-span-2' : ''
              }`}
              aria-label={`Open photo ${index + 1} of ${photos.length}`}
            >
              <img
                src={getImageUrl(photo, { thumbnail: index > 0 })}
                alt={`${title} - photo ${index + 1}`}
                className="h-full w-full object-cover transition-transform duration-500 group-hover:scale-105"
                fetchPriority={index === 0 ? 'high' : undefined}
                loading={index === 0 ? undefined : 'lazy'}
                onError={imageError}
              />
              <span className="absolute inset-0 bg-black/0 group-hover:bg-black/10 transition-colors" />
              {index === mosaic.length - 1 && hiddenCount > 0 && (
                <span className="absolute inset-0 flex items-center justify-center bg-black/50 text-lg font-semibold text-white">
                  +{hiddenCount} more
                </span>
              )}
            </button>
          ))}
        </div>
      )}
      {photos.length >= 3 && (
        <div className="hidden lg:flex justify-end mt-3">
          <button
            type="button"
            onClick={() => openAt(0)}
            className="inline-flex items-center gap-2 rounded-full border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 hover:border-primary-400 hover:text-primary-700 transition-colors"
          >
            <FiMaximize2 size={15} aria-hidden="true" /> Show all {photos.length} photos
          </button>
        </div>
      )}

      <div className={photos.length >= 3 ? 'lg:hidden' : ''}>
      <div className="relative overflow-hidden rounded-2xl bg-gray-100 h-72 sm:h-96 lg:h-112">
        <button
          type="button"
          onClick={() => setIsOpen(true)}
          className="block w-full h-full cursor-zoom-in"
          aria-label={`View ${title} photos`}
        >
          <img
            src={currentPhoto ? getImageUrl(currentPhoto) : IMAGE_FALLBACK_SVG}
            alt={`${title} - photo ${currentIndex + 1}`}
            className="w-full h-full object-cover"
            fetchPriority="high"
            onError={imageError}
          />
        </button>

        {total > 1 && (
          <>
            <button
              type="button"
              onClick={previousPhoto}
              aria-label="Previous photo"
              className="absolute left-3 top-1/2 -translate-y-1/2 rounded-full bg-white/90 p-2.5 shadow hover:bg-white"
            >
              <FiChevronLeft size={22} />
            </button>

            <button
              type="button"
              onClick={nextPhoto}
              aria-label="Next photo"
              className="absolute right-3 top-1/2 -translate-y-1/2 rounded-full bg-white/90 p-2.5 shadow hover:bg-white"
            >
              <FiChevronRight size={22} />
            </button>

            <span className="absolute bottom-3 left-3 rounded-full bg-black/65 px-3 py-1 text-sm text-white">
              {currentIndex + 1} / {total}
            </span>
          </>
        )}

        <button
          type="button"
          onClick={() => setIsOpen(true)}
          aria-label="Open photo viewer"
          className="absolute bottom-3 right-3 rounded-full bg-white/90 p-2.5 shadow hover:bg-white"
        >
          <FiMaximize2 size={18} />
        </button>
      </div>

      {photos.length > 1 && (
        <div className="flex gap-2 overflow-x-auto py-3" aria-label="Photo thumbnails">
          {photos.map((photo, index) => (
            <button
              key={photo.id ?? index}
              type="button"
              onClick={() => setActiveIndex(index)}
              aria-label={`Show photo ${index + 1}`}
              aria-pressed={currentIndex === index}
              className={`shrink-0 w-20 h-16 sm:w-24 sm:h-20 overflow-hidden rounded-lg border-2 ${
                currentIndex === index
                  ? 'border-primary-600'
                  : 'border-transparent opacity-70 hover:opacity-100'
              }`}
            >
              <img
                src={getImageUrl(photo, { thumbnail: true })}
                alt={`${title} thumbnail ${index + 1}`}
                className="w-full h-full object-cover"
                loading="lazy"
                onError={imageError}
              />
            </button>
          ))}
        </div>
      )}
      </div>

      {isOpen && (
        <div
          role="dialog"
          aria-modal="true"
          aria-label={`${title} photo viewer`}
          className="fixed inset-0 z-100 flex flex-col bg-black/95"
        >
          <div className="flex items-center justify-between px-4 py-4 text-white">
            {/* Photo credits are listed on the Photo Credits page rather than over each photo. */}
            <span className="text-sm font-medium">
              {currentIndex + 1} / {total}
            </span>

            <button
              type="button"
              onClick={() => setIsOpen(false)}
              aria-label="Close photo viewer"
              className="rounded-full p-2 hover:bg-white/20"
            >
              <FiX size={26} />
            </button>
          </div>

          <div className="relative flex flex-1 min-h-0 items-center justify-center px-12">
            <img
              src={currentPhoto ? getImageUrl(currentPhoto) : IMAGE_FALLBACK_SVG}
              alt={`${title} - enlarged photo ${currentIndex + 1}`}
              className="max-h-full max-w-full object-contain"
              onError={imageError}
            />

            {total > 1 && (
              <>
                <button
                  type="button"
                  onClick={previousPhoto}
                  aria-label="Previous enlarged photo"
                  className="absolute left-2 sm:left-5 rounded-full bg-white/15 p-2 text-white hover:bg-white/30"
                >
                  <FiChevronLeft size={28} />
                </button>

                <button
                  type="button"
                  onClick={nextPhoto}
                  aria-label="Next enlarged photo"
                  className="absolute right-2 sm:right-5 rounded-full bg-white/15 p-2 text-white hover:bg-white/30"
                >
                  <FiChevronRight size={28} />
                </button>
              </>
            )}
          </div>

          {photos.length > 1 && (
            <div className="flex justify-center gap-2 overflow-x-auto px-4 py-4">
              {photos.map((photo, index) => (
                <button
                  key={photo.id ?? index}
                  type="button"
                  onClick={() => setActiveIndex(index)}
                  aria-label={`View enlarged photo ${index + 1}`}
                  className={`h-14 w-16 shrink-0 overflow-hidden rounded-md border-2 ${
                    currentIndex === index ? 'border-white' : 'border-transparent opacity-60'
                  }`}
                >
                  <img
                    src={getImageUrl(photo, { thumbnail: true })}
                    alt=""
                    className="h-full w-full object-cover"
                    loading="lazy"
                    onError={imageError}
                  />
                </button>
              ))}
            </div>
          )}
        </div>
      )}
    </div>
  )
}
