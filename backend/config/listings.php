<?php

return [
    /*
     * Whether sample (demonstration) business listings - is_sample = true on
     * accommodations, food_beverages and retail_shops - appear on public pages,
     * search, nearby places and itineraries. Defaults to off in production, so
     * unverified demo businesses can never pass as real ones there.
     */
    'show_samples' => (bool) env('SHOW_SAMPLE_LISTINGS', env('APP_ENV', 'production') !== 'production'),
];
