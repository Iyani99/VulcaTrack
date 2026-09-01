# VulcaTrack — Database Notes

**Project:** VulcaTrack: Sales and Inventory with On-the-Go Services
**Scope:** Sales monitoring, inventory management, on-the-go vulcanizing/service requests, customer accounts, admin management.
**Stack:** PHP + MySQL (frontend HTML/CSS/JS)

This document explains the database structure and is meant to be read alongside
`docs/ERD/schema.dbml` (the maintainable source of truth) and the ERD PNG (a visual aid).

---

## 0. Revision Note — 2026-08-31 (Decision Review & Documentation Update)

Parts of this document predate the Project Decision Record
(`docs/decisions/project-decisions.md`), which is now the **authoritative** source for
confirmed decisions. Where this document and the decision record differ, the decision
record wins. The items below were confirmed/clarified on 2026-08-31 and are reflected
inline where practical:

- **`tiremen` table added** (`tireman_id`, `name`, `contact_number`, `is_active`,
  `created_at`, `updated_at`) so the customer-facing OTG screen can show the assigned
  Tireman's name and contact. `service_requests` gains a nullable `tireman_id`. Tiremen
  have no login, no dashboard, no GPS, no scheduling/payroll/ratings. Supersedes the
  "'Tireman' as a role vs. a label" open item in §12.
- **`is_active TINYINT(1) NOT NULL DEFAULT 1` added** to `items` and `vehicles` (and
  `tiremen`). Deactivate = set `0`. Inactive rows disappear from active selection but stay
  visible in historical `sale_items` / `service_requests`. Resolves both `is_active` open
  items in §12.
- **`eta_minutes` is a frozen snapshot** — computed once at submission, never recomputed
  for display. Resolves the "store vs. recompute" open item in §12. No route
  polyline/geometry is persisted; map route lines are re-rendered on demand from the stored
  endpoints.
- **POS cash handling** — online/gateway payment stays out of scope; the POS UI still
  calculates total, amount received, and change and blocks completion on insufficient
  payment, but amount tendered / change are **not persisted** in v1. `sales.total_amount`
  is the only monetary value stored. Receipts, if generated, are printable HTML with no
  receipt table.
- **`sales.sale_date`** is the system-controlled actual-sale timestamp (not manually
  editable in v1, no backdating); `created_at` is the DB record-creation timestamp; reports
  use `sale_date`.
- **No per-status timestamp columns** (`accepted_at`/`completed_at`/`rejected_at`) and no
  status-history table in v1.
- **`customers → service_requests` is `1 : 0..N`** (corrected from `1 : 1..N` in §3).
- **Shop location** lives in `config/shop.php` (`latitude`, `longitude`, `address`); no
  `shop_settings` table in v1.
- **"Manage Inventory"** is one admin module covering products and services; "Manage
  Products" is a sub-function, not a separate module.

---

## 1. Purpose of the Database

The database exists to support four things, and nothing beyond them:

1. **Customer accounts** — so a customer can log in and request on-the-go service.
2. **Inventory** — products and services the shop tracks, with stock levels for products.
3. **Sales** — a record of in-shop transactions (not an online checkout/cart system).
4. **On-the-go service requests** — a customer's request for roadside vulcanizing help, including their location.

There is no shopping cart, online payment, or e-commerce ordering in this schema — sales are recorded by Admin after a transaction happens physically in the shop.

---

## 2. Tables, Field by Field

### `customers`
Registered accounts for the public/customer-facing side of the system. Required before a customer can submit an on-the-go request.

| Field | Purpose |
|---|---|
| `customer_id` **(PK)** | Unique identifier |
| `full_name` | Customer's name |
| `email` *(unique)* | Login identifier and contact |
| `contact_number` **(required)** | Phone contact for coordinating service directly with the customer — confirmed mandatory, not optional |
| `password_hash` | Hashed password — never plain text (see §9) |
| `created_at` / `updated_at` | Account timestamps |

### `admins`
The single internal role. Kept as its own table, separate from `customers`, because admins are provisioned internally rather than through public registration and have an entirely different login area/dashboard.

| Field | Purpose |
|---|---|
| `admin_id` **(PK)** | Unique identifier |
| `full_name` | Admin's name |
| `email` *(unique)* | Login identifier |
| `password_hash` | Hashed password |
| `created_at` / `updated_at` | Account timestamps |

### `tiremen`
*(Added 2026-08-31.)* A minimal record of the people who perform OTG services, so the
customer-facing OTG screen can display the assigned Tireman's name and contact number.
**Not** a login role — Tiremen have no account and no dashboard in v1. No GPS, telemetry,
scheduling, ratings, or payroll.

| Field | Purpose |
|---|---|
| `tireman_id` **(PK)** | Unique identifier |
| `name` | Tireman's name (shown to the customer on an assigned request) |
| `contact_number` | Phone contact (shown to the customer on an assigned request) |
| `is_active` **(`TINYINT(1)` NOT NULL DEFAULT 1)** | `0` = deactivated: cannot be newly assigned; still shown on requests already assigned to them |
| `created_at` / `updated_at` | Record timestamps |

Admin can add, edit, activate/deactivate Tiremen, and assign a Tireman to an OTG request.

### `vehicles`
A customer's saved vehicle(s). Confirmed: its own table, and a customer may have **multiple** vehicles.

| Field | Purpose |
|---|---|
| `vehicle_id` **(PK)** | Unique identifier |
| `customer_id` **(FK → customers)** | Owner of the vehicle |
| `plate_number` | Identifies the specific vehicle |
| `vehicle_type` | e.g. motorcycle, car |
| `make` / `model` | Vehicle details |
| `is_active` **(`TINYINT(1)` NOT NULL DEFAULT 1)** | `0` = deactivated: hidden from active vehicle selection, still shown on historical `service_requests` |
| `created_at` / `updated_at` | Record timestamps |

### `items`
Confirmed: products and services share **one unified table**, distinguished by `item_type`. This lets a single sale line up products and services together without a separate services table.

| Field | Purpose |
|---|---|
| `item_id` **(PK)** | Unique identifier |
| `item_name` | Name of the product or service |
| `item_type` | `'product'` or `'service'` |
| `category` *(nullable)* | Simple grouping label (e.g. "Tires", "Patching"). Stored as a plain field rather than its own table — there's no indication categories need their own management screen yet. |
| `price` | Current unit price |
| `stock_quantity` *(nullable)* | Only meaningful for `item_type = 'product'`; `NULL`/ignored for services |
| `reorder_level` *(nullable)* | Low-stock threshold; products only |
| `is_active` **(`TINYINT(1)` NOT NULL DEFAULT 1)** | `0` = deactivated: hidden from POS selection and the active-inventory list, still shown on historical `sale_items` |
| `created_at` / `updated_at` | Record timestamps |

### `sales`
One in-shop transaction, recorded by Admin. Confirmed: walk-in sales are supported — `customer_id` is **nullable**.

| Field | Purpose |
|---|---|
| `sale_id` **(PK)** | Unique identifier |
| `customer_id` **(FK → customers, nullable)** | Set only if the transaction is tied to a registered customer |
| `admin_id` **(FK → admins)** | Who recorded the sale — always required |
| `sale_date` | Actual-sale timestamp; system-controlled, not manually editable in v1; used as the reporting date (see §8) |
| `total_amount` | Sum of line items; the only monetary value persisted (no amount-tendered / change — see §8) |
| `created_at` | Database record-creation timestamp |

### `sale_items`
Line items for a sale — the join between `sales` and `items`. This is what lets one sale mix products and services.

| Field | Purpose |
|---|---|
| `sale_item_id` **(PK)** | Unique identifier |
| `sale_id` **(FK → sales)** | Parent sale |
| `item_id` **(FK → items)** | The product or service sold |
| `quantity` | Units sold |
| `unit_price` | Price *at time of sale* (kept separate from `items.price` so later price changes don't rewrite history) |
| `subtotal` | `quantity × unit_price` |

### `service_requests`
An on-the-go request submitted by a logged-in customer.

| Field | Purpose |
|---|---|
| `request_id` **(PK)** | Unique identifier |
| `customer_id` **(FK → customers, NOT NULL)** | Required — requester must be authenticated |
| `vehicle_id` **(FK → vehicles)** | Which of the customer's vehicles needs help |
| `admin_id` **(FK → admins, nullable)** | Which admin is handling the request, once assigned |
| `tireman_id` **(FK → tiremen, nullable)** | Assigned Tireman; set by an admin on/after acceptance. `NULL` while pending/rejected or accepted-but-unassigned. Independent of `admin_id`. |
| `problem_description` | What the customer needs |
| `latitude` / `longitude` | Customer's shared location at request time |
| `eta_minutes` *(nullable)* | Estimated travel time from the shop to the customer, calculated **once** at submission and then **frozen**. Never recomputed or updated for display afterward. |
| `status` | See §11 |
| `requested_at` / `updated_at` | Timestamps. No per-status timestamp columns in v1 (no `accepted_at`/`completed_at`/`rejected_at`). |

---

## 3. Relationships (Cardinality)

| Relationship | Cardinality | Notes |
|---|---|---|
| customers → vehicles | 1 : 0..N | A customer may save several vehicles |
| customers → sales | 1 : 0..N | Optional — walk-in sales have no customer |
| customers → service_requests | 1 : 0..N | A request needs exactly one customer; a customer may have zero or many. `customer_id` is `NOT NULL`. |
| admins → sales | 1 : 1..N | Every sale is recorded by exactly one admin |
| admins → service_requests | 1 : 0..N | Optional — set once an admin picks up the request |
| tiremen → service_requests | 1 : 0..N | Optional — set once an admin assigns a Tireman to an accepted request |
| vehicles → service_requests | 1 : 0..N | A vehicle can be used across several requests over time |
| sales → sale_items | 1 : 1..N | A sale needs at least one line item |
| items → sale_items | 1 : 0..N | An item can appear in many sales |

---

## 4. Business Rules

- A customer **must** be authenticated before submitting an on-the-go service request. Anonymous submission is not allowed.
- Customer accounts and Admin access are entirely separate — there is no shared login table and no shared "users" concept.
- Admin and Staff are **not** modeled as separate roles; there is one internal `admins` table.
- Recording a sale of an `item_type = 'product'` line should decrease that item's `stock_quantity` by the quantity sold. Service lines do not affect stock.
- Walk-in, in-shop sales do not require a customer account — `sales.customer_id` is nullable specifically to support this.
- On-the-go requests always require both a customer and a vehicle reference.
- Every `customers` row must have a `contact_number` — it's how the shop/Tireman communicates directly with the customer once a request is accepted. No in-app messaging is modeled; a phone contact is sufficient.
- There is no live/continuous tracking of the Tireman's location. The customer's "Tireman is on the way" state is just a status display (see §11), not a moving-location feed.
- **Online / gateway payment (e.g. GCash) is out of scope.** No online-payment fields or payment-transaction tables exist, and none should be added on the strength of Figma UI alone. **In-person cash handling is different and is supported in the POS UI** (total, amount received, change, block-on-insufficient) but is **not persisted** — `sales.total_amount` is the only monetary value stored (see §8).
- Admin accounts are provisioned internally only. There is no public "sign up as Admin" path — only the `customers` side has open registration. The v1 mechanism may be simple (a seeded admin row or a protected internal-only page).

## 5. Data Integrity Rules

- `sale_items.unit_price` is captured at the time of sale and is intentionally independent of `items.price`, so historical sales stay accurate if prices change later.
- **Deactivation, not deletion, is the norm.** Setting `is_active = 0` on an `items`, `vehicles`, or `tiremen` row removes it from active selection while keeping it visible on historical records. A hard delete is only acceptable for a row with no historical references (no `sale_items` / no `service_requests`).
- Never hide a historical `sale_items` or `service_requests` row because a referenced item/vehicle/tireman is now inactive.
- `email` is unique per `customers` row and per `admins` row (checked independently — a customer and an admin could theoretically share an email since they're different tables, though this is worth flagging to the team).

## 6. Authentication & Security Considerations

- Passwords are never stored in plain text, in the database or anywhere else (cookies, local storage). Only `password_hash` (e.g. via `password_hash()` / bcrypt in PHP) is stored.
- **"Remember me" / persistent login is deferred entirely for v1** (decision record Decision 43, 2026-09-01). No remember-token table, no persistent-login cookie. Authentication (Phase 3) uses normal PHP sessions only, with a 30-minute sliding idle timeout (Decision 45). Revisiting persistent login later would require a new decision and a new table.
- Login identifier is `email` only (Decision 41); `email` uniqueness stays per-table (Decision 42).

## 7. Inventory Rules

- `items` holds both products and services; `item_type` is what separates them in queries and UI (e.g. the low-stock report should only ever look at rows where `item_type = 'product'`).
- Low-stock identification compares `stock_quantity` to `reorder_level` for product rows.
- No supplier, procurement, or multi-location inventory is modeled — out of scope per the spec.

## 8. Sales Rules

- A sale can freely mix product and service lines, since both live in the same `items` table.
- `sales.total_amount` is expected to equal the sum of `sale_items.subtotal` for that sale. It is the **only** monetary value persisted per sale.
- No online payment or checkout flow — a sale row represents a transaction that already happened in person.
- **In-person cash handling (v1):** the POS UI computes the total, accepts the amount received from the cashier, computes change, and prevents completion if the amount received is insufficient. The amount tendered and change due are **UI-only** and are **not** stored.
- If a receipt is produced, it is a simple printable HTML/browser view rendered from the saved `sales` + `sale_items` rows. There is **no receipt table**; a receipt "number" can just be `sale_id`.
- `sale_date` is the actual-sale timestamp, **system-controlled** — not manually editable during normal POS completion, and there is no backdating feature in v1. `created_at` is the database record-creation timestamp. Sales reports use `sale_date`.

## 9. On-the-Go Request Rules

- A request always ties back to one customer and one of that customer's saved vehicles.
- Live/continuous GPS tracking is out of scope — only the coordinates at the moment of submission are stored.

## 10. Location-Data Handling

- `latitude` / `longitude` are captured once, at submission time, from the browser's geolocation API.
- If location permission is denied, the request flow should let the customer retry rather than silently failing; this is a frontend/UX concern and doesn't change the schema — `latitude`/`longitude` simply stay unset until a successful capture.
- The customer's screen shows a map with their location, a route to the shop, and the calculated `eta_minutes` — all computed once at submission. The `eta_minutes` value shown afterward is always the stored snapshot.
- **No route geometry/polyline is persisted.** If a map is shown again later (customer or admin), the route line may be re-generated from the two fixed endpoints (stored request `latitude`/`longitude` → shop config location), but the ETA displayed stays the stored `eta_minutes`. Optional columns like `distance_km` / `route_calculated_at` are not added in v1.
- The shop's own location (needed to calculate the route/ETA) is a single fixed point stored in centralized application config (`config/shop.php`: `latitude`, `longitude`, `address`). All route/ETA calculations read from there; coordinates are never hard-coded across the app. No `shop_settings` table in v1; an admin-editable option remains a future consideration.
- Admin views the same location/route, using the stored coordinates, potentially through a map service such as Google Maps. No separate location-history table is kept — only the one point per request.
- None of this constitutes live tracking: the stored location and ETA do not change after submission, and there is no Tireman-location feed anywhere in the schema.

## 11. Status Values

`service_requests.status` uses four values: `pending`, `accepted`, `rejected`, `completed`. "Tireman is on the way" is UI copy shown while status is `accepted` — it is not a separate status value and doesn't require a schema change. No additional statuses are introduced.

Assigning a Tireman (`tireman_id`) is a separate action from the status transition: an admin may set the status to `accepted` and assign the Tireman together, or assign shortly after. The customer-facing "assigned Tireman" panel (name + contact number) appears once `tireman_id` is set.

## 12. Assumptions & Unresolved Decisions

Confirmed:
- Vehicles have their own table; a customer can have multiple.
- Products and services share one `items` table (`item_type` distinguishes them).
- Walk-in sales are supported without a customer account (`sales.customer_id` is nullable).
- No live/continuous Tireman tracking — only a one-time route + frozen ETA snapshot at request submission.
- Customer `contact_number` is required, not optional.
- Online / gateway payment (GCash or otherwise) is out of scope; no online-payment fields are modeled. In-person cash tender/change is UI-only, not persisted (§8).
- Admin accounts are provisioned internally; there is no public Admin self-registration.

Resolved on 2026-08-31 (see decision record Decisions 22–39):
- **`is_active` on `items` and `vehicles`** — confirmed (`TINYINT(1) NOT NULL DEFAULT 1`).
- **`eta_minutes` stored vs. recomputed** — confirmed stored and frozen; never recomputed for display.
- **"Tireman" as a role vs. a label** — confirmed as a minimal `tiremen` table (identity/contact/assignment only; no login, dashboard, GPS, scheduling, or payroll).
- **`customers → service_requests` cardinality** — confirmed `1 : 0..N`.
- **Shop location** — confirmed as `config/shop.php` config value for v1 (no table).
- **Per-status timestamps / status-history table** — confirmed not added in v1.
- **`sale_date` editability** — confirmed system-controlled, no backdating in v1.

Resolved on 2026-09-01 (Phase 3 — see decision record Decisions 41–47):
- **Customer identifier** — confirmed `email` only; no username (Decision 41).
- **Email uniqueness scope** — confirmed per-table, independent (Decision 42).
- **Remember-me / persistent login table** — confirmed deferred entirely; no table in v1 (Decision 43).

Still open / not yet confirmed:
- **`category` as a plain field vs. its own table** — currently a plain nullable field.
- **Whether Admin can manually create customer accounts** — not modeled as a separate flow.
- **"Manage Customer Accounts" (Admin)** — flagged proposed in the use-case diagram, pending confirmation it is in scope.
- **Whether `service_requests.admin_id` must become mandatory once accepted** — currently nullable throughout.
- **Whether shop location should later become admin-editable** (move from `config/shop.php` to a `shop_settings` table) — a future consideration only.
- **Denied-geolocation handling** beyond retry/fallback.
