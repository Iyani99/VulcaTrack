# VulcaTrack — Project Decision Record

**Status:** Authoritative record of CONFIRMED project decisions.
**Last updated:** 2026-09-06
**Last revised:** 2026-09-06 — Phase 4.5 stabilization; Phase 5 pre-decisions 49–57;
Rescue location selection 58–59; road-routing approved-but-deferred (Decision 60)
(see [Revision History](#revision-history)).
**Purpose:** This file exists so that a completely new session (human or Claude Code) can
understand the project's confirmed decisions, scope boundaries, and change-control rules
**without** relying on conversation history or any assistant's persistent memory.

If anything in this file conflicts with another artifact, see
[Artifact Authority / Change-Control Rule](#artifact-authority--change-control-rule) and
[Known Conflicts / Clarifications](#known-conflicts--clarifications). Do **not** silently
reconcile artifacts.

---

## Project Identity

**Approved and LOCKED title:**

> **VulcaTrack: Sales and Inventory with On-the-Go Services**

This title is final and supersedes all earlier title variants. Do not revert to or
introduce alternative titles.

**What VulcaTrack is:** a student, web-based system for a vulcanizing / tire shop. Its
scope covers exactly these areas:

1. Customer / public-facing functionality
2. Customer accounts and vehicles
3. In-shop sales and inventory
4. On-the-Go (OTG) roadside service requests

Nothing beyond these four areas is in scope unless explicitly approved later.

---

## Technology

| Layer | Choice |
|---|---|
| Backend | PHP 8.0 (local: PHP 8.0.30, XAMPP) |
| Database | MySQL-compatible — local is **MariaDB 10.4.32** (XAMPP) |
| Web server | Apache 2.4 (XAMPP) |
| Frontend | HTML5, CSS3, JavaScript; **Vue** only where component behaviour genuinely benefits (not a full SPA) |
| Maps | Leaflet + OpenStreetMap (vendored, no build step) |

Locked: do **not** introduce Laravel, React, a Node.js runtime, Firebase, or
PostgreSQL without explicit owner approval.

**Figma** is the team's primary reference for UI/UX and visual/interface behavior. The
Figma prototype lives **outside** the repo application and is provided/accessed separately.
*(The local folder is `C:\IPT102`; the GitHub remote is
`https://github.com/Iyani99/VulcaTrack.git`.)*

---

## Confirmed Project Decisions

These are settled. Treat them as binding unless a later, explicitly approved decision
changes them.

### On-the-Go (OTG) service requests

1. **OTG requests require an authenticated customer account.** Anonymous OTG submission is
   not allowed.
2. **Customer cellphone / contact number is mandatory.** The system must hold a reliable
   customer contact number so the shop and customer can communicate directly when needed.
   There is no in-app messaging.
3. **OTG requests capture the customer's location via browser/device geolocation.** The
   system stores latitude and longitude for the request.
4. **No live technician GPS tracking.** There is no continuous technician location feed, no
   location history, and no live moving technician marker.
5. **Route and ETA are calculated when the request is made,** using the customer's shared
   location and the shop's location.
6. **The route and ETA are a one-time snapshot** taken at request time. The ETA does not
   continuously update based on technician movement.
7. **The accepted-request state may display "Tireman is on the way".** This is UI / status
   wording only. It does not represent live GPS tracking and does not require a separate
   technician-tracking entity.
8. **The customer can view the route/map and ETA** associated with their request.
9. **The admin can view the customer's location and route** when handling an OTG request.
10. **OTG request statuses are:** `pending`, `accepted`, `rejected`, `completed`.
11. **"Tireman is on the way" is customer-facing wording for the `accepted` state** — not a
    separate database status, unless a future approved decision changes this.

### Sales and inventory

12. **GCash / online payment is PARKED / OUT OF SCOPE.** Do not implement GCash
    integration, online payment processing, payment gateway logic, payment tables, payment
    fields, or payment APIs unless the team explicitly approves this later.
13. **Sales are handled by an admin in the shop.** There is no online customer shopping
    checkout.
14. **Walk-in customers must be supported.** A sale may exist without a registered customer
    account; therefore `sales.customer_id` may be nullable.
15. **Products and services share one unified inventory/`items` table,** distinguished by an
    `item_type` value.
16. **Product sales reduce inventory stock.** Service items do not use stock deduction.
17. **Sale-item unit price is stored/frozen at the time of sale,** so historical
    transactions are not altered when an item's current price later changes.

### Accounts and roles

18. **Admin accounts are internally provisioned.** There must be no public "Sign Up as
    Admin" or self-service admin registration flow.
19. **There is one internal Admin role** for the current project scope. Do not invent a
    separate Staff / Technician **login role** unless explicitly approved later.
    *(The `tiremen` table added by Decision 22 is a non-login service-provider record, not
    a role — it grants no system access.)*
20. **"Tireman" is customer-facing terminology/label,** not a user role or login.
    *(Refined by Decision 22: a minimal non-login `tiremen` identity table is now in
    scope.)*

### Configuration

21. **Shop location is currently treated as a fixed application configuration value,** not a
    separate database table.

---

## Confirmed Project Decisions — 2026-08-31 Review

Added during the Decision Review & Documentation Update. These are settled.

### Tiremen (OTG service personnel)

22. **A minimal `tiremen` table is confirmed.** "Tireman" remains customer-facing
    terminology and is **not** a system role — Tiremen have **no login and no dashboard in
    v1**. But because the customer-facing OTG screen shows the assigned Tireman's name and
    contact number, the system keeps a simple record of the people who perform OTG
    services. This refines Decision 20 (which had said "Tireman" would not automatically
    become a database entity).
23. **`tiremen` columns are minimal:** `tireman_id` (PK), `name`, `contact_number`,
    `is_active`, `created_at`, `updated_at`. Nothing else in v1.
24. **Admin manages Tiremen:** add a Tireman, edit Tireman information, view Tiremen,
    activate/deactivate a Tireman, and assign an active Tireman to an OTG service request.
25. **`service_requests` gains a nullable `tireman_id`** (FK → `tiremen`), set when an admin
    assigns a Tireman to an accepted request. It stays `NULL` while a request is
    `pending`/`rejected` or accepted-but-unassigned. This is independent of
    `service_requests.admin_id` (the admin handling the request).
26. **Tiremen are identity / contact / assignment only.** No technician authentication, no
    live GPS tracking, no location telemetry/history, no schedules, no ratings, no payroll,
    no employee records. "Tireman is on the way" remains status wording for the `accepted`
    state.

> **Actor model (clarification, reaffirmed 2026-08-31):** three distinct actors —
> **Customer** (requests OTG service), **Admin** (system user who manages the system and
> handles requests), **Tireman** (person who performs the OTG service). A Tireman is a
> service-provider record only — **never** an Admin account and **never** a login/role.
> Only an Admin assigns an active Tireman to an `accepted` request via
> `service_requests.tireman_id`; the customer then sees that Tireman's `name` and
> `contact_number`. The owner reviewed the option to drop the `tiremen` table and
> **explicitly chose to keep it** (see [Revision History](#revision-history)).

### Deactivation / soft-delete

27. **`items.is_active` and `vehicles.is_active` are confirmed** —
    `TINYINT(1) NOT NULL DEFAULT 1`. Deactivation sets `is_active = 0` instead of deleting
    the row. (Resolves the two `is_active` open questions.) `tiremen.is_active` follows the
    same pattern.
28. **`is_active` filtering rules:**
    - Inactive `items` do **not** appear in POS product/service selection or the
      active-inventory list, but remain visible in historical sales (`sale_items`).
    - Inactive `vehicles` do **not** appear in active vehicle selection, but remain visible
      in historical `service_requests`.
    - Inactive `tiremen` cannot be newly assigned, but remain visible on historical
      requests they are already attached to.
    - Never hide a historical record because a referenced item/vehicle/tireman is inactive.
29. **Hard deletion stays discouraged.** Prefer `is_active = 0`. A hard delete is only
    acceptable for a row with no historical references.

### POS payment (clarifies Decision 12)

30. **Online / gateway payment is OUT OF SCOPE; in-person cash handling is supported in the
    POS UI only.** Precise wording — do **not** describe this as "all payment is out of
    scope":
    - Out of scope: GCash, payment gateways/APIs, online payment processing,
      payment-transaction tables, online-payment fields.
    - Supported (UI only): the POS calculates the sale total, lets the cashier enter the
      amount received, calculates change, and blocks completion when the amount received is
      insufficient.
    - **Not persisted in v1:** amount tendered and change due. The only monetary value
      stored for a sale is `sales.total_amount` (plus per-line `sale_items` values).
    - Correct distinction: *online/gateway payment is out of scope; in-person cash tender
      and change are UI-only and not persisted in v1.*
31. **Receipts:** if the POS flow generates a receipt, it is a simple printable
    HTML/browser receipt rendered from the saved `sales` + `sale_items` data. **No receipt
    table** in v1; a receipt "number" can just be `sale_id`.

### OTG route / ETA (clarifies Decisions 5–6)

32. **`service_requests.eta_minutes` is a frozen snapshot.** Calculated once at request
    submission from the customer's captured location and the fixed shop location, then
    stored. It is **never** recomputed or updated for display afterward. (Resolves the
    store-vs-recompute open question.)
33. **No route geometry is persisted.** The database does not store a provider-specific
    polyline/route. When a map is shown (customer or admin), the route line may be
    re-generated from the two fixed endpoints (stored request `latitude`/`longitude` → shop
    config location), but the customer-facing ETA shown must remain the stored
    `eta_minutes`. Optional columns such as `distance_km` or `route_calculated_at` are
    **not** added in v1.

### Service-request timestamps

34. **No per-status timestamp columns in v1.** `service_requests` keeps `status`,
    `requested_at`, `updated_at` only — no `accepted_at` / `completed_at` / `rejected_at`,
    and no status-history / audit table, unless a future requirement demands it.

### Sales dates

35. **`sales.sale_date` vs `sales.created_at`:** `sale_date` is the actual sale timestamp,
    **system-controlled** and not manually editable by the cashier/admin during normal POS
    completion (no backdating feature in v1). `created_at` is the database record-creation
    timestamp. Sales reports use `sale_date` as the reporting date.

### Admin module structure

36. **"Manage Inventory" is a single admin module** covering both products and services
    (unified `items` table). "Manage Products" is a sub-function of that module, **not** a
    separate top-level module/route. The module handles: add/edit items, stock management
    for physical products, low-stock monitoring, and activate/deactivate.

### Configuration (clarifies Decision 21)

37. **Shop location lives in centralized application configuration** (for example
    `config/shop.php`) holding the shop's fixed `latitude`, `longitude`, and `address`. All
    route/ETA calculations read from this single source; coordinates are never hard-coded
    throughout the app. No `shop_settings` table in v1. Making it admin-editable remains a
    future / open consideration.

### ERD source of truth

38. **The maintainable ERD source of truth is a text schema:** `docs/ERD/schema.dbml`. The
    PNG ERD (`docs/ERD/VulcaTrack-ERD_1.png`) is a visual representation only and is now
    behind the text schema. The text schema is intended to be the basis for the eventual
    MySQL implementation. For the use-case diagram, the PNG is retained and required changes
    are documented (see [Required Diagram Changes](#required-diagram-changes)) rather than
    rebuilding diagram tooling now.

### Customer → service_requests relationship (resolves C4)

39. **`customers` 1 : 0..N `service_requests`.** A customer may have zero or many service
    requests; every service request belongs to exactly one customer;
    `service_requests.customer_id` is `NOT NULL`. The earlier `1 : 1..N` notation is
    corrected — it never meant every customer must have a request.

### Admin provisioning (clarifies Decision 18)

40. **The admin provisioning mechanism may be kept simple for v1** (for example a
    pre-seeded admin row or a protected internal-only script/page), but there must be no
    unrestricted public admin self-registration. Open public registration exists for
    `customers` only.

---

## Confirmed Project Decisions — 2026-09-01 (Phase 3: Authentication & Authorization)

Owner decisions made when approving Phase 3. These resolve the corresponding
open questions. **No schema change** — the 8-table design is untouched.

41. **Customer login identifier is `email` only.** No username column, no
    username login. (Resolves the "email / username / both" open question.)
42. **Email uniqueness stays per-table.** `customers.email` is unique within
    `customers`; `admins.email` is unique within `admins`; the two are checked
    independently. One person *may* technically be both a customer and an admin
    with the same address. (Resolves the email-uniqueness-scope open question.)
43. **"Remember Me" / persistent login is deferred entirely.** No remember-token
    table, no persistent-login cookie. Authentication uses normal PHP sessions
    only. (Resolves the remember-me open question for v1; revisiting it later
    would be a new decision + a new table.)
44. **Password policy: minimum 8 characters, no composition rules.** Registration
    requires a confirmation field. Hashing/verification use `password_hash()` /
    `password_verify()` (`PASSWORD_DEFAULT`) only; `password_needs_rehash()` is
    applied opportunistically on login. Plain-text passwords are never stored.
45. **Session idle timeout: 30 minutes, sliding.** The last-activity timestamp
    is stored in the session and refreshed on authenticated activity; the
    session is invalidated after 30 minutes of inactivity. There is **no**
    absolute session lifetime. The value is configurable
    (`config.php` → `session.idle_timeout`, seconds).
46. **First-admin provisioning is a CLI-only script:** `vulcatrack/database/seed_admin.php`.
    It refuses web execution, prompts for full name / email / password, enforces
    Decision 44, hashes the password, and inserts one `admins` row. It never
    prints or logs the password. No public admin registration page exists
    (reaffirms Decisions 18/40).
47. **Two independent session actors — customer and admin — never cross.** One
    actor per browser session. `require_customer()` and `require_admin()` guard
    their own actor type only; a customer session never satisfies the admin
    guard and vice-versa. No generic role/RBAC system. Session stores only:
    actor type, actor id, display name, login timestamp, last-activity timestamp.
    Auth POST forms (register, login, logout) are CSRF-protected; logout is
    POST-only. Login failures use a generic message and a dummy
    `password_verify()` on the no-such-account path (anti-enumeration).

---

## Confirmed Project Decisions — 2026-09-01 (Phase 4: Customer-Side Functionality)

Phase 4 is implementation of already-approved scope (dashboard, profile, saved
vehicles, OTG submission, request history + status). One implementation-level
decision was needed and is recorded here; it changes no scope and no schema.

48. **OTG ETA computation method (implementation-level, reversible).** The
    one-time ETA (Decisions 5/6/32) is computed as the **straight-line
    (haversine) distance** between the customer's captured location and the fixed
    shop location (`config/shop.php`), divided by an assumed average speed
    (`config/config.php` → `otg.average_speed_kmph`, default 25), rounded up, and
    floored at `otg.min_eta_minutes` (default 5). It is written once to
    `service_requests.eta_minutes` and never recomputed. **No external routing /
    directions API** is used (avoids an API key, billing, and a runtime
    dependency). Swapping in a routing service later is a config/code change with
    no schema impact. *(The future stance on this is now settled by
    **Decision 60**: a single origin→destination road-routing call is approved as
    a deferred enhancement — road distance only, feeding this same
    `Geo::etaMinutes()` formula, with this haversine method as the mandatory
    fallback. Not implemented; not started before Phase 5.)* The map is drawn with **Leaflet** vendored at
    `vulcatrack/assets/lib/leaflet/` (OpenStreetMap tiles, graceful degradation);
    the route line is a client-side straight line between the two stored
    endpoints — **no polyline is persisted** (Decision 33).

    Phase 4 also, without needing owner decisions:
    - shows email read-only on the profile page (changing the login email is
      deferred; `CustomerRepository::emailExists()` already exists for when it is
      added);
    - requires a captured location to submit an OTG request (the feature is
      "come to my location"); denied-geolocation fallback = retry + map pin-drop
      + manual lat/long entry (exact UX still open — Known Open Questions);
    - sets `config/shop.php` away from `0.0 / 0.0` so the feature is
      demonstrable. *(Superseded 2026-09-04, commit `f2043d5`: `config/shop.php`
      now holds the **real** shop location — Gerald Tabayag Vulcanizing Shop,
      504 San Jose St. Baliwag, Bulacan, lat `14.946654430279454` / lng
      `120.89290174619997`. Still a config value only; no `shop_settings`
      table — Decision 37.)*

---

## Confirmed Project Decisions — 2026-09-06 (Phase 4.5: Phase 5 pre-decisions)

Settled by the owner ahead of Phase 5 implementation so these questions are not
reopened. They set direction only — **no code and no schema change** was made
when recording them. The Phase 2 schema is still exactly the approved 8 tables.

49. **Sales reporting / history UI is NOT part of Phase 5.** Phase 5 delivers the
    POS, inventory, the printable receipt and a minimal admin shell only. Any
    reporting or sales-history screen is deferred to **Phase 6**. Phase 5's
    `SaleRepository` *may* include receipt-read / lookup methods it needs
    internally, but no reporting page is built.

50. **Receipt = printable HTML only.** Rendered from the saved `sales` +
    `sale_items` rows (plus `config/shop.php` and the recording admin). Contents:
    - shop name; shop address;
    - `sale_id` used as the sale / receipt number;
    - sale date/time (`sales.sale_date`);
    - cashier / Admin name;
    - linked customer name if the sale has one, otherwise the literal **"Walk-in"**;
    - per line: item/service name, quantity, frozen unit price, line subtotal;
    - total;
    - a print button and print styling.
    **Not** added: a receipt table, TIN / BIR / tax fields, any tax subsystem,
    GCash / online payment / a payment table, or any "official receipt" claim.
    Reaffirms Decisions 12/30/31.

51. **Customer linking at the POS is optional and existing-only.** The cashier may
    attach an already-registered customer to a sale; blank means a walk-in and
    the sale stores `sales.customer_id = NULL`. The POS **must not** create a
    customer account (no inline registration), and linking a customer has **no**
    loyalty, pricing or payment side effect — it is only recorded. Whether an
    admin can create customer accounts through some *other* screen stays out of
    scope / open (Known Open Questions). Reaffirms Decision 14.

52. **`items.category` stays a plain nullable free-text column for Phase 5.** No
    category table is introduced in Phase 5. A datalist / suggestion list of the
    values already in use is acceptable UI later. (Whether it ever becomes its
    own table remains open beyond Phase 5.) Reaffirms Decision 15's note.

53. **A minimal Admin app shell / navigation is approved as a Phase 5
    prerequisite.** Just enough chrome to reach **Dashboard, POS, Inventory**
    (e.g. `src/Views/partials/admin_top.php` + `admin_bottom.php`, mirroring the
    existing customer shell). It must **not** ship Phase 6 features early — no
    OTG request management, no Tireman management, no reporting screens.

54. **Cash tender / change are never persisted.** The POS UI computes the sale
    total, accepts an amount tendered, computes change, and blocks completion
    when the tender is below the total. The server may receive `cash_tendered`
    **only** to re-validate it against the server-authoritative total and may
    compute change for the response/display. Neither value is written to the
    database; the only monetary values stored are `sales.total_amount` and the
    per-line `sale_items` values. Reaffirms Decision 30. There is no payment
    subsystem.

55. **Money arithmetic uses integer centavos** (or an equivalent exact method) —
    never PHP floating-point arithmetic — for line subtotals, the sale total,
    tender and change. `DECIMAL(10,2)` values read from the database are treated
    as exact and converted to integer centavos for computation.

56. **Application-layer validation limits for Phase 5:** `price >= 0`,
    `quantity > 0`, `stock_quantity >= 0` (a product sale may not drive stock
    negative). These are enforced in the service / repository layer. The
    approved Phase 2 schema is **not** modified to add new `CHECK` constraints
    unless a genuine correctness problem later forces the schema to be
    reconsidered.

57. **A session-backed POS cart is acceptable only for error recovery.** A
    temporary `$_SESSION` cart may hold the in-progress line items so the cashier
    does not lose them when a validation or insufficient-stock error re-renders
    the POS. It is not a persistent cart, not shared between sessions, and is
    cleared once the sale is committed or abandoned. No cart / cart-items table.

---

## Confirmed Project Decisions — 2026-09-06 (Phase 4 enhancement: Rescue location selection)

A focused Book-a-Rescue improvement. **No schema change** — still exactly 8
tables, four OTG statuses, `latitude` / `longitude` / `eta_minutes` unchanged.

58. **The rescue location may be chosen by browser geolocation OR by landmark /
    address search.** Both first-class; neither is "more accurate".
    - **Use my current location** — the existing browser Geolocation flow,
      unchanged. Best when the device location is working.
    - **Search landmark or address** — the customer types a place (gas station,
      mall, church, street, barangay landmark…), presses an explicit **Search**
      button, and picks from a short result list. The chosen result supplies
      latitude / longitude. Useful when geolocation is denied / unavailable /
      inaccurate, or the customer knows a nearby landmark.
    - Either way the point is shown on the existing Leaflet / OpenStreetMap map
      and **the marker is draggable**; the customer confirms before submitting.
    - **The final (possibly dragged) marker position is what is stored** — it
      overrides the initial GPS or geocoder coordinates.
    - The request is still created `status = 'pending'`; `eta_minutes` is still a
      one-time frozen snapshot computed from the stored coordinates (Decision
      48); no route geometry, no live tracking, no new statuses.

59. **Geocoding is a small replaceable layer, called only through a server-side
    endpoint.** `src/Support/Geocoder` (interface) + `NominatimGeocoder` (public
    OSM Nominatim) + `ArrayGeocoder` (offline / tests) + `GeocoderFactory`. The
    browser never calls Nominatim directly; it POSTs to
    `customer/geocode.php` (authenticated customer, CSRF-checked), which:
    - identifies the app with a real `User-Agent` (config `geocoding.user_agent`
      — the OSM policy rejects stock defaults, and a browser cannot set one);
    - enforces **≥ 1 second between outbound calls** app-wide and **caches
      identical queries** (`GeocodeCache`, files under `storage/cache/geocode/`,
      git-ignored);
    - only searches on an explicit user action — **no autocomplete / no
      per-keystroke requests** (the OSM policy forbids client-side autocomplete);
    - biases results to the Philippines + a Bulacan-area viewbox (soft bias,
      `bounded = 0`, so a legitimate request just outside still works);
    - treats every returned field as untrusted: coordinates are re-validated
      with `Geo`, labels are output-escaped.
    Swapping the provider later is a config + one-class change; `geocode.php`
    and the schema are unaffected. Attribution ("OpenStreetMap / Nominatim") is
    shown on the Rescue page. `geocoding.driver = 'none'` disables the network
    call and uses a small local landmark list instead.

    This is **not** a routing / directions / distance-matrix API and does not
    change the ETA method (still straight-line ÷ configured speed — Decision 48).
    *(Decision 60 later approves a separate, deferred road-routing enhancement;
    it does not change this geocoding layer.)*

The intended end-to-end flow, as the text reference until flowcharts 2 & 5 are regenerated
(see [Required Diagram Changes](#required-diagram-changes)):

1. Customer logs in.
2. Customer selects a saved vehicle (or adds one).
3. Customer describes the problem / service needed.
4. Customer's (already required) contact number is on file / confirmed.
5. Customer sets their location — either **browser geolocation** ("use my current
   location") or **landmark / address search** (type a place, press Search, pick a
   result) — then confirms the point on the map, dragging the marker if needed
   (Decisions 58–59). The confirmed `latitude` / `longitude` are captured.
6. System shows the straight line from the customer's location to the shop (shop
   endpoint from `config/shop.php`).
7. System calculates an ETA **at request time**.
8. Customer reviews and submits the request.
9. Request is saved with `status = pending`, `eta_minutes` frozen.
10. Admin reviews the request (customer, vehicle, problem, location, route, ETA).
11. Admin accepts or rejects.
12. If accepted, the admin assigns a Tireman (`service_requests.tireman_id`).
13. Customer now sees: Tireman name, Tireman contact number, "Tireman is on the way", the
    stored ETA, and the route/map.
14. Customer and shop/Tireman coordinate by phone (no in-app messaging).
15. No live Tireman location and no continuously changing ETA are shown.
16. After the service, the admin sets `status = completed`.

---

## Confirmed Project Decisions — 2026-09-06 (Phase 4 follow-up: road-routing — approved, deferred)

A design direction settled ahead of time so a future session does not re-open it.
**No code, no schema change, and no diagram redraw** was made when recording this.
Nothing here is implemented yet.

60. **Road-following routing on Book-a-Rescue is APPROVED as a future
    enhancement, with implementation DEFERRED.**

    **Status:** approved in principle; **not implemented**. Implementation does
    **not** begin until Phase 5 (POS & inventory) is complete, or until the owner
    explicitly approves starting it earlier. Until then the Rescue map keeps the
    current client-side straight line and the current haversine ETA (Decision 48)
    with no change.

    **What routing may be used for (only these two):**
    - **Display** a road-following route line between the fixed shop location
      (`config/shop.php`) and the customer's confirmed `latitude` / `longitude`
      on the existing Leaflet / OpenStreetMap map.
    - **Obtain road (driving) distance** for the existing one-time ETA
      calculation.

    **ETA rules (unchanged in substance):**
    - The routed **distance** feeds the existing `Geo::etaMinutes()` formula
      (`distance ÷ otg.average_speed_kmph`, rounded up, floored at
      `otg.min_eta_minutes`). One speed model, one formula.
    - The routing provider's **own travel-duration value does NOT become the
      authoritative stored ETA.** It may only be shown on screen as an
      informational label, if at all.
    - `service_requests.eta_minutes` stays a **single frozen snapshot**, computed
      once at submission and never recomputed for display (Decisions 5/6/32).
    - The ETA is computed **server-side** at submission; a client-sent distance
      or duration is never trusted.

    **Fallback / reliability (mandatory):**
    - If the routing provider fails, times out, or returns nothing, VulcaTrack
      **falls back to the existing straight-line / haversine distance** and the
      rescue request **must still be submittable**. Routing is strictly optional
      on the submission path — it never blocks a booking.

    **Persistence / schema (unchanged):**
    - **No route geometry / polyline is persisted** (Decision 33). A road line is
      re-drawn from the two endpoints when shown; it is not stored.
    - **No new database column and no new table.** The 8-table schema and
      `service_requests` are untouched.

    **Still explicitly OUT OF SCOPE (these exclusions are preserved, not
    superseded):**
    - No live Tireman GPS tracking / location feed / moving marker (Decision 4).
    - No continuously updating ETA and no continuous re-routing — routing runs
      once, after the customer confirms the location, not per marker-drag.
    - No turn-by-turn navigation / directions UI.
    - No distance-matrix, multi-stop, or route-optimization / dispatch routing.
    - No admin-side routing as part of this future change unless separately
      approved (an admin OTG map is Phase 6 work).

    **Privacy:** implementing this will send the customer's **confirmed
    coordinates** to a third-party routing provider (today, only the landmark
    *search text* leaves the system, via Nominatim). This is an acknowledged
    privacy implication; the existing "we use this location once … we do not
    track you" wording on the Rescue page stays and should cover it.

    **Provider:** the routing provider must remain **replaceable / configurable**
    (a `routing.driver` config value and a small provider class behind an
    interface, mirroring the Decision 59 `Geocoder` layer), with an offline /
    no-network driver that returns the haversine distance. Choosing the specific
    default provider is left to implementation time.

    **This decision supersedes** the earlier blanket wording that
    routing / directions APIs are *entirely* out of scope (the "Currently Out of
    Scope / Do Not Invent" entry, and the parentheticals in Decisions 48 and 59).
    A **single origin→destination road-routing call**, used only as described
    above, is now a sanctioned — but not yet built — enhancement. Distance-matrix
    APIs, multi-stop routing, navigation, live tracking, persisted routes, and
    continuous ETA updates remain out of scope.

---

## Currently Out of Scope / Do Not Invent

Unless explicitly approved later, do **not** introduce:

- Live technician / Tireman GPS tracking
- Technician / Tireman location history or GPS telemetry
- Live / continuously updating ETA
- Route history or persisted route polyline / geometry
- Online GCash payment
- Online payment gateways / APIs
- Payment-transaction tables or online-payment fields
- Shopping cart for customers
- Online checkout
- Customer online purchasing
- Supplier / procurement management
- Multi-location inventory
- A separate Staff role
- Tireman login portal, Tireman dashboard, Tireman authentication
- Technician scheduling, ratings, payroll, or employee-management features
- Advanced dispatch / routing-optimization algorithms
- Distance-matrix APIs, multi-stop routing, and turn-by-turn navigation.
  *(**Revised by Decision 60:** a single origin→destination road-routing call —
  for a display route line and road distance feeding the existing one-time ETA —
  is approved as a **deferred, not-yet-implemented** enhancement. The Rescue
  landmark search — Decision 59 — remains **geocoding only**: place name →
  coordinates, no route computation.)*
- Client-side geocoding autocomplete / per-keystroke place search (forbidden by
  the OSM Nominatim usage policy; the Rescue search is an explicit-button action)
- Status-history / audit tables (e.g. per-status timestamp trails)
- In-app customer/technician messaging
- Anything beyond identity / contact / assignment for the `tiremen` table
  (the minimal `tiremen` table itself **is** in scope — see Decisions 22–26)
- "Remember Me" / persistent-login tokens or a remember-token table (deferred — Decision 43)
- Password reset / "forgot password", email verification, 2FA, CAPTCHA,
  account lockout / login rate-limiting (not in Phase 3 scope; none decided)
- Public admin registration page (Decisions 18/40/46)

---

## Database Context

The database consists of these tables:

- `customers`
- `admins`
- `tiremen` *(added 2026-08-31 — Decisions 22–26)*
- `vehicles`
- `items`
- `sales`
- `sale_items`
- `service_requests`

The **text schema** (`docs/ERD/schema.dbml`, Decision 38) is the maintainable
database-design source of truth. The **ERD PNG** (`docs/ERD/VulcaTrack-ERD_1.png`) is a
visual aid and is now behind the text schema. The **Database Notes**
(`docs/VulcaTrack-Database-Notes_1.md`) hold the field-by-field rationale.

Key rules (from the Database Notes / schema / decisions):

- A customer can have multiple vehicles.
- A customer can have multiple sales.
- Walk-in sales can have `NULL` `customer_id`.
- `customers` 1 : 0..N `service_requests` — zero or many; `service_requests.customer_id`
  is `NOT NULL` (Decision 39).
- Vehicles belong to customers.
- Service requests reference a customer and a vehicle (both required).
- `service_requests.admin_id` is nullable (admin handling the request, once assigned).
- `service_requests.tireman_id` is nullable (assigned Tireman, set on/after accept —
  Decision 25).
- Admins record sales (every sale has exactly one recording admin).
- Products and services are unified through `items.item_type`.
- Product stock is affected by product sales; service items do not require stock.
- `items`, `vehicles`, and `tiremen` carry `is_active TINYINT(1) NOT NULL DEFAULT 1`;
  deactivate by setting `0`, never by hard-deleting a row that has historical references
  (Decisions 27–29).
- `sales.total_amount` is the only monetary value persisted for a sale; amount tendered /
  change due are UI-only and not stored (Decision 30).
- `service_requests.eta_minutes` is a frozen snapshot; no route geometry is stored
  (Decisions 32–33).
- `email` is unique within `customers` and within `admins`, checked independently — a
  customer and an admin may share an address (Decision 42). Login identifier is
  `email` only (Decision 41).

**Do not alter the database structure merely because of a UI element.**

---

## Artifact Authority / Change-Control Rule

The project contains several artifacts, each with a distinct purpose:

| Artifact | Authority / purpose |
|---|---|
| **Figma prototype** | Primary reference for UI/UX and visual/interface behavior. Should strongly influence frontend implementation. External to this repo. |
| **ERD** (`docs/ERD/…`) | Reference for database structure and relationships. |
| **Flowcharts** (`docs/flows/…`) | Reference for system/process workflows. |
| **Database Notes** (`docs/VulcaTrack-Database-Notes_1.md`) | Detailed explanation of the current database design, rules, assumptions, and unresolved questions. |
| **This file** (`docs/decisions/project-decisions.md`) | Authoritative record of CONFIRMED project decisions. |

### CRITICAL RULE

If two project artifacts conflict, **do not silently modify one artifact to make it match
another.**

Instead, classify the issue as one of:

- **CONSISTENT** — artifacts agree.
- **CONFLICT** — artifacts directly contradict each other.
- **AMBIGUOUS** — an artifact can be read more than one way.
- **MISSING** — something expected is absent from an artifact.
- **POSSIBLY OUTDATED** — an artifact appears to reflect an earlier decision.

Explain the conflict and wait for an explicit project decision before changing
architecture, database structure, or workflow.

---

## Known Open / Unresolved Questions

The following are **NOT** confirmed decisions and must not be silently resolved:

- Whether **"Manage Customer Accounts"** (admin capability) is officially in scope. It
  appears in the use-case diagram but is flagged there as proposed.
- ~~Exact handling of **denied geolocation**.~~ **Resolved 2026-09-06 (Decisions
  58–59):** landmark / address search is a first-class alternative to browser
  geolocation, with map + draggable-marker confirmation. Both resolve to
  `latitude` / `longitude`; a location is still required to submit.
- Whether **`service_requests.admin_id`** should remain nullable throughout the workflow or
  become mandatory once accepted.
- Whether **shop location** should eventually become editable through admin settings (i.e.
  move from the `config/shop.php` constant to a `shop_settings` table). Config-value
  treatment is confirmed for v1 (Decision 37); only the *future* editable option is open.
- Exact **UI treatment of saved-vehicle management**.
- **`category`** as a plain field on `items` vs. its own table — *settled for Phase 5 by
  Decision 52 (stays plain free-text; no category table in Phase 5)*; whether it ever
  becomes a table beyond Phase 5 is still open.
- Whether **Admin can manually create customer accounts** through a dedicated screen — still
  open. *Decision 51 only settles that the **POS** never creates accounts.*
- Whether a finalized **requirements / SRS document** will be produced, and its contents.
  Not started; when created it belongs under `docs/requirements/` (the folder exists and is
  intentionally empty).

Do not turn these into confirmed requirements without approval.

### Resolved on 2026-08-31 (moved out of this list)

| Former open question | Resolution |
|---|---|
| `vehicles` needs an `is_active` field? | **Yes** — Decision 27. |
| `items` needs an `is_active` field? | **Yes** — Decision 27. |
| `eta_minutes` stored snapshot vs. recomputed? | **Stored, frozen** — Decision 32. |
| "Tireman" modeled as its own entity? | **Yes, minimal `tiremen` table** (no login/dashboard) — Decisions 22–26. |
| `customers → service_requests` cardinality (`1:1..N` vs `1:0..N`)? | **`1 : 0..N`** — Decision 39. |
| POS in-person cash tender / change persisted? | **No, UI-only** — Decision 30. |
| Per-status timestamp columns on `service_requests`? | **No** in v1 — Decision 34. |
| `sale_date` manually editable / backdating? | **No, system-controlled** — Decision 35. |
| "Manage Inventory" vs "Manage Products" as separate modules? | **One module** — Decision 36. |

### Resolved on 2026-09-01 (Phase 3 — moved out of this list)

| Former open question | Resolution |
|---|---|
| Customers identified by email / username / both? | **Email only** — Decision 41. |
| Email uniqueness — global across `customers`+`admins`, or per-table? | **Per-table, independent** — Decision 42. |
| Remember-me / persistent-login token table? | **Deferred entirely for v1; no table** — Decision 43. Session auth only. |

### Resolved on 2026-09-06 (Phase 5 pre-decisions — moved out of this list)

| Former open question | Resolution |
|---|---|
| Final receipt requirements? | **Fixed field list; printable HTML only; no receipt table** — Decision 50. |
| Sales reporting in Phase 5? | **No — deferred to Phase 6** — Decision 49. |
| `items.category` a plain field or its own table (for Phase 5)? | **Plain free-text; no category table in Phase 5** — Decision 52. |
| POS customer linking — how / does it create accounts? | **Optional, existing customers only; POS never creates an account** — Decision 51. |
| Cash tender / change persisted? *(re-affirm)* | **No — UI/response only** — Decision 54. |
| Denied / poor geolocation UX on Book-a-Rescue? | **Landmark / address search alongside browser GPS, both with map + draggable-marker confirmation** — Decisions 58–59. |

---

## Current Project Status

- **Phases 1–4 complete (2026-09-01); Phase 4.5 stabilization pass done
  (2026-09-06).** Application Foundation, Database Schema, Authentication &
  Authorization, Customer-Side Functionality. Phase 5 (POS & inventory) is next
  and begins only when explicitly instructed; its pre-decisions (49–57) are
  settled.
- Repo on `main` at `C:\IPT102`, pushed to
  `https://github.com/Iyani99/VulcaTrack.git`; app at `C:\IPT102\vulcatrack\`
  served via a Windows junction from `C:\xampp\htdocs\vulcatrack`.
- Database: the 8 tables from `docs/ERD/schema.dbml` are built
  (`vulcatrack/database/schema.sql`); no seed data ships (the owner keeps a
  personal test account).
- **Test harness (Phase 4.5):** `vulcatrack/tests/` — dependency-free CLI runner
  (`php vulcatrack/tests/run.php`), 82 passed, 0 failed, 429 assertions across
  unit, integration (schema + repositories + Auth) and end-to-end HTTP suites.
  All green as of 2026-09-06.
- Auth (Decisions 41–47): customer + admin login/logout, CLI
  `vulcatrack/database/seed_admin.php`, hardened sessions, guards.
- Customer side (Decision 48): `vulcatrack/customer/*` — dashboard, profile,
  saved vehicles (soft-delete), OTG rescue submission with a frozen-snapshot
  ETA, request history + customer-facing status. No schema change; OTG requests
  are always created `status = 'pending'`.
- ERD exists (PNG + text schema `docs/ERD/schema.dbml`).
- Use-case diagram exists (PNG; changes pending — see Required Diagram Changes).
- Six flowcharts exist.
- Database Notes exist.
- Figma prototype exists externally and is the team's primary UI/UX reference.
- **No finalized requirements / SRS document exists.** There is no `docs/requirements/`
  folder yet; when a finalized requirements/SRS is created it should be placed under
  `docs/requirements/`.

The next development phase begins only when explicitly instructed. Work proceeds one
phase at a time; the next phase is never auto-started.

---

## Source-of-Truth Index

| Path | Purpose | Notes |
|---|---|---|
| `docs/VulcaTrack-Database-Notes_1.md` | Field-by-field explanation of the database design, business rules, integrity rules, assumptions, and unresolved questions. Read alongside the schema. | Has a 2026-08-31 revision note at the top. |
| `docs/ERD/schema.dbml` | **Maintainable source of truth for the database schema** (DBML text). Basis for the eventual MySQL implementation. | Decision 38. Added 2026-08-31. |
| `docs/ERD/VulcaTrack-ERD_1.png` | Entity-relationship diagram (visual aid). | Image. Now **behind** `schema.dbml`; needs regeneration — see Required Diagram Changes. |
| `docs/VulcaTrack-Use-Case-Diagram_1.png` | Actors and use cases for customer and admin sides. | Image. Changes pending (see Required Diagram Changes). "Manage Customer Accounts" flagged as proposed. |
| `docs/flows/VulcaTrack-1-Overall-System-Flow.png` | Overall system workflow. | Image. |
| `docs/flows/VulcaTrack-2-Customer-Flow.png` | Customer-side workflow. | Image. |
| `docs/flows/VulcaTrack-3-Admin-Flow.png` | Admin-side workflow. | Image. |
| `docs/flows/VulcaTrack-4-POS-Flow.png` | In-shop sales / POS workflow. | Image. |
| `docs/flows/VulcaTrack-5-OTG-Request-Flow.png` | On-the-Go request workflow. | Image. |
| `docs/flows/VulcaTrack-6-Inventory-Flow.png` | Inventory management workflow. | Image. |
| `docs/decisions/project-decisions.md` | **This file.** Authoritative confirmed-decision record. | — |
| `docs/requirements/` | Intended home of a finalized requirements/SRS document, **if/when one is produced.** | Folder exists and is intentionally empty; do not invent requirements to fill it. |
| `source/source.txt` | Obsolete 0-byte placeholder from before the app existed. | The application lives in `vulcatrack/`. Harmless; a candidate for deletion during a future cleanup. |

The **Figma prototype** is external to `C:\IPT102` and is the team's UI/UX reference when
provided/accessed.

---

## Known Conflicts / Clarifications

Cross-checked on 2026-08-31 (initial review, then Decision Review & Documentation Update)
against `docs/VulcaTrack-Database-Notes_1.md`, the diagrams, and the flowcharts. Status of
each item below.

| # | Topic | Classification | Status / detail |
|---|---|---|---|
| C1 | Project title | **CONSISTENT** | Database Notes header uses the locked title exactly. No earlier title variants exist anywhere in `C:\IPT102`. No action. |
| C2 | "Tireman" as label vs. entity | **RESOLVED** | Refined by Decisions 22–26: "Tireman" stays customer-facing wording **and** gets a minimal `tiremen` table (no login/dashboard/GPS). Database Notes §12 open item superseded; a revision note + inline `tiremen` section were added to the Notes. |
| C3 | Shop location: config vs. table | **RESOLVED for v1** | Decision 37: centralized config (`config/shop.php`) holding lat/long/address; no `shop_settings` table in v1. Admin-editable option stays a *future* open question. Database Notes §10/§12 updated. |
| C4 | `customers` → `service_requests` cardinality | **RESOLVED** | Decision 39: corrected to `1 : 0..N`; `service_requests.customer_id` `NOT NULL`. Database Notes §3 corrected. |
| C5 | Empty legacy stub `project-decisions.md.txt` | **RESOLVED** | The 0-byte stub is no longer on disk (only `project-decisions.md` remains in `docs/decisions/`). Closed in the 2026-09-06 stabilization pass. |
| N1 | POS in-person cash payment / receipt | **RESOLVED** | Decisions 30–31: online/gateway payment out of scope; POS UI does total/tender/change but does **not** persist tender/change; `sales.total_amount` only; printable HTML receipt, no receipt table. Flow 4 (POS PNG) is consistent — annotation only (D4). |
| N2 | Item / vehicle deactivation had no schema support | **RESOLVED** | Decisions 27–29: `is_active` on `items`, `vehicles` (and `tiremen`). Added to `schema.dbml` + Database Notes. ERD PNG needs regeneration (D1). |
| N3 | "Manage Inventory" vs "Manage Products" | **RESOLVED** | Decision 36: one module. Use-case PNG needs update (D2). No schema impact. |
| N4 | Route/polyline persistence & `eta_minutes` snapshot | **RESOLVED** | Decisions 32–33: `eta_minutes` frozen snapshot, never recomputed for display; no polyline persisted; no `distance_km`/`route_calculated_at`. Database Notes §10 updated. |
| N5 | No per-status timestamps on `service_requests` | **RESOLVED (defer)** | Decision 34: none added in v1; no status-history table. |
| N6 | `docs/requirements/` folder referenced but absent | **RESOLVED** | Record wording softened; folder intentionally not created. No requirements/SRS invented. |
| N7 | `sales.sale_date` vs `sales.created_at` | **RESOLVED** | Decision 35: `sale_date` = system-controlled actual-sale timestamp (no backdating in v1); `created_at` = record creation; reports use `sale_date`. |
| N8 | Figma not cross-checked | **OPEN (informational)** | Prototype is external and not provided this session. Cross-check needed before frontend work for: POS payment/receipt UI, inventory module layout, OTG map view (customer + admin), saved-vehicle management UI, admin dashboard contents. |

### Remaining conflicts after this update

- **No unresolved *CONFLICT* remains between the confirmed decisions and the artifacts.**
- **POSSIBLY OUTDATED diagrams** (documented, not yet regenerated): ERD PNG, use-case PNG,
  and OTG flowcharts 2 & 5 — see [Required Diagram Changes](#required-diagram-changes).
- **N8 (Figma)** stays open until the prototype is provided.

Any future conflict must be reported and classified here (or in a superseding decision
record), never silently reconciled.

---

## Required Diagram Changes

Documented here for whoever owns the diagram tooling. **Not executed in this task** (the
PNGs and the Figma prototype were not modified).

| ID | Artifact | Classification | Required change |
|---|---|---|---|
| D1 | `docs/ERD/VulcaTrack-ERD_1.png` | POSSIBLY OUTDATED | Regenerate from `docs/ERD/schema.dbml`. Must show: (a) new `tiremen` table (`tireman_id` PK, `name`, `contact_number`, `is_active`, `created_at`, `updated_at`); (b) `service_requests.tireman_id` nullable FK → `tiremen`; (c) `items.is_active` and `vehicles.is_active`; (d) `customers → service_requests` as **`1 : 0..N`** (not `1 : 1..N`). |
| D2 | `docs/VulcaTrack-Use-Case-Diagram_1.png` | POSSIBLY OUTDATED | (a) Collapse "Manage Inventory" + "Manage Products" into one "Manage Inventory" use case (products + services). (b) Add admin use cases "Manage Tiremen" and "Assign Tireman to Request". (c) Keep "Manage Customer Accounts" flagged as proposed/unresolved. |
| D3 | `docs/flows/VulcaTrack-2-Customer-Flow.png`, `docs/flows/VulcaTrack-5-OTG-Request-Flow.png` | POSSIBLY OUTDATED | Admin branch: add an "Assign Tireman" step after "Set Status: Accepted". Customer view after acceptance: show assigned Tireman name + contact number, "Tireman is on the way", the stored ETA, and the route/map. |
| D4 | `docs/flows/VulcaTrack-4-POS-Flow.png` | CONSISTENT (annotate) | No structural change. Optionally note that "Enter Payment Amount / Payment Sufficient? / Calculate Change" are UI-only (not persisted) and "Generate Receipt" is a printable HTML view with no receipt table. |
| D5 | `docs/flows/VulcaTrack-6-Inventory-Flow.png` | CONSISTENT | No change. "Deactivate / Delete Item" is now backed by `items.is_active`. |

---

## Revision History

### 2026-09-06 — Phase 4 follow-up: road-routing approved but deferred (Decision 60)

- **Decision 60** — road-following routing on Book-a-Rescue is **approved in
  principle as a future enhancement, with implementation deferred** until after
  Phase 5 (or until the owner explicitly approves starting earlier). Recorded
  after a documentation review of the proposal; **no code, no schema change, no
  diagram redraw.**
- Routing, when built, may only: (a) display a road-following route line between
  the fixed shop location and the customer's confirmed location, and (b) supply
  **road distance** to the existing `Geo::etaMinutes()` formula. The provider's
  own travel duration will **not** become the authoritative stored ETA.
  `eta_minutes` stays a single frozen snapshot; the ETA is computed server-side
  at submission.
- If routing fails, VulcaTrack falls back to the existing straight-line /
  haversine distance and the rescue request **must still be submittable**.
- No route geometry / polyline persisted; **no new DB column or table.** No live
  Tireman tracking, no continuous re-routing, no turn-by-turn navigation, no
  distance-matrix / multi-stop routing, no admin-side routing (unless separately
  approved). The routing provider must remain replaceable / configurable.
- Acknowledged privacy implication: implementing this sends the customer's
  confirmed coordinates to a third-party routing provider.
- **Supersedes** the earlier blanket "routing / directions / distance-matrix
  APIs" out-of-scope entry and the "no routing API" parentheticals in Decisions
  48 and 59, narrowing them to a single sanctioned origin→destination call.
  Distance-matrix / multi-stop / navigation / live tracking / persisted routes /
  continuous ETA remain out of scope.
- Also corrected the one directly conflicting sentence in
  `docs/flows/VulcaTrack-DFD-notes.md` (the "there is no external
  routing/directions API" line) to point at Decision 60. **No DFD diagram was
  redrawn** — geocoding and routing are treated as optional external support
  calls that add no data store and no domain data flow.

### 2026-09-06 — Phase 4 enhancement: Rescue location selection (Decisions 58–59)

- **Decision 58** — the Book-a-Rescue location step now offers **browser
  geolocation OR landmark/address search**, both first-class, both confirmed on
  the existing Leaflet map with a **draggable marker**; the final marker position
  is what is stored. Request stays `pending` on creation; ETA stays a one-time
  frozen snapshot (Decision 48). No schema change.
- **Decision 59** — geocoding is a small replaceable layer
  (`src/Support/Geocoder` + `NominatimGeocoder` + `ArrayGeocoder` +
  `GeocoderFactory` + `GeocodeCache`) reached only through a server-side
  endpoint, `customer/geocode.php` (auth + CSRF). The endpoint enforces the OSM
  Nominatim usage policy: identifying `User-Agent`, ≥ 1 request/second app-wide,
  cache identical queries (`storage/cache/geocode/`, git-ignored), explicit
  Search button only (no autocomplete), PH + Bulacan soft bias, all provider
  data treated as untrusted (coords re-validated, labels escaped). Attribution
  shown on the page. **Not** a routing API — the ETA method is unchanged.
- Moved "denied geolocation UX" out of *Known Open / Unresolved Questions*.
- Added a `geocoding` block to `config/config.example.php` (and the local
  `config.php`). Added `src/Support/` classes + `customer/geocode.php` +
  landmark-search UI in `customer/rescue.php` + search handling in
  `assets/js/otg-map.js`. Extended the test harness: `unit/GeocoderTest`,
  `unit/GeocodeCacheTest`, `http/GeocodeHttpTest`, plus a landmark-coords case in
  `integration/RepositoryTest`.
- **Follow-up fix (same day):** verified the whole flow in a real headless
  Chrome against Apache + live Nominatim — it worked. The reported "pressing
  Enter / Search does nothing" was a **stale browser cache** of `otg-map.js`
  (Apache serves `assets/` with only `Last-Modified`/`ETag`, no `Cache-Control`,
  so browsers heuristically cache and can hold a pre-enhancement copy across a
  redeploy). Fix: `vulcatrack_asset()` stamps `?v=<filemtime>` on the app-owned
  CSS/JS (`app.css`, `otg-map.js`, vendored Leaflet); the search field also got
  an inline `onkeydown` Enter guard so a stale/failed `otg-map.js` can never turn
  Enter into a full form submit. `unit/GeocoderTest` etc. now total **82 tests /
  429 assertions, green.**
- **No schema change** — still exactly 8 tables, four OTG statuses, and
  `service_requests.latitude` / `longitude` / `eta_minutes` unchanged.

### 2026-09-06 — Phase 4.5 stabilization pass (no feature code, no schema change)

- **Added Decisions 49–57** — the settled Phase 5 pre-decisions: sales reporting
  deferred to Phase 6 (49); fixed printable-HTML receipt field list, no receipt
  table (50); POS customer-linking is optional and existing-only, POS never
  creates accounts (51); `items.category` stays plain free-text for Phase 5
  (52); a minimal admin shell is an approved Phase 5 prerequisite (53); cash
  tender/change never persisted (54); integer-centavo money arithmetic (55);
  app-layer validation limits `price >= 0` / `quantity > 0` / `stock >= 0`
  without new schema CHECKs (56); session cart only for error recovery (57).
- **Moved five items** out of *Known Open / Unresolved Questions* into a new
  "Resolved on 2026-09-06" table (receipt requirements, Phase-5 reporting,
  `items.category`, POS customer linking, cash tender persistence).
- **Documentation-drift fixes:** the Technology table now names MariaDB 10.4,
  Apache 2.4, Vue-where-it-helps and Leaflet, and the GitHub remote
  (`Iyani99/VulcaTrack.git`); the Decision 48 note records that
  `config/shop.php` has held the **real** Baliwag shop location since commit
  `f2043d5` (2026-09-04), not "sample coordinates"; conflict **C5** closed (the
  `project-decisions.md.txt` stub is gone); `docs/requirements/` described as
  existing-but-empty; `source/source.txt` flagged as an obsolete placeholder.
- **Diagrams:** `docs/flows/VulcaTrack-Activity-Diagram-OTG.*` and
  `VulcaTrack-Sequence-Diagram-POS.*` were reviewed against the locked design
  (three swimlanes with the Tireman as an off-system note; four statuses; no
  payment table; frozen `unit_price`; product-only stock deduction; printable
  HTML receipt) — found **CONSISTENT** and added to version control as Chapter 3
  documentation.
- **Added a committed test harness** at `vulcatrack/tests/` (dependency-free,
  no Composer/PHPUnit): 60 tests / 339 assertions covering the Phase 1–4
  regression surface (unit, live-schema + repository integration, end-to-end
  HTTP guards/CSRF/actor-separation). Ran green.
- **No change to Decisions 1–48. No schema change** — still exactly 8 tables,
  four OTG statuses, no new columns.

### 2026-09-01 — Phase 4 (Customer-Side Functionality)

- Implemented the customer side on top of Phase 3 auth: dashboard, profile
  (name / contact number / password change), saved vehicles (add / edit /
  `is_active` soft-delete / restore), On-the-Go rescue submission, request
  history, and the customer-facing status view ("Tireman is on the way" +
  Tireman name/contact shown once an admin assigns one).
- Added **Decision 48** — OTG ETA computation method (haversine ÷ configured
  average speed, floored; frozen snapshot; no routing API) and the small
  implementation choices that went with it (email read-only on profile;
  location required to submit; `config/shop.php` set to sample coordinates;
  Leaflet vendored for the map).
- Annotated three Known Open Questions as pragmatically addressed but still
  open for exact-UX / Figma refinement: denied-geolocation fallback,
  saved-vehicle management UI, Figma cross-check.
- **No schema change** — still exactly 8 tables, still exactly four OTG status
  values, no new columns. No change to any earlier decision (1–47).

### 2026-09-01 — Phase 3 (Authentication & Authorization) decisions

- Added **Decisions 41–47** from the owner's Phase 3 approval: email-only login
  identifier; email uniqueness stays per-table; **Remember Me deferred entirely
  (no token table)**; 8-character minimum password with confirmation; 30-minute
  sliding session idle timeout (configurable); CLI-only admin provisioning
  (`vulcatrack/database/seed_admin.php`); two non-crossing session actors
  (customer / admin) with CSRF-protected POST auth forms and generic,
  enumeration-resistant login failures.
- Moved three items out of **Known Open / Unresolved Questions** (customer
  identifier, email-uniqueness scope, remember-me) into a new "Resolved on
  2026-09-01" table.
- Added remember-me tokens, password reset, email verification, 2FA, CAPTCHA,
  lockout/rate-limiting, and a public admin registration page to
  **Currently Out of Scope / Do Not Invent**.
- Updated **Current Project Status** (Phases 1–3 complete).
- **No schema change** — the database is still exactly the approved 8 tables.
  No change to any earlier decision (1–40).

### 2026-09-01 — Phases 1 & 2 (implementation, no decision changes)

- **Phase 1 (Application Foundation):** the scaffold was moved into the monorepo
  at `C:\IPT102\vulcatrack\`; Apache serves it via a Windows junction; Git was
  initialised on `main`. Documentation status lines in `docs/PROJECT-CONTEXT.md`
  were reconciled (its rev. 3). No decisions changed.
- **Phase 2 (Database Schema):** `vulcatrack/database/schema.sql` was created
  from `docs/ERD/schema.dbml` and verified against MariaDB 10.4.32 (8 tables,
  keys, FKs, nullability, `CHECK` constraints for `service_requests.status` and
  `items.item_type`). Column types follow the indicative DBML types. No decision
  or schema-design change.

### 2026-08-31 — §16.1 reconciliation: `tiremen` entity reaffirmed

- The project owner reviewed `docs/PROJECT-CONTEXT.md` §16.1 (which had flagged a conflict
  between Decisions 22–26 and a later handoff-draft that said "Tireman is not a DB entity")
  and **explicitly approved option (a): KEEP the `tiremen` table and
  `service_requests.tireman_id`.** Decisions 22–26 stand as the current approved design.
- Added the **three-actor clarification** (Customer / Admin / Tireman) after Decision 26.
- Decision 24 updated to include **"view"** and to say the assigned Tireman must be active.
- `docs/PROJECT-CONTEXT.md` updated to present the **8-table** design as approved (its
  §16.1 marked resolved).
- **No other decisions changed. Title unchanged. No new tables or features. No application
  code, database, or Figma changes.**
- `docs/ERD/schema.dbml` and `docs/VulcaTrack-Database-Notes_1.md` already reflected the
  8-table design and needed no change.

### 2026-08-31 — Decision Review & Documentation Update

- Added **Decisions 22–40** (Tiremen table, `is_active` / soft-delete, POS cash-handling
  clarification, `eta_minutes` snapshot + no route geometry, no per-status timestamps,
  `sale_date` behavior, single "Manage Inventory" module, shop-location config,
  `docs/ERD/schema.dbml` as ERD source of truth, `customers → service_requests` `1 : 0..N`,
  simple admin provisioning).
- Resolved conflicts **C2, C3, C4** and findings **N1–N7**; recorded **N8** (Figma) and
  **C5** (stub file) as still open.
- Rewrote the Database Context, Out-of-Scope, Open Questions, Current Status, and
  Source-of-Truth sections to match.
- Created `docs/ERD/schema.dbml`.
- Updated `docs/VulcaTrack-Database-Notes_1.md` (revision note + inline changes).
- Added the [Required Diagram Changes](#required-diagram-changes) section (PNGs / Figma not
  modified).
- **No application code, database, API, frontend, or requirements/SRS document was
  created.**

### 2026-08-31 — Initial decision record

- Created the authoritative decision record from prior design work; logged conflicts
  C1–C5.
