# Backend (Laravel API)

The API for the Sorsogon Tourism Information System. Setup, tests and deployment are described in the [main README](../README.md).

- Routes: [`routes/api.php`](routes/api.php) (everything is under `/api/v1`)
- Business logic: [`app/Services/`](app/Services/), for example `ItineraryService` for itinerary generation
- Tests: [`tests/Feature/`](tests/Feature/), run with `php artisan test`
