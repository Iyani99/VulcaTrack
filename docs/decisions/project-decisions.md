# VulcaTrack — Project Decision Record

**Status:** Authoritative record of CONFIRMED project decisions.
**Last updated:** 2026-10-08 (documentation and paper-diagram clarification only; no new decision)
**Earlier revision note (2026-09-28):** **Decision 69 added** (Phase 7.1: an item's type is fixed
once it has recorded sales). Earlier the same day: **Phase 6 closed** (status only), and
**Decisions 65–68 added** (owner-approved Rescue status rules, Phase 6.3; Decision 25
refined, `admin_id` open question resolved) and Phase 6 status updated (Chunks 6.1–6.3,
Sales History and Sales Reports done). Previous: 2026-09-23 — Phase 5 closed; clarified
Decision 62 (optional expected-total assertion) and Decision 50 (printable sale
document is a non-official transaction reference)
(see [Revision History](#revision-history)).
**Purpose:** This file exists so that anyone new to the project can understand its
confirmed decisions, scope boundaries, and change-control rules **without** relying on
prior discussion or any undocumented context.

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
3. In-shop sales and inventory *(the admin-operated POS; since Decision 70 a sale may
   also be linked to the Rescue request it was recorded for)*
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
    *(Clarified 2026-09-28 by **Decision 70**: the admin still records every sale at
    the POS, but a sale may also be the one recorded for an on-site Rescue job, linked
    to its request.)*
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
    *(Refined 2026-09-28 by **Decisions 66–68**: an admin can no longer create an
    accepted-but-unassigned request — acceptance assigns an active Tireman in the same
    action; a Tireman assigned before a later rejection or completion is **kept** as
    history; `admin_id` is the last admin who changed status or assignment.
    Accepted-but-unassigned rows from earlier data are still displayed safely.)*
26. **Tiremen are identity / contact / assignment only.** No technician authentication, no
    live GPS tracking, no location telemetry/history, no schedules, no ratings, no payroll,
    no employee records. "Tireman is on the way" remains status wording for the `accepted`
    state.
    *(Clarified 2026-09-29 by **Decision 78**: a customer's one-time feedback on their own
    completed Rescue is stored on the request and may name the Tireman who serviced it as
    context. That is not a Tireman rating — there are still no Tireman scores, averages,
    rankings or effects on dispatch.)*

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
    *(Clarified by **Decision 79**: the CLI script remains the way to create the
    **first** admin and to recover access; additional admins can also be created by a
    signed-in admin on the authenticated Admin Accounts page.)*
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
    *Clarified 2026-09-23 (non-official status):* "receipt" is this record's
    working term only. The printed document is a simple **transaction reference**
    generated from the recorded sale — **not** an Official Receipt, **not** a
    BIR-registered invoice, and VulcaTrack does **not** replace the shop's
    legally required invoicing method. On screen and in print it carries a
    neutral title (e.g. "Sales Transaction Slip" / "Transaction Summary") and a
    short note such as *"For transaction reference only. Not an official BIR
    invoice."* Using VulcaTrack as an official invoicing POS (TIN / VAT / BIR
    accreditation or registration) would be a separate compliance requirement
    **outside the approved project scope**.

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
11. Admin accepts — choosing an **active** Tireman in the same step (Decision 66) — or rejects.
12. The Tireman is stored in `service_requests.tireman_id`. While accepted, the admin may
    reassign another active Tireman, or reject (Decisions 65–66).
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

## Confirmed Project Decisions — 2026-09-08 (Phase 5: Sales foundation)

The trusted server-side foundation for recording an in-person sale
(`SaleRepository` + `SaleService`). **No schema change** — the 8 tables,
`sales`, and `sale_items` are exactly as approved. The POS UI (built later in Phase 5,
`admin/pos.php`) is a thin caller of this foundation.

61. **`SaleService` is the single owner of the checkout transaction.** One
    `SaleService::checkout()` call = one database transaction that it begins,
    commits, or rolls back. `SaleRepository` / `ItemRepository` take the same
    PDO connection and only read/write rows — they never begin, commit or roll
    back, and hold no business policy. `checkout()` refuses to run if a
    transaction is already open (no nested transactions). On **any** failure the
    whole transaction is rolled back: no `sales` row, no `sale_items`, and no
    stock change survives — a partial sale is never left behind.

62. **The server is authoritative for every business-critical value.** The
    caller may choose only: item ids, quantities, and an optional *existing*
    `customer_id` (blank / null = walk-in). `checkout()` ignores any
    caller-supplied price, subtotal, total or `item_type`.
    - **Price:** each line's unit price is read from `items.price` at checkout
      time (in the same locked read as the stock check) and written to
      `sale_items.unit_price` — a frozen historical snapshot (Decision 17). A
      later change to `items.price` does not alter past sales.
    - **Totals:** line subtotal = unit price × quantity; `sales.total_amount` =
      sum of the line subtotals — all computed server-side in **integer
      centavos** via `Money` (Decision 55). `DECIMAL(10,2)` values from the DB
      are treated as exact strings, converted to centavos for arithmetic, and
      formatted back with `Money::format()` for storage. No `float`, no
      `round()`-to-repair.
    - **Expected-total assertion (clarified 2026-09-23):** the caller may
      optionally pass `expected_total_centavos` — the total the cashier was
      shown. It is **only a consistency / stale-state assertion, never an
      authoritative price or total**, and is not an exception to the rule
      above: `SaleService` still calculates the authoritative total itself from
      the current database prices inside the transaction, and the database /
      current server state remains authoritative. If the supplied value differs
      from that authoritative total, checkout fails with a `SaleException` and
      the whole transaction rolls back (nothing is recorded). It exists so the
      cashier cannot unknowingly complete a sale whose displayed / cart state has
      become stale (a price changed, or the cart changed in another window).
      Omitting it leaves checkout behaviour unchanged.
    - **Sale date:** `sales.sale_date` is set by the application at completion
      (`date('Y-m-d H:i:s')`), never from the client (Decision 35).
    - **Quantity:** must be a whole number between 1 and the largest value the
      approved `sale_items.quantity` column (a signed `INT`) can hold —
      `SaleRepository::MAX_QUANTITY` (2,147,483,647). Enforced on each supplied
      line **and** on the merged per-item total (duplicate lines for the same
      item are merged by summing). The service rejects an over-range quantity as
      a clean `SaleException` with a clear message; the strict session
      (Decision 64) is the DB-level backstop.
    - **Overflow safety:** because `unit_price_centavos` (≤ `Money::MAX_CENTAVOS`)
      × quantity (≤ `MAX_QUANTITY`) can exceed PHP's integer range, each line is
      bounded with `intdiv` **before** the multiply and the running total is
      checked with subtraction **before** each add — no intermediate can
      overflow to a float and then be validated after the fact.
    - **Recording admin:** `admin_id` must be sourced by the caller from the
      authenticated Admin session; `checkout()` additionally confirms the id
      still refers to a real `admins` row (so a stale session becomes a clean
      `SaleException`, not a raw FK `PDOException`).

63. **Overselling is prevented with transaction-scoped row locking.** For each
    line, `ItemRepository::lockForUpdate()` does `SELECT … FOR UPDATE` on the
    item row, so two concurrent checkouts for the same product serialize and
    cannot both consume the same stock. Product lines then deduct stock with a
    single guarded statement
    (`UPDATE … SET stock_quantity = stock_quantity - ? WHERE item_id = ? AND
    item_type = 'product' AND stock_quantity >= ?`), which can never drive stock
    negative. **Service lines never touch stock** (Decision 16). Rows are locked
    in ascending item-id order to avoid deadlocks. No reservation system, no
    inventory ledger, no queue — just the transactional stock guarantee. This is
    the Decision 56 application-layer limit (`stock_quantity >= 0`); no new
    `CHECK` constraint was added.

64. **VulcaTrack puts its own database sessions in strict SQL mode.** The
    connection factory `includes/db.php` runs, on every PDO connection it hands
    out (app pages, CLI scripts, and the whole test harness, which shares that
    factory):

    ```sql
    SET SESSION sql_mode = IF(
        FIND_IN_SET('STRICT_TRANS_TABLES', @@SESSION.sql_mode),
        @@SESSION.sql_mode,
        CONCAT_WS(',', NULLIF(@@SESSION.sql_mode, ''), 'STRICT_TRANS_TABLES')
    )
    ```

    Effect: an over-long string or an out-of-range number is **rejected with an
    error**, never silently truncated or clamped — the sales-audit defect (an
    over-range `sale_items.quantity` silently clamped to `2147483647`) becomes
    impossible at the DB layer too, not only in `SaleService`.
    - **Robust across environments** — the expression is explicit about all three
      inherited states: strict already present → mode left untouched (no
      duplicate); strict absent on a non-empty list → prepended, other modes
      kept; inherited mode empty → `NULLIF` drops the empty side so there is no
      stray comma. It is idempotent (safe to re-run).
    - **Session-scoped only.** The XAMPP server's *global* `sql_mode` and
      `my.ini` are **not** touched, so nothing depends on how a particular
      machine is configured and no developer has to change their server.
    - **No schema change**, no `CHECK` constraints, no repository-level
      `SET sql_mode`.
    - **Verified compatible** with all completed modules: every write path
      already caps each string field to its exact column width (`Validator::text`
      / `optionalText` / `email`), and DECIMAL *fractional* rounding (the OTG
      `latitude` / `longitude` DECIMAL(10,7) receiving ~15-dp browser
      coordinates) is a warning, **not** an error, even under strict mode — so
      the rescue-submission path is unaffected. The full suite (HTTP end-to-end
      included) passes under the strict session.
    - Standing rule: **new INSERT/UPDATE code must validate string length and
      numeric range at the application layer** (the strict session is the
      backstop, not the primary guard) — see [feedback / standing rules].

---

## Confirmed Project Decisions — 2026-09-28 (Phase 6.3: Rescue status rules)

Approved by the owner when starting Phase 6.3 (admin Rescue actions). **No schema
change** — still exactly 8 tables and the four statuses of Decision 10; no status-history,
cancellation, rejection-reason or completion-notes column/table.

65. **Allowed OTG status transitions are exactly:** `pending → accepted`,
    `pending → rejected`, `accepted → completed`, `accepted → rejected`.
    `accepted → completed` additionally **requires an assigned Tireman**
    (`tireman_id IS NOT NULL`; that Tireman may since have been deactivated) — a legacy
    accepted request without one must be assigned a Tireman first.
    **`rejected` and `completed` are final** — nothing leaves them (no reopening, no
    `pending → completed`, no same-status "change"). The rule lives in
    `OtgStatus::canTransition()` and is enforced again at the database by guarded
    `UPDATE … WHERE status = <expected>` statements (one row or nothing), so a request
    that changed in another tab / by another admin is refused with "This request
    changed", never overwritten.
66. **Accepting a request requires an active Tireman, chosen in the same action.** An
    admin cannot create an accepted-but-unassigned request. While a request is
    `accepted`, the admin may **reassign** it to a different active Tireman (status
    unchanged); reassignment is not possible in any other state. Inactive Tiremen are
    never offered or accepted for a new assignment (Decision 28).
67. **`service_requests.admin_id` = the last admin who changed the request's status or
    Tireman assignment.** Every successful accept / reassign / reject / complete sets it
    from the authenticated admin session — never from form input. The column stays
    nullable (a pending request has not been handled yet). *(Resolves the open question
    on `admin_id`.)*
68. **The Tireman stays on final requests.** Completing or rejecting an accepted request
    keeps its `tireman_id` as history; only a reassignment replaces it. A request
    rejected while pending never had one. The customer sees "Tireman is on the way"
    only while the request is `accepted` with a Tireman; a completed request shows the
    Tireman's name as history and never the on-the-way wording.

## Confirmed Project Decisions — 2026-09-28 (Phase 7.1: inventory edit integrity)

Approved by the owner for Phase 7.1. **No schema change.**

69. **An item's type is fixed once it has recorded sales.** While an item appears on no
    `sale_items` row, an admin may still correct it between product and service (the
    stock fields follow Decision 16). Once it appears on at least one recorded sale,
    `item_type` can no longer change in the application — the edit page shows it
    read-only and `ItemRepository::update()` refuses a change (also inside the UPDATE
    itself, so a sale recorded in between cannot let one through). All other fields of
    a sold item stay editable. *Implementation note (same chunk):* the item edit form
    carries the type and stock it was loaded with, and the save is refused ("This item
    changed …") if a sale has changed them since, so a stale form can never put back
    stock that the POS has already deducted.

## Confirmed Project Decisions — 2026-09-28 (Phase 7.3d: Rescue sales)

Approved by the owner for Phase 7.3d (built in three checkpoints: 7.3d-a schema,
7.3d-b domain rules, 7.3d-c admin UI / POS workflow). **One structural change:** the
nullable `sales.service_request_id` column (Decision 70) — still exactly the 8 tables,
the four OTG statuses of Decision 10, and no payment table or field. Business context:
a Rescue job is typically paid for on site once the actual work is known, and the
admin then records the products and services actually used as an ordinary POS sale;
before this, a sale could not be traced back to the Rescue it came from.

70. **A sale may be linked to at most one Rescue request, and a request to at most one
    sale.** `sales.service_request_id` is a nullable foreign key to
    `service_requests.request_id`, **UNIQUE**, `ON DELETE RESTRICT ON UPDATE RESTRICT`
    (one-to-one, optional on both sides). `NULL` = an ordinary **in-shop** sale; a
    request id = a **Rescue** sale. The sale's *source* is **derived** from this column
    only — there is no source / type column and no separate table. Sales recorded
    before the column existed stay `NULL` (shown as in-shop); old links are never
    guessed or backfilled. Existing databases are upgraded with
    `vulcatrack/database/migrations/2026-09-28-sales-service-request.sql`.
    *Customer identity and sale source are independent:* `sales.customer_id` (NULL =
    walk-in, Decision 14) says **who** bought; `service_request_id` says **where the
    sale came from**. A registered customer may buy in the shop (registered + in-shop);
    a Rescue sale always has the Rescue's registered customer and is never a walk-in.
    *This clarifies Decision 13 and scope area 3 ("in-shop sales"):* sales are still
    recorded only by an admin at the POS — there is still no customer checkout — but a
    recorded sale may be for a Rescue job performed on site as well as for an in-shop
    transaction.
71. **A sale can be recorded for a Rescue request that is `accepted` or `completed`,
    never for one that is `pending` or `rejected`, and only while the request has no
    sale.** `completed` is allowed on purpose: the admin may close the request first and
    record its sale afterwards (a late financial entry), because `completed` is final
    (Decision 65) and an omission could otherwise never be corrected. A second sale for
    the same request is refused with a clear message; the UNIQUE key of Decision 70 is
    the final guarantee under concurrency. The rule lives in `OtgStatus::canRecordSale()`
    and is enforced by `SaleService` inside the checkout transaction, which locks the
    request row before any item row.
72. **The Rescue's customer is authoritative for its sale.** A Rescue sale is recorded
    for `service_requests.customer_id`. A walk-in or a different customer is **refused**
    (never silently replaced) — it means the screen was stale or in the wrong context.
    The POS locks the customer while recording a Rescue sale, and `SaleService`
    re-validates it at checkout.
73. **Recording a sale does not change the Rescue request.** It never changes the
    request's status, `admin_id`, Tireman or `updated_at`, and it is not the completion
    action. Completing the request stays a separate, explicit admin action (Decision
    65); the system enforces no order between the two (the usual order is: sale
    recorded while accepted, then *Mark as completed* — but a completed request may
    receive its sale later, Decision 71). A Rescue sale is an ordinary sale in every
    other respect: frozen unit prices (Decision 17), product-only stock deduction
    (Decision 16), full rollback on failure (Decision 61), and `sale_date` = the time
    the sale is recorded (Decision 35 — a late entry is not backdated to the request or
    completion date). Reports and the Dashboard therefore include Rescue sales with no
    separate calculation.
74. **A request with a linked sale can no longer be rejected.** A rejected request with
    a recorded, unchangeable sale would be a contradictory final state. The guarded
    reject update itself refuses a request that has a sale (from `pending` or
    `accepted`), so a stale screen cannot get past it; the Reject action is also no
    longer offered. While still accepted, such a request can have its Tireman
    reassigned and can be marked completed. There is **no unlink** feature — recorded sales stay unchangeable.
75. **Rescue sales use the existing POS (no second cart or checkout).** From the admin
    Rescue detail page, *Record sale in POS* (a POST with CSRF — never a plain link)
    puts the POS session cart into Rescue mode for that request, only from a clean cart
    (no items, no registered customer chosen, no other Rescue active) so a sale in
    progress is never overwritten; reopening the same Rescue changes nothing. The
    request id is held in the session cart, never trusted from a form. Because every
    browser tab shares one session cart, each cart-changing POS form states the
    context it was rendered for (ordinary sale or a specific Rescue) and is refused if
    the cart's context has changed since, so an old tab cannot change a different
    sale. If the request stops being eligible while its sale is in progress, the POS
    shows why and does not complete it; *Cancel sale* leaves Rescue mode. A completed
    checkout, or a cancel, returns the POS to an ordinary walk-in sale.
76. **A Rescue sale records a sale, not a payment method.** The `sales` table still
    stores no payment method, amount received, change or payment status (Decisions
    30/54); the in-person POS workflow is cash, but that is not stored data. Screens
    therefore say **"Sale recorded"** (with the sale number, total, date and recording
    admin) — never "Payment recorded" or "Cash" as a stored fact. Sales History shows
    the derived source (**In-shop** / **Rescue #N**, linking to the request) beside the
    independent Customer column, and the Transaction Summary of a Rescue sale shows
    "Rescue request #N" for traceability — still a transaction reference, not an
    official BIR invoice (Decision 50). GCash / online payment remains out of scope
    (Decision 12).

## Confirmed Project Decisions — 2026-09-29 (Phase 7.4b-e2: customer profile picture)

Approved by the owner for Phase 7.4b-e2 (the Customer Profile Figma alignment).
**One structural change:** the nullable `customers.avatar_filename` column — still
exactly the 8 tables; no avatar / media / upload table.

77. **A customer may upload their own profile picture; it is private and optional.**
    - **Who / where:** only a signed-in customer, for their own account, from the
      Profile page (upload / replace / remove; POST + CSRF, each its own form).
      Admins, Tiremen and guests have no avatar feature.
    - **Stored reference:** `customers.avatar_filename VARCHAR(64) NULL` holds only
      the server-generated file name `<customer_id>_<32 random hex>.<jpg|png|webp>` —
      never a path, URL or the uploader's own file name. `NULL` = no picture.
      Existing databases are upgraded with
      `vulcatrack/database/migrations/2026-09-29-customers-avatar-filename.sql`.
    - **Files are private:** kept under `vulcatrack/storage/avatars/` (created on
      demand, git-ignored, denied to the web by `storage/.htaccess`) and delivered
      only by `customer/avatar.php`, which serves the **signed-in customer's own**
      picture and takes no id / file name / path from the request. There is no
      public avatar URL, gallery or directory listing.
    - **Accepted files:** JPEG, PNG or WebP up to **5 MB**, identified from the file
      contents (`finfo` + `getimagesize`, which must agree) — never from the file
      name or the browser's Content-Type. GIF, SVG, BMP, PDF and everything else
      are refused. No resizing, cropping or re-encoding (no GD / Imagick
      dependency): the original is kept and cropped for display by CSS.
    - **Replace / remove order:** a new picture is written under a new name, then
      recorded in the database, and only then is the old file deleted; a failed
      database update removes the new file and keeps the old picture. Remove clears
      the column, then deletes the file. A file that cannot be deleted is left as a
      harmless, unreferenced orphan.
    - **Display:** only the shared account panel (Profile and My Vehicles). If there
      is no picture, or the stored name is malformed, or the file is missing /
      unreadable, the customer's **initials** are shown — never a broken image.
      Not shown in the header, Dashboard, Rescue screens, or anywhere in Admin.
    - **Not part of this decision:** Notifications, Tracking, a Settings module,
      editable email, avatars for admins / Tiremen, galleries or multiple pictures.

**2026-10-08 implementation clarification to Decision 77 (display only):** the later
committed Customer header intentionally displays the signed-in customer's own
avatar (or initials fallback), in addition to the shared account panel. The
original display bullet above records the earlier approved scope; it is retained
as history. Private, owner-only serving and the no-Admin/Tireman-avatar boundary
still apply. See `customer_top.php` and commit `7b5c2ee`.

## Confirmed Project Decisions — 2026-09-29 (Phase 7.4c: completed Rescue feedback)

Approved by the owner for Phase 7.4c. **One structural change:** three nullable
feedback columns on `service_requests` plus a named rating CHECK — still exactly the
8 tables and the four statuses of Decision 10; no feedback / review / rating table.

78. **A customer may give one-time feedback on their own completed Rescue — feedback
    on the request, not a Tireman rating system.**
    - **Who / when:** only the signed-in customer who owns the request, only once it is
      `completed` with a Tireman on record (`tireman_id` set), and only once. Pending,
      accepted and rejected requests cannot be rated. It is optional: the customer may
      rate later or never ("Skip for now" writes nothing; there is no "skipped" state).
    - **What:** a required rating of **1–5** whole stars and an optional comment of up
      to **500 characters** (a line break counts as one; blank / whitespace-only is
      stored as `NULL`). Stored on the request as `feedback_rating TINYINT UNSIGNED`,
      `feedback_comment VARCHAR(500)` and `feedback_submitted_at DATETIME` (set by the
      database), all nullable; the named CHECK `chk_service_requests_feedback_rating`
      allows only NULL or 1–5. Existing databases are upgraded with
      `vulcatrack/database/migrations/2026-09-29-service-requests-feedback.sql`.
    - **One-time, never overwritten:** saved by one guarded UPDATE that re-checks owner,
      `completed`, Tireman set and "no feedback yet"; a second tab or double submit
      changes nothing. No editing, deleting or resubmitting in v1.
    - **Changes nothing else:** submitting feedback never changes the request's status,
      Tireman, `admin_id` or `updated_at` (which keeps meaning the last admin status /
      assignment change — Decision 67), and never touches sales, sale lines, stock or the
      Transaction Summary. Eligibility does not depend on a sale (Decision 71 still lets
      the sale be recorded before or after).
    - **The Tireman is context only:** the feedback page shows who serviced the request
      by name (never the phone number after completion — Decision 66's customer view).
      The browser never supplies a Tireman, customer, status or sale.
    - **Who sees it:** the customer on Request Status ("Your feedback", read-only) and
      the admin on the Rescue detail page ("Customer feedback", read-only — no reply,
      moderation, edit or delete).
    - **Still NOT part of the system:** Tireman average scores, rankings, leaderboards,
      public technician review profiles, feedback categories, tips, automated quality
      scoring, or any effect on dispatch or assignment. This clarifies (does not
      overturn) Decision 26 and the "Technician … ratings" out-of-scope item — see
      conflict **C8**.

## Confirmed Project Decisions — 2026-09-29 (Phase 7.4 Chunk 2: Admin Accounts)

Approved by the owner before deployment: the shop should not need a command line to add
another admin. **No schema change** — the existing `admins` table already holds multiple
accounts (UNIQUE, case-insensitive email).

79. **A signed-in admin may create additional admin accounts from an authenticated
    Admin Accounts page** (`admin/accounts.php`).
    - The page lists every admin account (full name, email, created date — never the
      password hash) and holds a **Create Admin Account** form: full name, email, new
      password, confirm new password, and **the signed-in admin's own current
      password**.
    - Creating an account requires the admin session, POST + CSRF, and re-authentication:
      the current password is verified against the admin row of the **trusted session**
      (never an id from the form). Same email / password rules as elsewhere (valid email;
      Decision 44 minimum length; confirmation must match; `Password::hash`). Emails are
      stored trimmed and lowercased; the UNIQUE key is the final duplicate guard.
    - Success redirects back to the page (PRG). The current admin stays signed in; the new
      admin is **not** signed in and uses the normal admin login later.
    - **All admins stay equivalent** — no roles, permission levels or super-admin tier. No
      public admin registration, no email invitations, and no admin edit / delete / disable
      or password reset in this decision.
    - The page is reached from the signed-in identity area of the admin sidebar ("Admin
      accounts"), not from the operational navigation; there is no generic Settings page.
    - `database/seed_admin.php` stays supported for the **first** admin (bootstrap),
      emergency recovery and development. This clarifies Decisions 18 / 40 / 46 (it does
      not reopen public admin sign-up).

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
  *(Clarified by **Decision 78**: customer feedback on a completed Rescue request is in
  scope; a Tireman rating / scoring system still is not.)*
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
  change due are UI-only and not stored (Decision 30). No payment method is stored
  (Decision 76).
- `service_requests` 1 : 0..1 `sales` — `sales.service_request_id` is nullable and
  UNIQUE (Decision 70). `NULL` = in-shop sale; set = the sale recorded for that Rescue.
  Independent of `sales.customer_id` (walk-in vs registered customer).
- `service_requests.eta_minutes` is a frozen snapshot; no route geometry is stored
  (Decisions 32–33).
- `email` is unique within `customers` and within `admins`, checked independently — a
  customer and an admin may share an address (Decision 42). Login identifier is
  `email` only (Decision 41).
- `customers.avatar_filename` is nullable (Decision 77): only a generated file name
  for the customer's own private profile picture under `storage/avatars/`; `NULL`
  = no picture (initials shown). The image itself is never stored in the database.
- `service_requests.feedback_rating` / `feedback_comment` / `feedback_submitted_at` are
  nullable (Decision 78): the owner's one-time feedback on a completed request, rating
  limited to 1–5 by `chk_service_requests_feedback_rating`. All NULL = no feedback.
  Submitting it never changes the request's status, Tireman, `admin_id` or `updated_at`.

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
- ~~Whether **`service_requests.admin_id`** should remain nullable throughout the workflow or
  become mandatory once accepted.~~ **Resolved 2026-09-28 (Decision 67):** it stays
  nullable (pending = not yet handled) and holds the last admin who changed the
  request's status or Tireman assignment.
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

**Current-state addendum (2026-10-08):** The later committed Customer header
shows the owner-only avatar; Admin Accounts and completed-Rescue feedback are
built. Reports include Sales Performance and Sales by Source, and sold item
types are protected. The fresh complete-suite baseline at `29e30ce` is
**263 passed / 0 failed / 4,672 assertions / 49 files**. The older
**233 / 0 / 3,486 / 41** run below is correctly dated to `7d158e6`;
the separate **263 / 0 / 4,668 / 49** handoff figure is historical.
Phase 7 remains open; these facts do not imply phase closeout.

- **Phases 1–4 complete (2026-09-01); Phase 4.5 stabilization pass done
  (2026-09-06).** Application Foundation, Database Schema, Authentication &
  Authorization, Customer-Side Functionality.
- **Phase 5 (POS & inventory) COMPLETE (2026-09-23).** The minimal Admin shell
  (Decision 53), the full Inventory module (unified `items`, Decisions 15/52/56),
  integer-centavo `Money` (Decision 55), the **Sales foundation** —
  `SaleRepository` + atomic `SaleService` (Decisions 61–64) — the **POS**
  (`admin/pos.php`: session cart (Decision 57), optional existing-customer link
  or walk-in (Decision 51), cash tender/change never stored (Decision 54),
  checkout through `SaleService`, product-only stock deduction) and the
  printable **Transaction Summary** (`admin/transaction-summary.php`,
  Decision 50 — non-official). Closed after an end-to-end walkthrough through
  Apache, a phone/LAN check and a documentation pass.
- **Phase 6 (admin OTG handling, Tireman assignment, reporting — Decision 49)
  COMPLETE (closed 2026-09-28).** Chunk 6.1 (2026-09-28): admin **Tireman management**
  (Decision 24) — `admin/tiremen.php` + `admin/tireman-edit.php` on
  `TiremanRepository`: view, Active / Inactive / All filter, add, edit,
  activate / deactivate; no hard delete; `listActive()` ready for assignment.
  Chunk 6.2 (2026-09-28): **read-only** admin Rescue view (Decision 9) —
  `admin/rescue.php` (status filter) + `admin/rescue-view.php` (customer,
  vehicle, stored location + frozen ETA, Tireman, handling admin, read-only
  straight-line map — Decision 33); customer reads stay owner-scoped.
  Chunk 6.3 (2026-09-28): admin Rescue **status actions** on
  `admin/rescue-view.php` — accept (with an active Tireman), reassign,
  reject, complete — under **Decisions 65–68**; the customer booking page no
  longer says "on the way" on a completed request.
  Sales History (2026-09-28): read-only `admin/sales.php` — all recorded
  sales newest first, optional From / To filter on `sale_date` (Decision 35),
  each row linking to the Transaction Summary (Decision 50).
  Sales Reports (2026-09-28): read-only `admin/reports.php` — the same
  From / To filter; Transactions and Total Sales, Daily Sales grouped by
  `sale_date`, Items Sold with frozen revenue (Decisions 17, 35, 49).
  No schema change.
- **Phase 7 (integration, testing, bug fixing, presentation readiness) IN
  PROGRESS.** Chunk 7.1 (2026-09-28): inventory edit integrity — a sold item's
  type is fixed (**Decision 69**) and a stale item edit cannot overwrite stock a
  sale has changed. Chunk 7.2 (2026-09-28): the public landing page was rebuilt
  (accurate copy — request status and a one-time ETA, no live tracking) and the
  Admin Dashboard shows total sales today, low-stock alerts and pending rescues.
  Chunk 7.3 (2026-09-28): 7.3a–7.3c aligned the admin area with the approved Figma
  (shared sidebar shell and black / white / red admin theme, page layouts, the POS
  as catalogue cards beside a Current Sale panel) — presentation only, customer and
  guest pages unchanged. 7.3d added **Rescue sales** (**Decisions 70–76**): the sale
  for an accepted or completed Rescue request is recorded through the ordinary POS
  and linked to the request (`sales.service_request_id`); Sales History shows its
  source and the Transaction Summary its request. Committed as `b7e595d` (schema),
  `0d2f86e` (domain rules) and `7d158e6` (UI), and re-checked after commit with a
  browser run of the whole workflow. Chunk 7.4 (2026-09-29): admin / POS
  responsive refinement (7.4a) and the customer area aligned with the Figma
  (7.4b: shell + Dashboard, Log in / Sign up, Book a Rescue, request status + My
  Bookings, My Vehicles under Profile, and Profile with an optional private
  profile picture — **Decision 77**, `customers.avatar_filename`). 7.4c added
  one-time customer feedback on a completed Rescue (**Decision 78**,
  `service_requests.feedback_*`; read-only for the admin; not a Tireman rating).
  Its deferred-item backlog is consolidated in
  `docs/PROJECT-CONTEXT.md` §16.5.
- Repo on `main` at `C:\IPT102`, pushed to
  `https://github.com/Iyani99/VulcaTrack.git`; app at `C:\IPT102\vulcatrack\`
  served via a Windows junction from `C:\xampp\htdocs\vulcatrack`.
- Database: the 8 tables from `docs/ERD/schema.dbml` are built
  (`vulcatrack/database/schema.sql`); no seed data ships (the owner keeps a
  personal test account). The only structural change since Phase 2 is the
  nullable, UNIQUE `sales.service_request_id` (Decision 70); an existing database is
  upgraded once with `vulcatrack/database/migrations/2026-09-28-sales-service-request.sql`.
- **Test harness (Phase 4.5, extended each chunk):** `vulcatrack/tests/` —
  dependency-free CLI runner (`php vulcatrack/tests/run.php`), **233 passed, 0
  failed, 3486 assertions across 41 files** (unit, integration — schema +
  repositories + Auth + inventory + sales + Rescue-sale rules + Sales History /
  Reports reads + Tiremen + admin request reads and guarded status changes + DB
  session, and end-to-end HTTP incl. the POS, the Rescue-sale workflow, Transaction
  Summary, Sales History, Reports, Tiremen and Rescue pages and actions). All green
  as of 2026-09-28 (`7d158e6`).
- Auth (Decisions 41–47): customer + admin login/logout, CLI
  `vulcatrack/database/seed_admin.php`, hardened sessions, guards.
- Customer side (Decision 48): `vulcatrack/customer/*` — dashboard, profile,
  saved vehicles (soft-delete), OTG rescue submission with a frozen-snapshot
  ETA, request history + customer-facing status. No schema change; OTG requests
  are always created `status = 'pending'`.
- Admin side (Phase 5; Tiremen, Rescue, Sales History and Reports added in
  Phase 6): `vulcatrack/admin/*` — dashboard, inventory + item edit, POS,
  transaction summary, Sales History, Reports, Tiremen, Rescue (list, detail,
  status actions, and — Phase 7.3d — the Rescue's sale). Still exactly the 8 tables.
- ERD exists (PNG + text schema `docs/ERD/schema.dbml`).
- Use-case diagram exists (PNG; changes pending — see Required Diagram Changes).
- Six flowcharts exist.
- Database Notes exist.
- Figma prototype exists externally and is the team's primary UI/UX reference.
- **No finalized requirements / SRS document exists.** `docs/requirements/` exists but
  is intentionally empty; a finalized requirements/SRS, if one is produced, goes there.

The next development phase begins only when explicitly instructed. Work proceeds one
phase at a time; the next phase is never auto-started.

---

## Source-of-Truth Index

| Path | Purpose | Notes |
|---|---|---|
| `docs/VulcaTrack-Database-Notes_1.md` | Field-by-field explanation of the database design, business rules, integrity rules, assumptions, and unresolved questions. Read alongside the schema. | Has a 2026-08-31 revision note at the top. |
| `docs/ERD/schema.dbml` | **Maintainable source of truth for the database schema** (DBML text). Basis for the eventual MySQL implementation. | Decision 38. Added 2026-08-31. |
| `docs/ERD/VulcaTrack-ERD_1.png` | Entity-relationship diagram (visual aid). | Image + `.svg`. **Regenerated 2026-09-29** from `schema.dbml` by `docs/diagram-src/erd.php` (Required Diagram Changes D1 — refreshed). |
| `docs/VulcaTrack-Use-Case-Diagram-Paper.png` | Paper Customer/Admin use cases. | Generated from `docs/diagram-src/use-case-paper.php`; the separate `-Detailed` figure covers more current functions. Neither gives Tiremen a login. |
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
| C7 | "In-shop sales" wording (scope area 3, Decision 13, Database Notes §1 / §2 / §4) vs Rescue-linked sales | **RESOLVED** | Logged 2026-09-28 with Decisions 70–76: the older wording read as if every sale were an in-shop counter transaction, which became **AMBIGUOUS** once a sale can be the one recorded for an on-site Rescue job. Resolved by clarification, not rewrite: Decision 13 and scope area 3 carry a note pointing to Decision 70 (sales are still recorded only by an admin at the POS), and the Database Notes got a revision note plus inline additions. *Walk-in* stays a customer-identity term (`customer_id` NULL), never a synonym for *in-shop* (`service_request_id` NULL). |
| C6 | `service_requests.tireman_id` / `admin_id` note wording vs Decisions 65–68 | **RESOLVED** | Logged 2026-09-28 when Decisions 65–68 were added: the notes in `docs/ERD/schema.dbml`, the `vulcatrack/database/schema.sql` comments and `docs/VulcaTrack-Database-Notes_1.md` (§2, §3, §11) still described the older "assign after acceptance / NULL while rejected" wording. Corrected the same day on owner approval — **comment / note wording only**, no table, column, type, constraint, FK, CHECK or index changed. |
| C8 | Decision 26 / out-of-scope "no ratings" for Tiremen vs customer feedback on completed Rescues (Phase 7.4c) | **RESOLVED** | Logged 2026-09-29 with **Decision 78**: Decision 26 ("Tiremen … no ratings"), the out-of-scope item "Technician … ratings" and PROJECT-CONTEXT §7 read as if any rating were excluded, which **CONFLICTED** with the owner-approved customer feedback feature. Resolved by clarification, not rewrite: Decision 78 defines the feature as one-time feedback on the customer's own completed request, stored on `service_requests`, with the Tireman shown only as context and no Tireman scores / averages / rankings / dispatch effects; Decision 26 and the out-of-scope item carry a note pointing to it. |
| C9 | Decision 77 display wording vs later Customer header | **RESOLVED for current documentation** | The dated implementation clarification after Decision 77 records the later committed header avatar without changing the historical bullet or the privacy boundary. |

### Remaining conflicts after this update

- **No unresolved *CONFLICT* remains between the confirmed decisions and the artifacts.**
- **POSSIBLY OUTDATED flowcharts** (separate from the current paper diagrams):
  OTG flowcharts 2 & 5 and POS flowchart 4 — see
  [Required Diagram Changes](#required-diagram-changes). The ERD and Level 1 DFD
  remain the 2026-09-29 generated versions; the Level 0, Book a Rescue activity,
  POS sequence and use-case diagrams were refreshed for the paper on 2026-10-08.
- **N8 (Figma)** stays open until the prototype is provided.

Any future conflict must be reported and classified here (or in a superseding decision
record), never silently reconciled.

---

## Required Diagram Changes

Documented here for whoever owns the diagram tooling. *2026-09-29:* the ERD (D1) and the
activity / POS sequence / Level 1 DFD items of D6 are **refreshed** — regenerated from the
committed code and `schema.dbml` by the generators in `docs/diagram-src/` (see its README).

**2026-10-08 paper presentation update:** D2 and D7 are now refreshed. The
activity diagram is deliberately scoped to the Customer's **Book a Rescue**
submission with two lanes; the POS sequence uses three conceptual participants.
The 2026-09-29 D6 descriptions below remain historical records of that earlier
generation. Level 1 remains available as the detailed DFD; refreshed Level 0 is
the preferred context figure for the paper.

| ID | Artifact | Classification | Required change |
|---|---|---|---|
| D1 | `docs/ERD/VulcaTrack-ERD_1.png` | **REFRESHED 2026-09-29** | *Done:* regenerated from `schema.dbml` (`docs/diagram-src/erd.php`, `.svg` + `.png`), covering (a)–(g) below. Original requirement: regenerate from `docs/ERD/schema.dbml`. Must show: (a) new `tiremen` table (`tireman_id` PK, `name`, `contact_number`, `is_active`, `created_at`, `updated_at`); (b) `service_requests.tireman_id` nullable FK → `tiremen`; (c) `items.is_active` and `vehicles.is_active`; (d) `customers → service_requests` as **`1 : 0..N`** (not `1 : 1..N`); (e) *(Decision 70)* `sales.service_request_id` — nullable, UNIQUE FK → `service_requests`, drawn as **`service_requests 1 : 0..1 sales`**; (f) *(Decision 77)* `customers.avatar_filename` — nullable `varchar(64)`, no relationship; (g) *(Decision 78)* `service_requests.feedback_rating` / `feedback_comment` / `feedback_submitted_at` — nullable, no relationship. |
| D2 | `docs/VulcaTrack-Use-Case-Diagram-Paper.png` | **REFRESHED 2026-10-08** | Current generated paper figure uses one Inventory use case, Manage Tiremen and Assign Tireman to Request, shared Log In and bottom Log Out. The old proposed Manage Customer Accounts is omitted because it is not implemented; Admin Accounts is shown. Tireman has no application login. |
| D3 | `docs/flows/VulcaTrack-2-Customer-Flow.png`, `docs/flows/VulcaTrack-5-OTG-Request-Flow.png` | POSSIBLY OUTDATED | Admin branch: add an "Assign Tireman" step after "Set Status: Accepted" *(per Decision 66 the Tireman is chosen in the same step as acceptance; also show accepted → rejected and the final states, Decision 65)*. Customer view after acceptance: show assigned Tireman name + contact number, "Tireman is on the way", the stored ETA, and the route/map. |
| D4 | `docs/flows/VulcaTrack-4-POS-Flow.png` | POSSIBLY OUTDATED *(was CONSISTENT — annotate)* | Note that "Enter Payment Amount / Payment Sufficient? / Calculate Change" are UI-only (not persisted) and "Generate Receipt" is a printable HTML view with no receipt table. *(Decisions 70–76)* Add the optional Rescue entry: *Record sale in POS* from an accepted / completed request → customer locked to the request's customer → the same cart and checkout → sale linked to the request; the request's status is not changed by the sale. |
| D5 | `docs/flows/VulcaTrack-6-Inventory-Flow.png` | CONSISTENT | No change. "Deactivate / Delete Item" is now backed by `items.is_active`. |
| D6 | `docs/flows/VulcaTrack-5-OTG-Request-Flow.png`, `docs/flows/VulcaTrack-Activity-Diagram-OTG.*`, `docs/flows/VulcaTrack-Sequence-Diagram-POS.*`, `docs/flows/VulcaTrack-DFD-1-Level1.*` | **PARTLY REFRESHED 2026-09-29** — activity, POS sequence and Level 1 DFD done; OTG flowchart 5 still outdated (also D3) | *(Decisions 70–76, logged 2026-09-28)* OTG flow / activity diagram: after the job, the admin may record the request's sale in the POS (accepted or completed) and separately mark it completed; a request with a sale cannot be rejected. POS sequence diagram: optional Rescue context (read the request, lock its customer, store `service_request_id`). Level 1 DFD: the POS process now reads `service_requests` (D6) to link a sale — no new data store. No payment store in any diagram. *Done 2026-09-29:* the activity diagram (accept + assign in one action, accepted → rejected, completion separate from the sale, optional feedback), the POS sequence diagram (one atomic transaction, product-only stock deduction, Transaction Summary, tender not stored) and the Level 1 DFD (Rescue sale context P4 → P5, feedback into D6, Sales History & Reports, and — Decision 79 — Admin Accounts in process 1). |
| D7 | `docs/flows/VulcaTrack-DFD-0-Context.*` | **REFRESHED 2026-10-08** | Current context diagram uses Customer and Admin only, printable **Transaction Summary**, completed-Rescue feedback, Sales History / Reports, and Admin Accounts; generated from `docs/diagram-src/dfd-level0.php`. |

**2026-10-08 diagram organization clarification (status only):** The D6 paths
above identify the files reviewed in 2026-09-29. Their current detailed
successors have `-Detailed` names; the professor-facing Book a Rescue Activity,
Process Sale Sequence, and Use Case have separate `-Paper` names. The old use
case image contained a proposed Customer-account management function and is
superseded by generated current versions. See `docs/diagram-src/README.md` for
the maintained source/output mapping. The shared ERD and technical Level 1 DFD
remain available; paper simplification does not remove implemented behavior.

---

## Revision History

### 2026-09-29 — Paper diagram refresh (status only; no decision change)

- **Required Diagram Changes:** D1 (ERD) refreshed; D6 partly refreshed (activity diagram,
  POS sequence diagram, Level 1 DFD); new **D7** — the Level 0 context DFD must be balanced
  with the new Level 1. The Level 1 DFD shows Admin Accounts (Decision 79) as part of
  process 1. Diagrams are now generated from `docs/diagram-src/` (the ERD straight from
  `schema.dbml`).
- No decision change, no schema change.

### 2026-09-29 — Phase 7.4 Chunk 2: Admin Accounts (**decision change: Decision 79 added**)

- **Decision change (owner-approved):** added **Decision 79** — an authenticated Admin
  Accounts page lists admins and creates additional ones (current-password
  re-authentication, CSRF, PRG; all admins equivalent; no roles, public sign-up, edit /
  delete or reset). **Decision 46** gained a clarification note: `seed_admin.php` remains
  the first-admin bootstrap / recovery / development path.
- **Schema:** none — still exactly 8 tables.

### 2026-09-29 — Phase 7.4c: completed Rescue feedback (**decision change: Decision 78 added**)

- **Decision change (owner-approved):** added **Decision 78** — the owner of a completed,
  serviced Rescue request may give one-time feedback (1–5 stars + optional comment ≤ 500
  characters), stored on the request; read-only for the customer and the admin; never
  changes the request or its sale; not a Tireman rating system. **Decision 26** and the
  "Technician … ratings" out-of-scope item gained a clarification note (no rewrite).
- **Conflict:** new **C8** (Decision 26 "no ratings" vs the feedback feature) — resolved
  by clarification.
- **Schema:** three nullable columns + CHECK `chk_service_requests_feedback_rating` on
  `service_requests` — `schema.dbml`, `schema.sql` and a one-off migration
  (`2026-09-29-service-requests-feedback.sql`) updated. Still exactly 8 tables and four
  statuses.
- **Status:** Current Project Status (Chunk 7.4), Database Context and Required Diagram
  Changes (D1 item (g)) updated. ERD image not regenerated (Phase 7.5).

### 2026-09-29 — Phase 7.4b-e2: customer profile picture (**decision change: Decision 77 added**)

- **Decision change (owner-approved):** added **Decision 77** — a signed-in customer
  may upload / replace / remove their own profile picture; JPEG / PNG / WebP up to
  5 MB verified from the file contents; files private under `storage/avatars/`,
  served only to their owner by `customer/avatar.php`; initials when there is no
  usable picture; no resizing. No existing decision was renumbered.
- **Schema:** one nullable column `customers.avatar_filename VARCHAR(64)` —
  `schema.dbml`, `schema.sql` and a one-off migration
  (`2026-09-29-customers-avatar-filename.sql`) updated. Still exactly 8 tables.
- **Status:** Current Project Status (Chunk 7.4), Database Context and Required
  Diagram Changes (D1 item (f)) updated. ERD image not regenerated (Phase 7.5).

### 2026-09-28 — Phase 7.3d: Rescue sales (**decision change: Decisions 70–76 added**)

- **Decision change (owner-approved):** added **Decisions 70–76** — the optional
  one-to-one link between a sale and a Rescue request (`sales.service_request_id`,
  nullable + UNIQUE, RESTRICT), accepted / completed eligibility with late entry, the
  Rescue's customer as the sale's customer, sale recording independent of the
  request's status, no reject once a sale is linked (no unlink), Rescue mode in the
  existing POS with the clean-cart and stale-tab rules, and "Sale recorded" wording
  with no payment method stored. **Decision 13** and scope area 3 gained a
  clarification note (no rewrite). No existing decision was renumbered.
- **Schema:** one nullable column + UNIQUE key + FK on `sales` (7.3d-a, `b7e595d`);
  `schema.dbml`, `schema.sql` and a one-off migration were updated in that commit.
  Still exactly 8 tables; no new status; no payment table or field.
- **Status:** 7.3d-b domain rules (`0d2f86e`) and 7.3d-c admin UI / POS workflow
  (`7d158e6`) committed and re-verified after commit. Tests **233 passed / 3486
  assertions / 41 files**. **Current Project Status**, Database Context, Known
  Conflicts (new **C7**, resolved) and Required Diagram Changes (D1, D4 updated; new
  **D6**) updated. Diagrams themselves not regenerated (Phase 7.5).

### 2026-09-28 — Phase 7.2: landing page + Admin Dashboard cards (status only; no decision change)

- **Current Project Status** updated. The public landing page follows the approved
  Figma layout but describes only approved behavior: request status, the assigned
  Tireman and a one-time ETA (Decisions 32/48), with no live tracking. The Admin
  Dashboard's three cards reuse existing reads (Decision 35 for "today's" sales; the
  Inventory low-stock rule; pending = `status = 'pending'`). Tests **206 passed / 2958
  assertions / 40 files**.
- No renumbering, no new decision, no schema change.

### 2026-09-28 — Phase 7.1: inventory edit integrity (**decision change: Decision 69 added**)

- **Decision change (owner-approved):** added **Decision 69** — an item's `item_type`
  is fixed once it appears on a recorded sale; unsold items may still switch between
  product and service. No existing decision was rewritten or renumbered.
- **Status:** Phase 7 in progress; Chunk 7.1 implemented. `ItemRepository::update()`
  now enforces the type lock (up front and inside the UPDATE) and accepts the edit
  form's loaded type + stock as an optimistic guard, so a stale edit cannot overwrite
  stock a POS sale changed; a matched save with nothing to change is not mistaken
  for a stale one. Tests **204 passed / 2873 assertions / 38 files**.
- No schema change.

### 2026-09-28 — Phase 6 closed (status only; no decision change)

- **Current Project Status** updated: Phase 6 is **COMPLETE**. A closeout review
  found every Phase 6 feature built and tested — Tireman management (Decision 24),
  the admin Rescue view and status actions (Decisions 9, 33, 65–68), Sales History
  and Sales Reports (Decision 49). No required Phase 6 item is missing. The Admin
  Dashboard is unchanged; its Figma alignment belongs to Phase 7. The admin file
  list and test summary were brought up to date (**198 passed / 2762 assertions /
  36 files**). Phase 7 is next; its deferred backlog is consolidated in
  `docs/PROJECT-CONTEXT.md` §16.5.
- No renumbering, no new decision, no schema change, no code change.

### 2026-09-28 — Phase 6: admin Sales Reports (status only; no decision change)

- **Current Project Status** updated: the reporting half of **Decision 49** is
  built — `admin/reports.php`, read-only, over an optional From / To range on
  `sales.sale_date` (Decision 35; all recorded sales by default). It shows the
  transaction count and total sales (stored `total_amount`), Daily Sales grouped
  by `sale_date`, and Items Sold (quantity + revenue from the frozen
  `sale_items.subtotal`, Decision 17). It deliberately has no item-type column or
  product/service totals, because `items.item_type` stays editable. It also has
  no charts, exports or pagination. Sales History is unchanged and separate.
  Tests **198 passed / 2762 assertions / 36 files**.
- No renumbering, no new decision, no schema change.

### 2026-09-28 — Phase 6: admin Sales History (status only; no decision change)

- **Current Project Status** updated: the admin can now list every recorded sale
  (`admin/sales.php`) — read-only, newest first, with an optional From / To
  filter on `sales.sale_date` (the reporting date, Decision 35), showing the
  cashier, the customer or "Walk-in" (Decision 14) and the stored total. Each
  row opens the existing Transaction Summary (Decision 50), which stays the one
  sale-detail page. Implements the history half of Decision 49; Sales Reports
  are still to come. Tests **191 passed / 2575 assertions / 34 files**.
- No renumbering, no new decision, no schema change.

### 2026-09-28 — Phase 6.3: Rescue status rules (**decision change: Decisions 65–68 added**) + status

- **Decision change (owner-approved):** added **Decisions 65–68** — the four allowed
  OTG transitions with `rejected` / `completed` final (65); acceptance requires an
  active Tireman in the same action, reassignment only while accepted (66);
  `admin_id` = the last admin who changed status or assignment (67); the Tireman
  stays on final requests (68). **Decision 25** gained a refinement note (not
  rewritten). The `admin_id` open question moved to resolved. Intended-flow steps
  11–12 and diagram change D3 annotated.
- **Conflict C6** logged and **resolved** the same day: the `tireman_id` / `admin_id`
  note wording in `schema.dbml` / `schema.sql` / Database Notes predated Decisions
  65–68 and was corrected on owner approval (comments only — no structural change).
- **Clarification (owner-approved, same day, before commit):** Decision 65 now states
  that `accepted → completed` requires an assigned Tireman (it may since have been
  deactivated); a legacy accepted request without one is assigned first.
- **Status only:** Phase 6 Chunk 6.3 implemented (admin accept / reassign / reject /
  complete via guarded updates; customer booking page fixed so a completed request
  never says "on the way"). Tests **181 passed / 2257 assertions / 31 files**.
- No renumbering, no schema change.

### 2026-09-28 — Phase 6 Chunk 6.2: read-only admin Rescue view (status only; no decision change)

- **Current Project Status** updated: the admin can now **view** every OTG
  request (list + detail) as Decision 9 allows — customer, vehicle, problem,
  stored coordinates, the frozen ETA (Decision 32), the assigned Tireman and
  handling admin, and a read-only straight-line map (Decision 33; no routing).
  **Read-only**: no status changes and no Tireman assignment yet. Customer reads
  remain owner-scoped. Tests **168 passed / 1980 assertions / 29 files**.
- No renumbering, no new decision, no schema change.

### 2026-09-28 — Phase 6 Chunk 6.1: Tireman management (status only; no decision change)

- **Current Project Status** updated: Phase 6 is **in progress**. Chunk 6.1
  implements the Tireman management already approved by **Decision 24** (view,
  add, edit, activate / deactivate; soft only — Decisions 27–29). Tiremen remain
  non-login records (Decisions 22–26). `TiremanRepository::listActive()` offers
  only active Tiremen for the future assignment step (Decision 28). No Tireman
  assignment, no `service_requests` change. Tests **162 passed / 1668
  assertions / 27 files**.
- No renumbering, no new decision, no schema change.

### 2026-09-23 — Phase 5 closed (status only; no decision change)

- **Current Project Status** updated: Phase 5 (POS & inventory) is **COMPLETE** —
  admin shell, Inventory, `Money`, `SaleRepository` / `SaleService`, the POS
  (session cart, optional customer / walk-in, cash tender / change, atomic
  checkout, product-only stock deduction) and the printable Transaction Summary.
  Test harness 156 passed / 1396 assertions / 25 files. Stale
  "`docs/requirements/` does not exist" wording corrected (it exists, empty).
- Closing verification: end-to-end walkthrough in a real browser through Apache,
  database-verified (one sale per checkout, frozen unit prices, product stock
  deducted, service stock untouched); phone / LAN check at 360–1280 px.
- Known limitations deferred at close (not decision changes): Transaction Summary
  names are joined live (no name snapshot in the approved schema — prices, totals
  and dates are frozen); no sales lookup / history screen until Phase 6
  (Decision 49). Details in `PROJECT-CONTEXT.md` §16.4.
- No renumbering, no new decision, no schema change.

### 2026-09-23 — Clarifications after the POS UI chunk (Decisions 50 and 62; no new decision)

- **Decision 62** — added an *Expected-total assertion* bullet: `SaleService::checkout()`
  accepts an optional `expected_total_centavos` purely as a stale-state check. It
  is never an authoritative price or total; `SaleService` still computes the total
  from current database prices, and any mismatch fails the checkout with a full
  rollback. The existing server-authoritative pricing rule is unchanged.
- **Decision 50** — added a *non-official status* clarification: the printable
  document is a transaction reference only (neutral title, "not an official BIR
  invoice" note); it is not an Official Receipt or BIR-registered invoice and does
  not replace the shop's legally required invoicing. Official-invoicing / TIN / VAT
  / BIR accreditation is outside the approved scope. Field list, no-receipt-table
  and no-TIN/tax rules unchanged.
- **Current Project Status** refreshed: the POS UI is built (session cart, optional
  customer link, cash tender/change, checkout through `SaleService`); the printable
  transaction document is the remaining Phase 5 work; tests 155 / 1214 / 24 files.
- No renumbering, no schema change, no new table, no code change in this revision.

### 2026-09-08 — Phase 5: Sales foundation — `SaleRepository` + atomic `SaleService` (Decisions 61–63)

- **Decision 61** — `SaleService` is the **single owner of the checkout
  transaction**: it begins / commits / rolls back; repositories share its PDO
  connection and only persist rows. Any failure rolls everything back — no
  partial sale. No nested transactions (`checkout()` refuses to run inside an
  open one).
- **Decision 62** — the **server is authoritative** for price (read from
  `items.price` at checkout, frozen into `sale_items.unit_price`), subtotals,
  `sales.total_amount` (all integer-centavo `Money` arithmetic — no float),
  `sale_date` (app-set at completion), and quantity validation (whole number
  ≥ 1). The caller may choose only item ids, quantities and an optional existing
  `customer_id`; caller-supplied prices / totals / `item_type` are ignored.
- **Decision 63** — **overselling is prevented with `SELECT … FOR UPDATE`** on
  each item row inside the transaction plus a guarded decrement that cannot go
  negative; product lines only, service lines never touch stock; rows locked in
  ascending item-id order. No reservation system / ledger / queue.
- **New code:** `vulcatrack/src/Repository/SaleRepository.php`,
  `vulcatrack/src/Service/SaleService.php`,
  `vulcatrack/src/Service/SaleException.php`; `ItemRepository` gained
  `lockForUpdate()` + `decrementStock()` (both used only by `SaleService`).
  `ItemRepository` / `SaleRepository` are no longer `final` so the (mock-library-free)
  test harness can subclass them to simulate mid-checkout failures.
- **No schema change** — `sales` / `sale_items` / the 8 tables are untouched; no
  new `CHECK` constraint (Decision 56's app-layer limits stand). No POS UI, no
  receipt table, no payment/tender persistence.
- **Pre-commit integrity audit (same day):** four small hardening fixes, no
  redesign — (a) `SaleRepository::MAX_QUANTITY` (the `sale_items.quantity` signed
  `INT` ceiling) is enforced on each line and on the merged per-item total, so a
  pathological quantity is a clean `SaleException` rather than a silent DB clamp
  (the server's *global* SQL mode is non-strict — later addressed by Decision 64);
  (b) the money maths now bounds each line
  with `intdiv` before multiplying and the running total with subtraction before
  adding, so no intermediate can overflow PHP's integer range; (c) new
  `AdminRepository::findById()` (mirrors `CustomerRepository::findById`) lets
  `checkout()` turn a stale/deleted recording-admin session into a clean
  `SaleException` instead of a raw FK `PDOException`; (d) the rollback in the
  `catch` is itself wrapped so a failing `rollBack()` cannot mask the original
  exception. Points audited and found already correct (no change): authoritative
  input surface, frozen-history reads, deterministic ascending-item-id locking
  with no duplicate locks, customer validation inside the transaction.
- **Tests:** `tests/integration/SaleRepositoryTest.php` (4 cases) +
  `tests/integration/SaleServiceTest.php` (23 cases) — happy paths, authoritative
  / frozen pricing, integer-centavo exactness, walk-in vs linked customer,
  quantity/item validation, DB-representable + merged quantity limits, money
  overflow boundaries, stale-admin rejection, `FOR UPDATE` locking, and full
  rollback on header / line / stock-deduction failure.

### 2026-09-08 — Application-scoped strict SQL mode (Decision 64)

- **Decision 64** — prompted by the sales audit finding that this XAMPP server's
  global `sql_mode` is **non-strict** and silently clamped an over-range `INT`.
  `includes/db.php` now adds `STRICT_TRANS_TABLES` to `@@SESSION.sql_mode` on
  every connection it creates — one central place, so app pages, CLI scripts and
  the entire test harness get identical behaviour on any machine and on a future
  host. The statement (see Decision 64 for the full expression) uses
  `IF(FIND_IN_SET(…))` + `CONCAT_WS` + `NULLIF` so it is correct whether the
  inherited mode already has strict, does not, or is empty. **No `my.ini` /
  global change; no schema change; no repo-level `SET sql_mode`.**
- **Why safe:** empirically verified — every completed write path already caps
  each string to its exact column width, and DECIMAL *fractional* rounding (OTG
  `latitude` / `longitude` at DECIMAL(10,7) receiving ~15-dp coordinates) stays a
  warning, not an error, under strict mode, so rescue submission is unaffected.
  All pre-existing tests (HTTP end-to-end included) pass unchanged under the
  strict session.
- **New tests:** `tests/integration/DbSessionTest.php` (5 cases) — the session is
  strict with the other modes preserved, the global is left non-strict, the
  mode-setup expression is correct for all three inherited states (non-strict
  list / already-strict / empty), over-length string → `1406`, over-range int →
  `1264`, high-precision coordinate still stores rounded. Suite now **143 passed
  / 0 failed / 1028 assertions / 22 files**.
- **Standing rule:** new INSERT/UPDATE code still validates string length and
  numeric range at the application layer; the strict session is the backstop.

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
