# WFOT General Assembly – AI Guide

## Big Picture
- PHP 8 + Composer app that brokers registration/booking data between Airtable and WFOT web properties; every entry script includes `src/bootstrap.php` for autoloading, dotenv setup, error policy, and CSRF/session helpers.
- Data lives entirely in Airtable tables; local storage is limited to generated PDFs inside `storage/confirmations` plus transient logs.
- `vendor/` is committed; do not touch upstream vendor files unless explicitly asked.

## Data + Services
- `src/Services/AirtableService.php` wraps `sleiman/airtable-php` and is the only place that should talk to Airtable; repositories (`src/Repository/*.php`) pin table IDs (`Bookings` tblETcytPcj835rb0, `Registrations` tblxFb5zXR3ZKtaw9, `Bookable Items` tblT0M8sYqgHq6Tsa, `Booked Items` tbluEJs6UHGhLbvJX, `Members` tblDDpToTMCxgrHBw).
- API keys and behavior toggles arrive via `.env`; required keys include `AIRTABLE_*`, `APP_URL`, `TOKEN_SALT`, `SMTP_*`, `STRIPE_*`, `PAYPAL_*`, `API_BEARER_TOKEN`, and `DEBUG`.
- Keep env lookups behind the global `env()` helper so CLI scripts and web front-ends behave consistently.

## Booking Flow (`public/bookings`)
- `index.php` accepts either `?registration=...&tok=...` (token required) or `?booking=...`; it uses `RegistrationRepository`, `BookingRepository`, and `ItemRepository` to hydrate state and decide between edit form (`templates/booking_form.php`) or confirmation PDF/HTML (`templates/booking_complete.php`).
- When a booking is (re)generated it produces PDFs via `WFOT\Services\PdfService`, stores them under `storage/confirmations/{bookingId}.pdf`, writes the download URL back to Airtable, and emails attendees through `EmailService`.
- QR codes (`QrCodeService`) embed the booking ID in the confirmation output; keep this flow intact when adding new badges or check-in features.

## Saving Bookings & Payments
- The form posts to `public/bookings/save-booking.php` (AJAX only); the handler enforces CSRF tokens, sanitizes IDs, clears existing `Booked Items` records, recreates them, updates `Subtotal`/`Total`, and branches by payment total.
- Stripe logic lives in `src/Services/StripeService.php`; `save-booking.php` only ever supplies arrays of `{name, amount}` line items to `createCheckoutSession`. Keep metadata updates (`booking_id`) in sync with `public/bookings/stripe/webhook.php` and `capture-order.php` because downstream automation depends on those fields.
- Webhook handler updates Airtable, generates confirmation tokens via `TokenService`, and reuses `EmailService::sendConfirmation`; any new payment state must be reflected in that webhook to keep the WordPress booking widget accurate.

## Registration Flow (`public/registration`)
- `registration/index.php` is the hub the Delegates Hub calls; it accepts `person` (member record ID) or `observer` email plus `meeting` and optionally responds as JSON (`response=json`).
- The script enforces role checks against `ALLOWED_MEMBER_ROLES`, scopes Airtable queries via `getMeetingConfig()`, and either redirects users to existing Airtable forms or creates new registration records (`createRegistration`).
- JSON output feeds the WP widget (`wp-content/booking-widget.js`), so any schema changes (e.g., renaming `booking.status`) must keep backward compatibility or version gating.

## Token + Security Conventions
- All deep links use `WFOT\Services\TokenService` (HMAC over record IDs with `TOKEN_SALT`). Booking PDFs (`bookings/confirmation.php`) and registration launch URLs both validate tokens; never expose raw Airtable IDs without an accompanying token.
- CSRF helpers from `src/bootstrap.php` are required on every POST endpoint (`save-booking.php`, `stripe/capture-order.php`, etc.). If you add a new form, drop `generateCsrfToken()` into the markup and `validateCsrfToken()` into the handler.
- `public/api/generate-booking-link.php` is a bearer-protected endpoint used by WordPress (`wfot_get_booking_link` AJAX); preserve the Authorization header contract when refactoring.

## Front-end Patterns
- Booking UI (`public/bookings/assets/js/booking.js`) is vanilla jQuery: it recalculates totals client-side, toggles payment UI, and expects JSON responses shaped like `{payment: 'Stripe'|'Cash'|'None', checkout_url?, booking_id}`.
- Templates live in `/templates` and are pure PHP snippets that rely on scoped variables; avoid introducing heavy frameworks and keep any shared markup in header/footer partials.
- WordPress surfaces bookings through `wp-content/booking-widget.js`, which calls `/registration` for status and `/wp-admin/admin-ajax.php?action=wfot_get_booking_link` to proxy `public/api/generate-booking-link.php`.

## Local Dev & Troubleshooting
- Install deps with `composer install`; run the site via `php -S localhost:8000 -t public` or your preferred web server that points to `/public`.
- Provide a `.env` (copy from ops) and ensure `storage/confirmations` plus `storage/logs` are writable by PHP; PDFs will not generate otherwise.
- Use the built-in DEBUG flag for verbose logging—services log to the PHP error log, so tail `storage/logs/*.log` or your web server log when chasing Airtable/Stripe issues.
- There is no automated test suite; verify changes by exercising `/registration` JSON responses, the booking form, Stripe webhooks (use the CLI forwarder), and PDF/email generation paths manually.
