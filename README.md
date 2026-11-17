# Pixelforge Studio — AI Image Generation Workspace

A full-stack AI image generation workspace built with **Laravel 11**, **Vue 3**, **Inertia.js**, and **Tailwind CSS**, integrated with the **Pixelforge API**. Every monetary calculation uses **PHP BCMath** (and integer-scaled `BigInt` on the frontend) with 9-decimal precision (`DECIMAL(18, 9)`) to avoid floating-point drift.

---

## Key Features

- **Real-Time Token & Cost Estimation**: Debounced live pricing preview via `POST /generations/estimate` using cached rates from `GET /v1/pricing` (5-minute TTL with documented fallback rates), supporting `low`, `medium`, and `high` quality tiers with a transparent **25% service markup** and reasoning token ranges.
- **Credit Wallet & Instant Top-Ups**: New users start with **$10.00** in free studio credits, validated before job submission against the maximum estimated user cost, with instant simulated top-ups (`$5.00`, `$10.00`, `$25.00`).
- **Text-to-Image & Reference Images**: Supports `1024x1024`, `1536x1024`, and `1024x1536` aspect ratios plus up to 4 optional reference image URLs (`reference_images`), along with quick-insert Pixelforge testing directive tags (`[slow]`, `[fail]`, `[duplicate]`, `[blocked]`, `[overloaded]`, `[succeed]`).
- **HMAC-Verified Webhooks & Live Polling Fallback**:
  - `POST /webhooks/pixelforge` verifies `Pixelforge-Signature` (`t=<timestamp>,v1=<hmac>`) in constant time with a 300-second replay window.
  - Idempotent event deduplication (`webhook_events`), row-level locking (`lockForUpdate()`), strict state transitions (`pending` → `queued` → `processing` → `succeeded`/`failed`), and race-condition resolution via `metadata.local_generation_id`.
  - Automatic frontend polling (`POST /generations/{generation}/sync`) every 4 seconds for active jobs plus a manual **Sync Status** action.
- **Zero Charge on Failed Generations**: Failed or rejected jobs always settle at `$0.000000000` (`actual_user_cost = 0`) and never deduct user credits.
- **Operator Financial & Reconciliation Report**: Accessible at `GET /admin/report` (the first registered user is automatically assigned `is_admin = true`), aggregating user spend, provider cost, and net profit daily and per user, with live reconciliation against Pixelforge `GET /v1/usage`.

---

## Tech Stack

- **Backend**: Laravel 11, PHP 8.2+ (`bcmath`, `pdo_sqlite`), Laravel Breeze
- **Frontend**: Vue 3, Inertia.js v2, Tailwind CSS, Vite
- **Precision & Ledger**: PHP `bcmath` (`scale = 9`) & `DECIMAL(18, 9)` columns
- **Database**: SQLite (default) or MySQL / PostgreSQL
- **Deployment**: Multi-stage `Dockerfile` (Composer + Node 20 Vite build + PHP 8.3 FPM + Nginx)

---

## Local Setup & Installation

### Prerequisites

- PHP 8.2+ with `bcmath`, `pdo_sqlite`, `mbstring`, `xml`, `curl`, `zip`, `intl` extensions
- Composer 2.x
- Node.js 20+ & npm

### Steps

```bash
# 1. Clone the repository
git clone https://github.com/amirrdn/ai-image-studio.git
cd ai-image-studio

# 2. Install PHP and JavaScript dependencies
composer install
npm install

# 3. Configure environment
cp .env.example .env
php artisan key:generate

# 4. Prepare SQLite database and run migrations
touch database/database.sqlite
php artisan migrate

# 5. Build frontend assets (or run `npm run dev` for HMR)
npm run build

# 6. Start the application server
php artisan serve
```

If you configure asynchronous queue workers (`QUEUE_CONNECTION=database`), also run:

```bash
php artisan queue:work
```

For local development with Vite hot-reload, run `npm run dev` alongside `php artisan serve` (or use `composer run dev`).

### Production Docker Deployment

A multi-stage `Dockerfile` (with `docker/nginx.conf` and `docker/entrypoint.sh`) is included for platforms such as Render, Railway, or Fly.io. On startup, the container prepares `database/database.sqlite`, runs `php artisan migrate --force`, caches configuration/routes/views, and serves the app over `${PORT:-8080}`.

```bash
docker build -t pixelforge-studio .
docker run -p 8080:8080 \
  -e APP_KEY="base64:..." \
  -e PIXELFORGE_BASE_URL="https://pixelforge.promptedit.dev" \
  -e PIXELFORGE_API_KEY="your_api_key" \
  -e PIXELFORGE_WEBHOOK_SECRET="whsec_your_secret" \
  pixelforge-studio
```

---

## Environment Variables Reference

| Variable | Default / Example | Description |
| --- | --- | --- |
| `APP_NAME` | `Laravel` | Application name displayed in titles |
| `APP_URL` | `http://localhost` | Public base URL of the application |
| `DB_CONNECTION` | `sqlite` | Database driver (`sqlite`, `mysql`, `pgsql`) |
| `DB_DATABASE` | `database/database.sqlite` | Path to SQLite file or database name |
| `PIXELFORGE_BASE_URL` | `https://pixelforge.promptedit.dev` | Base URL for Pixelforge REST API |
| `PIXELFORGE_API_KEY` | *(required)* | Bearer API key for Pixelforge endpoints |
| `PIXELFORGE_WEBHOOK_SECRET` | *(required)* | Signing secret (`whsec_...`) for `Pixelforge-Signature` verification |

---

## Running Tests

Run the full PHPUnit unit and feature test suite (covers BCMath pricing & markup calculations, Pixelforge API error handling, webhook HMAC/replay/idempotency/out-of-order/race-condition flows, balance blocking, and admin reconciliation):

```bash
php artisan test
```
