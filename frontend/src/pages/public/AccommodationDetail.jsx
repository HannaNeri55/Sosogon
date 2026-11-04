import { useCallback, useEffect, useState } from "react";
import { useParams } from "react-router-dom";
import { FiMapPin, FiPhone, FiClock, FiDollarSign, FiKey } from "react-icons/fi";
import { approxRange, estimateOf } from "../../utils/estimates";
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
import RoomTypeList from "../../components/detail/RoomTypeList";
import RoomCard from "../../components/rooms/RoomCard";
import RoomDetailsModal from "../../components/rooms/RoomDetailsModal";

export default function AccommodationDetail() {
  const { id } = useParams();
  const [item, setItem] = useState(null);
  const [loading, setLoading] = useState(true);
  const [selectedRoom, setSelectedRoom] = useState(null);
  const closeRoom = useCallback(() => setSelectedRoom(null), []);

  const load = () =>
    getAccommodation(id)
      .then(setItem)
      .finally(() => setLoading(false));

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id]);

  if (loading) return <LoadingSpinner size="lg" />;

  if (!item) {
    return <div className="p-6 sm:p-8 text-center">Accommodation not found</div>;
  }

  return (
    <div className="bg-gray-50 min-h-screen">
      <DetailHero
        backTo="/accommodations"
        backLabel="Back to Accommodations"
        badge={item.type}
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
              { icon: FiClock, label: "Operating Hours", value: item.operating_hours || "Not specified" },
              {
                icon: FiDollarSign,
                label: "Price Range",
                value: item.price_range && (
                  <span>
                    <span className="capitalize">{item.price_range}</span>
                    {/* Real room prices take precedence: the estimate only shows when no room has one. */}
                    {estimateOf(item, "nightly_rate") && !item.rooms?.some((room) => Number(room.price) > 0) &&
                      ` · ${approxRange(estimateOf(item, "nightly_rate"), " / night")}`}
                  </span>
                ),
              },
              ...(estimateOf(item, "check_in")
                ? [{
                    icon: FiKey,
                    label: "Check-in / Check-out",
                    value: `Usually ${estimateOf(item, "check_in")} / ${estimateOf(item, "check_out")} (confirm with the property)`,
                  }]
                : []),
            ]}
            latitude={item.latitude}
            longitude={item.longitude}
            website={item.website}
          />
        }
      >
        <DetailSection title="About this place">
          <p className="text-gray-700 leading-relaxed whitespace-pre-line">{item.description}</p>
        </DetailSection>

        <DetailSection
          title={item.rooms?.length > 0 || !item.room_types?.length ? "Rooms" : "Room Types"}
          subtitle={item.rooms?.length > 0 ? "Select a room to see its photos, features and details." : undefined}
        >
          {item.rooms?.length > 0 ? (
            <>
              {/* Compact cards; a click opens that room's full details. */}
              <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-5">
                {item.rooms.map((room) => (
                  <RoomCard key={room.id} room={room} onOpen={setSelectedRoom} />
                ))}
              </div>
              <p className="mt-5 text-xs text-gray-500">
                Room occupancy is updated manually by the property owner and is for information only. Contact the
                property directly to confirm availability and arrange a stay.
              </p>

              {selectedRoom && <RoomDetailsModal key={selectedRoom.id} room={selectedRoom} onClose={closeRoom} />}
            </>
          ) : item.room_types?.length > 0 ? (
            <>
              <RoomTypeList types={item.room_types} />
              <p className="mt-5 text-xs text-gray-500">
                The room types this property offers. Rates shown are as published and may change; availability isn't
                confirmed. Contact the property directly to book.
              </p>
            </>
          ) : (
            <AmenityGrid
              emptyTitle="Room information coming soon"
              emptyText="This accommodation has not published its room information yet. Please contact the property directly for room types, rates, and availability."
            />
          )}
        </DetailSection>

        <DetailSection title="Amenities & Facilities">
          <AmenityGrid
            items={item.amenities || []}
            emptyTitle="Amenities information coming soon"
            emptyText="This property has not provided its amenities yet. Please contact the accommodation for details."
          />
        </DetailSection>

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
