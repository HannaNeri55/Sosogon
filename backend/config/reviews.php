<?php

return [
    /*
     * Whether seeded demo reviews (ratings.is_demo = true) are shown on public
     * pages and counted in public averages. Defaults to off in production, so
     * demonstration data can never pass as genuine visitor feedback there.
     * After changing it, run: php artisan ratings:recalculate
     */
    'show_demo' => (bool) env('SHOW_DEMO_REVIEWS', env('APP_ENV', 'production') !== 'production'),
];
