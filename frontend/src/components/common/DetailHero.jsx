import { FiMapPin } from "react-icons/fi";
import { StarDisplay } from "./StarRating";
import PhotoGallery from "./PhotoGallery";
import { SampleNotice } from "./SampleBadge";
import BackButton from "../layout/BackButton";

/**
 * Top of every listing detail page: back button, type badge, title, address,
 * rating and the photo gallery. Directions and website live in the page's
 * FactsCard ("Plan your visit").
 */
export default function DetailHero({
  backTo,
  backLabel,
  badge,
  title,
  subtitle,
  rating,
  reviewCount,
  imageUrl,
  imageAlt,
  images,
  hideGallery = false,
  sample = false,
}) {
  const hasRating = rating != null && Number(rating) > 0;

  return (
    <div className="max-w-6xl mx-auto px-4 sm:px-6 pt-6 sm:pt-8">
      <BackButton to={backTo} label={backLabel} className="mb-4" />

      <div className="mb-6">
        {badge && (
          <span className="inline-block text-xs font-semibold uppercase tracking-wider text-secondary-700 bg-secondary-50 border border-secondary-100 px-3 py-1 rounded-full mb-3">
            {badge}
          </span>
        )}
        <h1 className="text-3xl sm:text-4xl lg:text-5xl font-heading font-bold text-gray-900 leading-tight">
          {title}
        </h1>
        {sample && <SampleNotice />}
        <div className="mt-3 flex flex-wrap items-center gap-x-5 gap-y-2 text-gray-600">
          {subtitle && (
            <p className="flex items-center gap-1.5">
              <FiMapPin size={15} className="shrink-0 text-primary-600" aria-hidden="true" /> {subtitle}
            </p>
          )}
          {rating != null && (
            <a href="#reviews" className="flex items-center gap-2 hover:text-primary-700">
              <StarDisplay rating={rating} size={16} />
              <span className="text-sm">
                {hasRating ? <span className="font-semibold text-gray-900">{Number(rating).toFixed(1)}</span> : "No ratings yet"}{" "}
                ({reviewCount ?? 0} {reviewCount === 1 ? "review" : "reviews"})
              </span>
            </a>
          )}
        </div>
      </div>

      {!hideGallery && (
        <PhotoGallery
          images={images?.length ? images : imageUrl ? [imageUrl] : []}
          title={imageAlt || title}
        />
      )}
    </div>
  );
}
