# VulcaTrack -- Application

**VulcaTrack: Sales and Inventory with On-the-Go Services.**
Local development runs on XAMPP (Apache + PHP + MariaDB).

## Status

**Phase 4 complete -- customer-side functionality.**
Implemented on top of the Phase 3 auth system: customer dashboard, profile
(name / contact number / password), saved vehicles (add / edit / soft-delete /
restore), On-the-Go rescue-request submission with a one-time route + frozen ETA,
and the customer request history + status views.

A **Phase 4.5 stabilization pass** (2026-09-06) then added a committed test
harness (`tests/`) and fixed documentation drift. A focused **Phase 4
enhancement** (2026-09-06, Decisions 58-59) then added **landmark / address
search** to Book-a-Rescue alongside browser geolocation, with draggable-marker
map confirmation -- no schema change (`service_requests` lat/lng/eta unchanged).

Not yet implemented (later phases): admin OTG request handling (accept / reject /
assign a Tireman / complete), POS, inventory, reports, admin dashboard. Do not add
these until the relevant phase is explicitly approved.

## Design / decision documents

Kept separately in `C:\IPT102\docs\`. The Project Decision Record
(`docs/decisions/project-decisions.md`) is authoritative for all confirmed decisions;
the database design source of truth is `docs/ERD/schema.dbml`.

## Local setup

1. Start **Apache** and **MySQL** from the XAMPP Control Panel.
2. If `config/config.php` is missing:
   `copy config\config.example.php config\config.php` and adjust values.
3. Build the database schema (Phase 2) if it is not present:
   `C:\xampp\mysql\bin\mysql -u root vulcatrack < database\schema.sql`
4. Create your first admin account (see below).
5. Open:
   - <http://localhost/vulcatrack/> -- landing page (shows auth status)
   - <http://localhost/vulcatrack/register.php> -- customer registration
   - <http://localhost/vulcatrack/login.php> -- customer login
   - <http://localhost/vulcatrack/admin/login.php> -- admin login
   - <http://localhost/vulcatrack/health.php> -- environment + database check

## Creating an admin account

There is **no public admin registration** (Decision 18/40). Admins are provisioned
from the command line:

```
php vulcatrack/database/seed_admin.php
```

The script is CLI-only (it refuses to run over the web), prompts for full name,
email and password, enforces the 8-character minimum, hashes the password with
`password_hash()`, and inserts one row into `admins`. It never prints or logs the
password. **Do not commit real admin credentials.**

## Authentication / authorization

| Concern | Implementation |
|---|---|
| Passwords | `password_hash($p, PASSWORD_DEFAULT)` / `password_verify()`; opportunistic `password_needs_rehash()` on login. Never stored in plain text. |
| Identifier | Email only (unique within `customers`, and independently within `admins`). |
| Sessions | Hardened in `includes/bootstrap.php`: custom name, `HttpOnly`, `SameSite=Lax`, `Secure` when `session.cookie_secure` is true (HTTPS), `use_strict_mode`, `use_only_cookies`, cookie path `/vulcatrack/`. Session id regenerated on login and logout. |
| Session contents | actor type, actor id, display name, login timestamp, last-activity timestamp. Nothing sensitive. |
| Idle timeout | `session.idle_timeout` in config (default 1800s / 30 min). Sliding window -- resets on authenticated activity; no absolute cap. |
| Guards | `require_customer()` / `require_admin()` in `includes/auth.php`. A customer session never satisfies the admin guard and vice-versa. |
| CSRF | Per-session token (`src/Auth/Csrf.php`), `hash_equals()` check on every auth POST. Logout is POST-only + CSRF-protected. |
| Enumeration | Generic "Invalid email or password"; dummy `password_verify()` when the account does not exist. |
| Data access | `src/Repository/*` -- prepared statements only. Duplicate email caught via the DB unique constraint (SQLSTATE 23000 / 1062). |

**Not in scope (by decision):** Remember Me / persistent tokens, password reset,
email verification, 2FA, CAPTCHA, account lockout / rate limiting, Tireman/Staff login.

## Customer functionality (Phase 4)

| Page | Notes |
|---|---|
| `customer/dashboard.php` | Home: vehicle count, open-request count, latest request, "Book a Rescue" CTA |
| `customer/profile.php` | Edit full name + contact number (mandatory); change password (current + new). Email is the login id and is read-only in v1. |
| `customer/vehicles.php` | List active vehicles; **soft-delete** (`is_active = 0`) and restore. Removed vehicles stay on past requests. |
| `customer/vehicle-edit.php` | Add (`?id` absent) / edit (`?id=N`, ownership-checked). `plate_number` required; type/make/model optional. |
| `customer/rescue.php` | OTG submission: pick an active vehicle, describe the problem, set the location by **browser geolocation** or **landmark/address search**, then confirm on the map (the marker is draggable — its final position wins). ETA is computed **once** here and stored frozen. Request is always created `status = 'pending'`. |
| `customer/geocode.php` | Landmark/address search endpoint (POST, customer-auth, CSRF). Server-side proxy to OpenStreetMap Nominatim — identifying `User-Agent`, ≥ 1 req/sec app-wide, cached identical queries, explicit-Search only (no autocomplete), PH + Bulacan bias. Returns a small JSON list of `{label, latitude, longitude}`. Provider data is re-validated and escaped. `geocoding.driver = 'none'` runs it offline from a local list. |
| `customer/bookings.php` | Request history (read-only list, newest first). |
| `customer/booking.php?id=N` | Customer-facing status: frozen ETA, straight-line route map, and -- once an admin assigns one -- the Tireman's name + contact ("Tireman is on the way"). `?new=1` shows the submission confirmation. |

**On-the-Go rules honoured:** account required; contact number mandatory;
location set once (browser geolocation **or** landmark/address search, then a
draggable-marker confirmation) and stored as `latitude`/`longitude`; **no live
tracking**; ETA is a frozen snapshot (`Geo::etaMinutes()` = straight-line
distance / `otg.average_speed_kmph`, floored at `otg.min_eta_minutes`) written
once and never recomputed; **no route polyline persisted** (the map line is
redrawn client-side from the two stored endpoints); statuses stay exactly
`pending / accepted / rejected / completed`. Landmark search is geocoding only
(place name -> coordinates); it is **not** a routing API and does not change the
ETA method.

The map uses **Leaflet** (vendored at `assets/lib/leaflet/`, no build step) with
OpenStreetMap tiles. It degrades gracefully: if Leaflet or the tiles fail to
load, geolocation + manual coordinate entry still work and the status page shows
the coordinates with an "open map" link.

**Shop location:** `config/shop.php` holds the **real shop location** -- Gerald
Tabayag Vulcanizing Shop, 504 San Jose St. Baliwag, Bulacan
(`14.946654430279454` / `120.89290174619997`). It is a config value only, not a
database table (Decision 37); route/ETA code reads from here.

## Structure

| Path | Purpose |
|---|---|
| `index.php` | Landing page + auth-status strip |
| `register.php`, `login.php`, `logout.php` | Customer auth entry points |
| `account.php` | Redirects to `customer/dashboard.php` (back-compat) |
| `customer/` | Signed-in customer pages (guarded by `require_customer()`) |
| `admin/login.php`, `admin/logout.php`, `admin/index.php` | Admin auth entry points + guarded placeholder |
| `health.php` | Environment + DB connectivity check |
| `config/` | Local configuration -- **not web-accessible** (`config.php` git-ignored) |
| `config/shop.php` | Fixed shop location (Decision 37) -- the real Baliwag shop coordinates |
| `includes/` | `bootstrap.php`, `db.php`, `auth.php` -- **not web-accessible** |
| `src/Auth/` | `Auth.php` (session/actor lifecycle), `Password.php`, `Csrf.php` |
| `src/Repository/` | `CustomerRepository`, `AdminRepository`, `VehicleRepository`, `ServiceRequestRepository` -- prepared statements, customer-scoped |
| `src/Support/` | `Validator.php`, `Geo.php` (haversine + frozen ETA), `OtgStatus.php` (status→label mapping), `Geocoder.php` + `NominatimGeocoder.php` / `ArrayGeocoder.php` / `GeocoderFactory.php` / `GeocodeResult.php` / `GeocodeException.php`, `GeocodeCache.php` (query cache + ≥1s throttle) |
| `src/Views/` | Form templates + shared partials (`partials/customer_top.php` app shell) |
| `assets/` | `css/app.css`, `js/otg-map.js`, `lib/leaflet/` (vendored), `img/` |
| `database/` | `schema.sql`, `seed_admin.php` -- **not web-accessible** |
| `storage/` | Logs / generated files -- **not web-accessible** |
| `tests/` | Dependency-free regression harness -- **not web-accessible** |

## Tests

```
C:\xampp\php\php.exe vulcatrack/tests/run.php            # all suites
C:\xampp\php\php.exe vulcatrack/tests/run.php unit       # unit only (no DB needed)
```

No Composer, no PHPUnit. `run.php` discovers `tests/*/*Test.php`, runs each case
isolated, promotes PHP warnings/notices to failures, and exits non-zero on any
failure. The `integration` and `http` suites need MariaDB running and the schema
built; every DB test rolls back its writes. See `tests/README.md`.

## Verified environment

| Component | Version |
|---|---|
| Apache | 2.4 (Win64), port 80 |
| PHP | 8.0.30 (Apache module + CLI) |
| MariaDB | 10.4.32, port 3306, user `root`, no password |
| phpMyAdmin | <http://localhost/phpmyadmin/> |
| Web root | `C:\xampp\htdocs` (junction to `C:\IPT102\vulcatrack`) |

## Stack

PHP 8.0 &middot; Apache 2.4 &middot; MariaDB 10.4 &middot; vanilla HTML / CSS / JS
