// frontend/src/components/common/NearbyPlaces.jsx
import NearbySection from "./NearbySection";

/**
 * The four "nearby" sections of a detail page, fed by the API's nearby_* lists
 * (only places within nearby_radius_km, nearest first). `kind` names the page's
 * own listing type so its section reads "Other … Nearby".
 */
export default function NearbyPlaces({ data, name, kind }) {
  const radiusKm = data?.nearby_radius_km ?? 10;
  const empty = (noun) =>
    data?.has_coordinates === false
      ? `Nearby ${noun} can't be shown because this place's map location hasn't been set.`
      : `No ${noun} found within ${radiusKm} km.`;
  const other = (type, title) => (kind === type ? `Other ${title}` : title);

  return (
    <>
      <NearbySection
        title={other("destination", "Nearby Destinations")}
        items={data?.nearby_destinations}
        basePath="/destinations"
        intro={`Tourist spots within ${radiusKm} km of ${name}.`}
        typeLabel={(item) => item.category?.name || "Tourist Attraction"}
      />

      <NearbySection
        title={other("accommodation", "Nearby Hotels & Accommodations")}
        emptyMessage={empty("other accommodations")}
        items={data?.nearby_accommodations}
        basePath="/accommodations"
        intro={`Places to stay within ${radiusKm} km of ${name}.`}
        typeLabel={(item) => item.type}
      />

      <NearbySection
        title={other("food", "Nearby Food & Beverage")}
        emptyMessage={empty("food & beverage places")}
        items={data?.nearby_food_beverages}
        basePath="/food-beverage"
        intro={`Places to eat within ${radiusKm} km of ${name}.`}
        typeLabel={(item) => item.cuisine_type}
      />

      <NearbySection
        title={other("shop", "Nearby Souvenir Shops")}
        emptyMessage={empty("souvenir shops")}
        items={data?.nearby_retail_shops}
        basePath="/shops"
        intro={`Pasalubong shops within ${radiusKm} km of ${name}.`}
        typeLabel={() => "Pasalubong Shop"}
      />
    </>
  );
}
