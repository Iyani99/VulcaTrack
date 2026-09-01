# VulcaTrack — Project Decision Record

**Status:** Authoritative record of CONFIRMED project decisions.
**Last updated:** 2026-09-01
**Last revised:** 2026-09-01 — Phase 4 (Customer-Side Functionality); Decision 48
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
| Backend | PHP |
| Database | MySQL |
| Frontend | HTML, CSS, JavaScript |

**Figma** is the team's primary reference for UI/UX and visual/interface behavior. The
Figma prototype lives **outside** `C:\IPT102` and is provided/accessed separately.

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
    no schema impact. The map is drawn with **Leaflet** vendored at
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
    - sets `config/shop.php` to **sample** coordinates (was `0.0 / 0.0`) so the
      feature is demonstrable — replace with the real shop location before a
      real deployment / graded demo.

---

## Canonical OTG Request Flow (v1)

The intended end-to-end flow, as the text reference until flowcharts 2 & 5 are regenerated
(see [Required Diagram Changes](#required-diagram-changes)):

1. Customer logs in.
2. Customer selects a saved vehicle (or adds one).
3. Customer describes the problem / service needed.
4. Customer's (already required) contact number is on file / confirmed.
5. Customer shares their current location; the browser captures `latitude` / `longitude`.
6. System shows the route from the customer's location to the shop (shop endpoint from
   `config/shop.php`).
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
- Exact handling of **denied geolocation** beyond the documented retry/fallback behavior
  (`latitude`/`longitude` simply stay unset until a successful capture).
- Whether **`service_requests.admin_id`** should remain nullable throughout the workflow or
  become mandatory once accepted.
- Whether **shop location** should eventually become editable through admin settings (i.e.
  move from the `config/shop.php` constant to a `shop_settings` table). Config-value
  treatment is confirmed for v1 (Decision 37); only the *future* editable option is open.
- Exact **UI treatment of saved-vehicle management**.
- **`category`** as a plain field on `items` vs. its own table (currently: plain nullable
  field).
- Whether **Admin can manually create customer accounts** (not modeled as a separate flow).
- Whether a finalized **requirements / SRS document** will be produced, and its contents.
  Not started; when created it belongs under `docs/requirements/`.

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

---

## Current Project Status

- **Phases 1–4 complete (2026-09-01):** Application Foundation, Database Schema,
  Authentication & Authorization, Customer-Side Functionality. Phase 5 (POS &
  inventory) is next and begins only when explicitly instructed.
- Repo initialised on `main` at `C:\IPT102`; app at `C:\IPT102\vulcatrack\`
  served via a Windows junction from `C:\xampp\htdocs\vulcatrack`.
- Database: the 8 tables from `docs/ERD/schema.dbml` are built
  (`vulcatrack/database/schema.sql`); **0 application rows**.
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
| `docs/requirements/` | Intended home of a finalized requirements/SRS document, **if/when one is produced.** | Folder does not exist yet; do not create it empty. |
| `source/source.txt` | Placeholder. | Empty; no application code exists yet. |

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
| C5 | Empty legacy stub `project-decisions.md.txt` | **OPEN (cleanup)** | 0-byte file still present in `docs/decisions/`. Deletion is cosmetic and was **not** performed in this task; pending an explicit "yes, delete it" from the project owner. |
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
