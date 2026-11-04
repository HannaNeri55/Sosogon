<?php

/*
 * "Nearby" sections on the destination detail page: businesses are ranked by
 * straight-line distance from the destination's stored coordinates.
 */
return [
    // Only businesses within this many kilometers are shown.
    'radius_km' => (float) env('NEARBY_RADIUS_KM', 10),

    // Maximum cards per section (accommodations, food & beverage, shops).
    'limit' => (int) env('NEARBY_LIMIT', 5),
];
