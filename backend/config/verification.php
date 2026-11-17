<?php

return [
    /*
    | Days after which a check of each part of a listing's information is overdue. These are the
    | defaults; the Tourism Office changes them on the admin side (site_settings
    | 'verification.overdue_days'), which takes precedence. Prices change most often.
    */
    'overdue_days' => [
        'price' => 30,
        'hours' => 90,
        'contact' => 90,
        'location' => 180,
    ],
];
