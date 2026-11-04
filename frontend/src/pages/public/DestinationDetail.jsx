// frontend/src/pages/public/DestinationDetail.jsx
import { useEffect, useState } from "react";
import { useParams, Link } from "react-router-dom";
import { FiMapPin, FiClock, FiDollarSign, FiPlus } from "react-icons/fi";
import { getDestination } from "../../api/destinations";
import { useAuth } from "../../contexts/AuthContext";
import { StarDisplay } from "../../components/common/StarRating";
import LocationMap from "../../components/maps/LocationMap";
import DetailHero from "../../components/common/DetailHero";
import LoadingSpinner from "../../components/common/LoadingSpinner";
import GuestReviewCarousel from "../../components/common/GuestReviewCarousel";
import ReviewSection from "../../components/common/ReviewSection";
import NearbyPlaces from "../../components/common/NearbyPlaces";
import DetailLayout from "../../components/detail/DetailLayout";
import DetailSection from "../../components/detail/DetailSection";
import DiningInfo from "../../components/detail/DiningInfo";
import FactsCard from "../../components/detail/FactsCard";
import AmenityGrid from "../../components/detail/AmenityGrid";
import PriceList from "../../components/detail/PriceList";
import { formatPeso, getImageUrl, IMAGE_FALLBACK_SVG } from "../../utils/helpers";
import { approxHours, approxRange, estimateOf } from "../../utils/estimates";

/** Single entrance fee for the facts card (used when no itemised fees exist). */
function entranceFeeLabel(fee) {
  if (fee === null || fee === undefined || fee === "") return "Not specified";
  if (Number(fee) === 0) return "Free";
  return `₱${Number(fee).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

export default function DestinationDetail() {
  const { id } = useParams();
  const { isAuthenticated, isTourist } = useAuth();
  const [destination, setDestination] = useState(null);
  // The nearby_* lists and their radius, straight from the API response.
  const [nearby, setNearby] = useState(null);
  const [loading, setLoading] = useState(true);

  const loadDestination = async () => {
    try {
      const data = await getDestination(id);
      setDestination(data.destination);
      setNearby(data);
    } catch (e) {
      console.error(e);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    loadDestination();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id]);

  if (loading) return <LoadingSpinner size="lg" />;
  if (!destination)
    return <div className="p-6 sm:p-8 text-center">Destination not found</div>;

  const hasFeeList = destination.fees?.length > 0;
  const hasPricing = hasFeeList || destination.rates?.length > 0;

  return (
    <div className="bg-gray-50 min-h-screen">
      <DetailHero
        backTo="/destinations"
        backLabel="Back to Destinations"
        badge={destination.category?.name}
        title={destination.name}
        subtitle={destination.address}
        rating={destination.average_rating}
        reviewCount={destination.total_reviews}
        imageUrl={getImageUrl(destination.images?.[0])}
        imageAlt={destination.name}
        images={[...(destination.images || [])].sort(
          (a, b) => Number(Boolean(b.is_primary)) - Number(Boolean(a.is_primary)),
        )}
      />

      <DetailLayout
        aside={
          <FactsCard
            facts={[
              { icon: FiMapPin, label: "Municipality", value: destination.municipality?.name },
              { icon: FiClock, label: "Operating Hours", value: destination.operating_hours || approxHours(estimateOf(destination, "operating_hours")) || "Not specified" },
              ...(hasFeeList
                ? []
                : [{
                    icon: FiDollarSign,
                    label: "Entrance Fee",
                    value: destination.entrance_fee == null
                      ? approxRange(estimateOf(destination, "entrance_fee")) || "Not specified"
                      : entranceFeeLabel(destination.entrance_fee),
                  }]),
            ]}
            latitude={destination.latitude}
            longitude={destination.longitude}
            website={destination.website}
          >
            {isAuthenticated && isTourist && (
              <Link to="/plan-trip" className="btn-accent w-full">
                <FiPlus size={16} aria-hidden="true" /> Add to Itinerary
              </Link>
            )}
          </FactsCard>
        }
      >
        <DetailSection title="About this destination">
          <p className="text-gray-700 leading-relaxed whitespace-pre-line">{destination.description}</p>
        </DetailSection>

        {hasPricing && (
          <DetailSection title="Fees & Rates">
            <div className="space-y-6">
              <PriceList title="Entrance Fees" rows={destination.fees} />
              <PriceList title="Cottage / Rental Rates" rows={destination.rates} />
            </div>
          </DetailSection>
        )}

        {destination.amenities?.length > 0 && (
          <DetailSection title="Amenities & Facilities">
            <AmenityGrid items={destination.amenities.map((amenity) => amenity.name)} />
          </DetailSection>
        )}

        {destination.dining_details && (
          <DetailSection title="Food & Dining">
            <DiningInfo details={destination.dining_details} meals={destination.meals_offered} />
          </DetailSection>
        )}

        {destination.visitor_guidelines && (
          <DetailSection title="Visitor Information">
            <p className="text-gray-700 leading-relaxed whitespace-pre-line">{destination.visitor_guidelines}</p>
          </DetailSection>
        )}

        {/* THINGS TO DO: activities the tourism office has linked to this destination */}
        {destination.activities?.length > 0 && (
          <DetailSection title="Things to Do" subtitle={`Activities available at ${destination.name}.`}>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
              {destination.activities.map((a) => (
                <Link key={a.id} to={`/activities/${a.id}`} className="card group flex flex-col">
                  <div className="aspect-video overflow-hidden bg-gray-100">
                    <img
                      src={getImageUrl(a.image_path)}
                      alt={a.name}
                      className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"
                      loading="lazy"
                      onError={(e) => {
                        e.target.onerror = null;
                        e.target.src = IMAGE_FALLBACK_SVG;
                      }}
                    />
                  </div>
                  <div className="p-4">
                    <div className="flex items-center justify-between gap-2 mb-2">
                      {a.type && (
                        <span className="text-xs font-medium text-secondary-700 bg-secondary-50 px-2 py-0.5 rounded-full capitalize">
                          {a.type}
                        </span>
                      )}
                      {Number(a.average_rating) > 0 && <StarDisplay rating={a.average_rating} size={14} />}
                    </div>
                    <h3 className="font-heading font-semibold text-gray-900 mb-1 group-hover:text-primary-600 transition-colors">
                      {a.name}
                    </h3>
                    {a.description && <p className="text-sm text-gray-500 line-clamp-2">{a.description}</p>}
                    {(a.duration_minutes || a.price != null) && (
                      <p className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-600">
                        {a.duration_minutes && (
                          <span className="inline-flex items-center gap-1">
                            <FiClock size={12} aria-hidden="true" />
                            {formatDuration(a.duration_minutes)}
                          </span>
                        )}
                        {a.price != null && (
                          <span className="font-medium text-primary-700">
                            {Number(a.price) === 0 ? "Free" : formatPeso(a.price)}
                          </span>
                        )}
                      </p>
                    )}
                  </div>
                </Link>
              ))}
            </div>
          </DetailSection>
        )}

        <DetailSection title="Location">
          <LocationMap latitude={destination.latitude} longitude={destination.longitude} title={destination.name} address={destination.address} />
        </DetailSection>

        {destination.ratings?.length > 0 && (
          <GuestReviewCarousel item={destination} title="What Visitors Are Saying" />
        )}

        <NearbyPlaces data={nearby} name={destination.name} kind="destination" />

        <div id="reviews" className="scroll-mt-24">
          <ReviewSection item={destination} rateableType="destination" onReviewed={loadDestination} />
        </div>
      </DetailLayout>
    </div>
  );
}

function formatDuration(minutes) {
  const hours = Math.floor(minutes / 60);
  const rest = minutes % 60;
  if (!hours) return `${rest} min`;
  return rest ? `${hours} hr ${rest} min` : `${hours} hr`;
}
