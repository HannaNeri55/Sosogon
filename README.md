# Sorsogon Tourism Information System

A tourism website built for the Bulan Tourism Office. Visitors can browse Sorsogon's destinations, accommodations, food and beverage places, local shops and events, and generate a day-by-day trip itinerary. Business owners manage their own listings, and Tourism Office staff review and publish everything through an admin panel.

| Part | Folder | Built with |
|------|--------|------------|
| API (backend) | [`backend/`](backend/) | Laravel 12 (PHP 8.2+), Sanctum tokens, MySQL in production / SQLite locally |
| Website (frontend) | [`frontend/`](frontend/) | React 19, Vite, Tailwind CSS, Leaflet maps |

## Who uses it

- **Tourists** (or guests with no account) browse listings, search, write reviews, and generate, save, print and share itineraries. Guests get a preview of an itinerary and log in to keep it.
- **Business owners** register with their business permit. Once the Tourism Office approves them, they manage one accommodation, food place or shop, including its rooms, menu or products. New listings and later edits go to the admin for review before they're public.
- **Admins (Tourism Office)** publish destinations, events, announcements and homepage slides. They approve owners, listings and changes, handle account warnings, suspensions and appeals, and view reports.

## Running it on your computer

You need PHP 8.2+ with Composer, and Node.js 22+.

### Backend (http://localhost:8000)

```bash
cd backend
composer install
cp .env.example .env            # then fill in the values below
php artisan key:generate
php artisan migrate --seed      # creates the tables and the sample data
php artisan storage:link        # makes uploaded photos viewable
php artisan serve
```

In `backend/.env`:

- `ADMIN_SEED_PASSWORD`: the password the seeder gives the local admin account. Set it before running `--seed`.
- `GEMINI_API_KEY`: needed for itinerary generation (`AI_PROVIDER=gemini`). Claude is also supported (`AI_PROVIDER=claude` with `ANTHROPIC_API_KEY`). Both cost credits, so keep the daily limits (`ITINERARY_DAILY_LIMIT`, `ITINERARY_GUEST_DAILY_LIMIT`) low.

Emails (password reset, listing status, account notices) are sent right away; no queue worker is needed. To send through Gmail, set these in `backend/.env`:

- `MAIL_USERNAME`: the Gmail address the emails come from.
- `MAIL_PASSWORD`: a Gmail App Password for that account. You can create one under Google Account → Security → 2-Step Verification → App passwords. It is not the normal Gmail password.

With `MAIL_MAILER=log`, emails are written to `storage/logs/laravel.log` instead of being sent. A failed send is logged there as `Failed to send ... email`, together with the SMTP error.

### Frontend (http://localhost:5173)

```bash
cd frontend
npm install
npm run dev
```

Leave the URLs in `frontend/.env` empty locally. The dev server forwards `/api` and `/storage` to the backend on port 8000.

## Tests and checks

```bash
cd backend && php artisan test     # feature and unit tests (in-memory SQLite, AI is always mocked)
cd frontend && npm run lint        # code checks
cd frontend && npm run build       # production build
```

GitHub Actions runs all three on every push and pull request ([`.github/workflows/ci.yml`](.github/workflows/ci.yml)).

## Deployment

| Piece | Service | Notes |
|-------|---------|-------|
| Website | Vercel | Set `VITE_API_URL`, `VITE_STORAGE_URL` and `VITE_SITE_URL` (see [`frontend/.env.example`](frontend/.env.example)). |
| API | Render (Docker, [`backend/Dockerfile`](backend/Dockerfile)) | On start it caches config and runs `migrate --force`. It never seeds. |
| Database | Aiven MySQL | The CA certificate is uploaded to Render as a secret file. |
| Photos and permits | Cloudflare R2 | One public bucket for photos, one private bucket for business permits. |
| Email | Brevo (HTTPS API) | Render's free plan blocks SMTP ports. |

All production settings are listed in the **PRODUCTION** section of [`backend/.env.example`](backend/.env.example). Set them in the hosts' dashboards, never in a committed file.

### Scheduled tasks

[`backend/routes/console.php`](backend/routes/console.php) schedules two jobs: pruning expired login tokens (daily) and ending suspensions whose time is up (hourly). They run only where something calls `php artisan schedule:run` every minute. To enable them on Render, add a Cron Job that runs that command. Until then, nothing breaks: logging in already clears that user's expired tokens and lifts a suspension that has ended.
