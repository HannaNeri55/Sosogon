import usePageTitle from "../../hooks/usePageTitle";
import { useCallback, useEffect, useState } from "react";
import { useParams, useSearchParams } from "react-router-dom";
import { FiMapPin, FiPhone, FiClock, FiKey, FiMail, FiHome } from "react-icons/fi";
import { estimateOf } from "../../utils/estimates";
import { accommodationTypeLabel, isEntireProperty, optionFacts, optionName, optionPrice } from "../../utils/accommodationOptions";
import { getAccommodation } from "../../api/accommodations";
import LocationMap from "../../components/maps/LocationMap";
import NearbyPlaces from "../../components/common/NearbyPlaces";
import LoadingSpinner from "../../components/common/LoadingSpinner";
import ReviewSection from "../../components/common/ReviewSection";
import GuestReviewCarousel from "../../components/common/GuestReviewCarousel";
import DetailHero from "../../components/common/DetailHero";
import DetailLayout from "../../components/detail/DetailLayout";
import DetailSection from "../../components/detail/DetailSection";
import FactsCard from "../../components/detail/FactsCard";
import AmenityGrid from "../../components/detail/AmenityGrid";
import DiningInfo from "../../components/detail/DiningInfo";
import RoomCard from "../../components/rooms/RoomCard";
import RoomDetailsModal from "../../components/rooms/RoomDetailsModal";
import LoadError from "../../components/common/LoadError";

function EntirePropertyDetails({ option, onOpen }) {
  const price = optionPrice(option);
  const facts = optionFacts(option);
  return (
    <div className="rounded-xl border border-gray-200 bg-white p-4">
      <p className="flex items-center gap-2 font-semibold text-gray-900">
        <FiHome size={16} className="text-primary-600" aria-hidden="true" /> {optionName(option)}
      </p>
      {option.description && <p className="mt-2 text-sm text-gray-600 whitespace-pre-line">{option.description}</p>}
      {facts.length > 0 && <p className="mt-2 text-sm text-gray-600">{facts.join(" · ")}</p>}
      {price.amount && <p className="mt-2 font-semibold text-gray-900">{price.amount}</p>}
      <p className="text-sm text-gray-600">{price.note}</p>
      <button type="button" onClick={() => onOpen(option)} className="mt-3 text-sm font-medium text-primary-600 hover:text-primary-700">
        View details
      </button>
    </div>
  );
}

export default function AccommodationDetail() {
  const { id } = useParams();
  const [item, setItem] = useState(null);
  usePageTitle(item?.name);
  const [loading, setLoading] = useState(true);
  // True when the request failed for a reason other than "no such accommodation".
  const [loadFailed, setLoadFailed] = useState(false);
  // The open option lives in the link, so a tourist can share it or go back to the page.
  const [searchParams, setSearchParams] = useSearchParams();
  const openOption = useCallback((option) => setSearchParams({ option: option.id }), [setSearchParams]);
  const closeOption = useCallback(() => setSearchParams({}, { replace: true }), [setSearchParams]);

  const load = () =>
    getAccommodation(id)
      .then((data) => {
        setItem(data);
        setLoadFailed(false);
      })
      .catch((error) => setLoadFailed(error.response?.status !== 404))
      .finally(() => setLoading(false));

  const retry = () => {
    setLoading(true);
    load();
  };

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id]);

  if (loading) return <LoadingSpinner size="lg" />;

  if (!item) {
    return loadFailed
      ? <div className="px-4 py-12 sm:py-16"><LoadError what="this accommodation" onRetry={retry} className="max-w-xl mx-auto" /></div>
      : <div className="p-6 sm:p-8 text-center">Accommodation not found</div>;
  }

  const options = item.rooms || [];
  const units = options.filter((option) => !isEntireProperty(option));
  const wholeOption = options.find(isEntireProperty);
  const offersWhole = Boolean(wholeOption) || ["entire_property", "both"].includes(item.offering_arrangement);
  const selectedOption = options.find((option) => String(option.id) === searchParams.get("option"));
  const classifications = item.classifications?.length ? item.classifications : item.type && item.type !== "Accommodation" ? [item.type] : [];
  const checkIn = item.check_in_time || estimateOf(item, "check_in");
  const checkOut = item.check_out_time || estimateOf(item, "check_out");
  const checkTimesStated = Boolean(item.check_in_time || item.check_out_time);

  return (
    <div className="bg-gray-50 min-h-screen">
      <DetailHero
        backTo="/accommodations"
        backLabel="Back to Accommodations"
        badge={accommodationTypeLabel({ ...item, classifications }) || "Accommodation"}
        title={item.name}
        subtitle={item.address}
        rating={item.average_rating}
        reviewCount={item.ratings?.length}
        images={item.images || []}
        imageAlt={item.name}
        sample={item.is_sample}
      />

      <DetailLayout
        aside={
          <FactsCard
            facts={[
              { icon: FiMapPin, label: "Address", value: item.address },
              { icon: FiPhone, label: "Contact", value: item.contact_number || "Not available" },
              ...(item.email ? [{ icon: FiMail, label: "Email", value: item.email }] : []),
              { icon: FiClock, label: "Operating Hours", value: item.operating_hours || "Not specified" },
              {
                label: "Price Range",
                value: item.price_range && <span className="capitalize">{item.price_range}</span>,
              },
              ...(checkIn || checkOut
                ? [{
                    icon: FiKey,
                    label: "Check-in / Check-out",
                    value: checkTimesStated
                      ? `${checkIn || "Not stated"} / ${checkOut || "Not stated"}`
                      : `Usually ${checkIn} / ${checkOut} (confirm with the property)`,
                  }]
                : []),
            ]}
            name={item.name}
            latitude={item.latitude}
            longitude={item.longitude}
            website={item.website}
          />
        }
      >
        <DetailSection title="About this place">
          <p className="text-gray-700 leading-relaxed whitespace-pre-line">{item.description}</p>
        </DetailSection>

        {item.amenities?.length > 0 && (
          <DetailSection title="Property amenities" subtitle="Facilities shared by the whole property.">
            <AmenityGrid items={item.amenities} />
          </DetailSection>
        )}

        {units.length > 0 && (
          <DetailSection title="Accommodation options" subtitle="Select one to see its photos, amenities and details.">
            <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-5">
              {units.map((option) => (
                <RoomCard key={option.id} room={option} onOpen={openOption} />
              ))}
            </div>
          </DetailSection>
        )}

        {offersWhole && (
          <DetailSection title="Entire property" subtitle="This property can be rented as a whole for exclusive use.">
            {wholeOption ? (
              <EntirePropertyDetails option={wholeOption} onOpen={openOption} />
            ) : (
              <p className="text-sm text-gray-600">Contact the property about renting the whole place, including its capacity and rates.</p>
            )}
          </DetailSection>
        )}

        {units.length === 0 && !offersWhole && (
          <DetailSection title="Accommodation options">
            <p className="text-sm text-gray-600">Contact the property for room details and rates.</p>
          </DetailSection>
        )}

        <p className="-mt-2 text-xs text-gray-500">
          For information only. SOSOGON takes no bookings and doesn't track availability. Contact the property directly to arrange your stay.
        </p>

        {selectedOption && <RoomDetailsModal key={selectedOption.id} room={selectedOption} onClose={closeOption} />}

        {item.dining_details && (
          <DetailSection title="Dining">
            <DiningInfo details={item.dining_details} />
          </DetailSection>
        )}

        <DetailSection title="Location">
          <LocationMap latitude={item.latitude} longitude={item.longitude} title={item.name} address={item.address} />
        </DetailSection>

        <GuestReviewCarousel item={item} />

        <NearbyPlaces data={item} name={item.name} kind="accommodation" />

        <div id="reviews" className="scroll-mt-24">
          <ReviewSection item={item} rateableType="accommodation" onReviewed={load} />
        </div>
      </DetailLayout>
    </div>
  );
}
