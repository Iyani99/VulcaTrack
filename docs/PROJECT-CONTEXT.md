# VulcaTrack — Master Project Context & Handoff Document

| | |
|---|---|
| **Project title (LOCKED)** | **VulcaTrack: Sales and Inventory with On-the-Go Services** |
| **Document purpose** | Single authoritative context/handoff file so anyone new to the project can understand it without relying on prior discussion or undocumented context. |
| **Current project phase** | **Phases 1–4 + Phase 4.5 COMPLETE. Phase 5 (POS & inventory) IN PROGRESS (started 2026-09-07):** the minimal Admin app shell and the full Inventory module (browse/search/filter/low-stock + add/edit + activate/deactivate) are done and tested; **the POS itself — cart, atomic checkout, printable receipt — is the remaining Phase 5 work.** Phase 5 pre-decisions are Decisions 49–57. |
| **Last updated** | 2026-09-08 (rev. 8 — small mid-phase sync: Phase 5 status through Inventory; test count 111/861; host-relative URL fix; mobile/responsive standing rule) |
| **Status** | **Living document.** Update it whenever a decision changes. If it conflicts with `docs/decisions/project-decisions.md`, the decision record wins and this file must be corrected. |

---

## How to use this document

> **Read this file first.** Then, before taking any development action:
> 1. Read `docs/decisions/project-decisions.md` (the authoritative decision record).
> 2. Read `docs/ERD/schema.dbml` (the database source of truth) and `docs/VulcaTrack-Database-Notes_1.md`.
> 3. Skim the flowcharts in `docs/flows/` and `docs/VulcaTrack-Use-Case-Diagram_1.png`.
> 4. Inspect the current code under `C:\IPT102\vulcatrack\` (or `C:\xampp\htdocs\vulcatrack\`).
> 5. Do **only** the phase you were asked to do. Do not start the next phase automatically.
> 6. If a task needs a decision listed in **§17 Open Questions**, **stop and ask** — do not invent a requirement. Check **§16** for reconciliation status and outstanding documentation items.

---

## 0. TL;DR

VulcaTrack is a **BSIT student, web-based system for a vulcanizing / tire shop**, built with **PHP + MySQL/MariaDB + Apache (XAMPP)** and a **HTML/CSS/JS frontend (Vue where it helps)**. It has a **customer side** (accounts, saved vehicles, On-the-Go roadside service requests with a map + one-time ETA) and an **admin side** (in-shop POS, unified product/service inventory, OTG request handling, reports). Three actors: **Customer** (requests OTG service), **Admin** (manages the system and handles requests), **Tireman** (performs the OTG service — a service-provider record, not a login role). It explicitly **does not** do live technician GPS tracking, online/GCash payment, or public admin sign-up. Walk-in POS sales without an account must work. Development is phased: **Phases 1–4 are complete** (foundation, 8-table schema, auth, the full customer side) a Phase 4.5 stabilization pass added a committed test harness, and a focused Phase 4 enhancement added landmark/address search to Book-a-Rescue. **Phase 5 (POS & inventory) is in progress** — the Admin shell and the full Inventory module are built; the POS (cart, atomic checkout, printable receipt) is the remaining Phase 5 work.

---

## 1. What VulcaTrack is

A web application for a single vulcanizing / tire-repair shop, covering exactly four areas (nothing beyond these without explicit approval):

1. **Customer / public-facing functionality** — landing pages, registration/login, customer dashboard.
2. **Customer accounts and vehicles** — profile, required contact number, multiple saved vehicles.
3. **In-shop sales and inventory** — an admin-operated POS and a unified products+services inventory.
4. **On-the-Go (OTG) roadside service requests** — an authenticated customer asks the shop to come to them; map + route + a one-time ETA snapshot; admin accepts/rejects/completes and assigns a **Tireman** (the person who performs the service).

It is a student project: prioritise **maintainability, modularity, clear separation of concerns, and a demonstrable working vertical slice** over polish or enterprise features. There is a presentation deadline, so a working end-to-end slice beats breadth.

---

## 2. Technology stack (LOCKED unless the owner approves otherwise)

| Layer | Choice |
|---|---|
| Server language | **PHP** (local XAMPP build is **PHP 8.0.30** — keep code 8.0-compatible) |
| Database | **MySQL-compatible; local is MariaDB 10.4.32** (XAMPP) |
| Web server | **Apache 2.4** via **XAMPP** |
| Frontend | **HTML5, CSS3, JavaScript**; **Vue** where component behaviour genuinely benefits (not a full SPA framework mandate) |
| DB admin | **phpMyAdmin** (bundled with XAMPP) |
| UI/UX design | **Figma prototype** (external to the repo; owner provides the link when needed) |

**Do NOT introduce** Laravel, React, Node.js as a runtime, Firebase, PostgreSQL, or any other major framework/database without explicit owner approval. Avoid unnecessary complexity.

---

## 3. Repository & local environment

### Repository (monorepo)

- **GitHub remote:** `https://github.com/Iyani99/VulcaTrack.git` (branch `main`; commits are pushed here). *(Earlier drafts of this file named `Iyani99/IPT102.git`; that was the placeholder name before the repo was renamed — the local folder is still `C:\IPT102`, but the remote is `VulcaTrack`.)*
- **Local root / repo root:** `C:\IPT102`
- **Intended layout:**
  ```
  C:\IPT102\                     <- Git repo root (branch: main)
  ├── .git\                      (not yet created)
  ├── .gitignore                 (not yet created)
  ├── docs\                      <- all project documentation (this file lives here)
  └── vulcatrack\                <- THE single real copy of the PHP application
  ```
- The application has **one real copy**, at `C:\IPT102\vulcatrack\`.
- Apache serves it via a **Windows directory junction**:
  `C:\xampp\htdocs\vulcatrack`  →  `C:\IPT102\vulcatrack`
  so the app is reachable at `http://localhost/vulcatrack/` while the source stays in the repo.
- **Never** keep two independent copies. **Never** make `C:\xampp\htdocs` the repo root. **Never** create a second `.git` inside `vulcatrack\`.
- Default branch: **`main`**.

### Current environment state (verified 2026-09-01)

| Item | State |
|---|---|
| XAMPP install | `C:\xampp\` — Apache, PHP 8.0.30, MariaDB 10.4.32, phpMyAdmin, htdocs all present and working |
| Apache | Starts, serves port 80, config `Syntax OK`, `mod_rewrite` on, `.htaccess` honored |
| MySQL/MariaDB | Starts; user `root`, **no password** (XAMPP default) |
| `vulcatrack` database | **8 tables built** from `vulcatrack/database/schema.sql` (Phase 2). No seed data ships; the owner has created their own customer account for manual testing. Create an admin with `php vulcatrack/database/seed_admin.php`. |
| Application code | At `C:\IPT102\vulcatrack\`. **Phases 3–4:** auth (customer + admin, hardened sessions, guards, CLI admin seeding) **and** the full customer side — dashboard, profile, saved vehicles (soft-delete), OTG rescue submission (browser geolocation **or landmark/address search**, draggable-marker confirmation → frozen ETA; geocode proxy at `customer/geocode.php`), request history + customer-facing status. **Phase 5 so far:** Admin app shell (`src/Views/partials/admin_*.php`, nav Dashboard/POS/Inventory), `Money` helper (integer centavos), `ItemRepository`, and the Inventory module (`admin/inventory.php` browse + `admin/item-edit.php` create/edit + activate/deactivate). Not built yet: **POS** (cart, checkout, receipt, `SaleRepository`/`SaleService`), admin OTG handling, reports (rest of Phase 5 + Phase 6). |
| Test harness | `vulcatrack/tests/` — dependency-free CLI runner, no Composer/PHPUnit. `php vulcatrack/tests/run.php` → **111 tests / 861 assertions** (unit + integration + end-to-end HTTP). MariaDB must be running for the integration/http suites (XAMPP starts it manually); geocoding HTTP tests use a fake provider (`VULCATRACK_GEOCODER_FAKE`), never the network. |
| Shop location | `vulcatrack/config/shop.php` — the **real shop location**: Gerald Tabayag Vulcanizing Shop, 504 San Jose St. Baliwag, Bulacan, lat `14.946654430279454` / lng `120.89290174619997` (set in commit `f2043d5`, 2026-09-04). Config value only — no `shop_settings` table (Decision 37). |
| OTG map | Leaflet vendored at `vulcatrack/assets/lib/leaflet/` (no build step); OpenStreetMap tiles at view time; graceful degradation when offline. |
| Apache junction | **Created:** `C:\xampp\htdocs\vulcatrack` → `C:\IPT102\vulcatrack` (Windows directory junction). App reachable at `http://localhost/vulcatrack/`. Since 2026-09-08 `vulcatrack_url()` emits **host-relative** URLs (only the path of `app.base_url` is used), so the app also works unchanged over a LAN IP (e.g. a phone reaching the PC) or a future deployment hostname — no per-host config. |
| PHP → Apache → MariaDB health check | **Passing** — `http://localhost/vulcatrack/health.php` reports all checks PASS (verified 2026-09-01). |
| Git | Repo in `C:\IPT102` on branch `main`, pushed to `origin` → `https://github.com/Iyani99/VulcaTrack.git`. Root `.gitignore` + `.gitattributes` in place (LF in the repo, `text=auto`). Identity `Lian` / `jokerjesterjay@gmail.com`. |
| GitHub auth | Not configured in this environment — owner must set up a PAT / `gh auth login` before any push. |

**Apache and MySQL must be started manually from the XAMPP Control Panel** (they are not Windows services).

---

## 4. Source-of-truth priority & document map

When information conflicts, use this order:

1. **Explicitly approved / latest project decision** (what the owner most recently confirmed).
2. **`docs/decisions/project-decisions.md`** — the authoritative decision record.
3. **`docs/ERD/schema.dbml`** (DB structure) and **current flow diagrams / project docs**.
4. **Current Figma prototype** (UI/UX direction).
5. Older documentation / earlier ideas.
6. Unverified assumptions or inferences (lowest — do not act on these alone).

| File | Role |
|---|---|
| `docs/PROJECT-CONTEXT.md` | **This file** — consolidated handoff context. |
| `docs/decisions/project-decisions.md` | **Authoritative** confirmed decisions, scope, change-control. Decisions numbered 1–59 + open questions + conflict log + revision history. |
| `docs/ERD/schema.dbml` | **Database source of truth** (DBML text). |
| `docs/ERD/VulcaTrack-ERD_1.png` | ERD diagram — visual aid only, **behind** `schema.dbml`, pending regeneration. |
| `docs/VulcaTrack-Database-Notes_1.md` | Field-by-field DB rationale; has a 2026-08-31 revision note at the top. |
| `docs/VulcaTrack-Use-Case-Diagram_1.png` | Actors + use cases; changes pending. |
| `docs/flows/VulcaTrack-1..6-*.png` | Overall, Customer, Admin, POS, OTG, Inventory workflows. Flows 2 & 5 are pending updates. |
| `docs/flows/VulcaTrack-DFD-0-Context.*` / `VulcaTrack-DFD-1-Level1.*` | **Data Flow Diagrams** (Chapter 3) — context (Level 0) and Level 1, academic notation. Regenerated 2026-09-04 from the approved scope + 8-table schema; consistent with Decision 48 (ETA computed internally — no external routing entity). Editable `.svg` + high-res `.png`. See `VulcaTrack-DFD-notes.md`. |
| `docs/flows/VulcaTrack-Activity-Diagram-OTG.*` | **OTG activity diagram** (Chapter 3). Three swimlanes — Customer / VulcaTrack System / Admin; the roadside job is an *off-system note*, not a Tireman swimlane. Four statuses only. Committed & reviewed 2026-09-06. |
| `docs/flows/VulcaTrack-Sequence-Diagram-POS.*` | **POS sequence diagram** (Chapter 3). Admin · VulcaTrack POS · Items DB · Sales & Line Items DB · Receipt. Frozen `unit_price`, product-only stock deduction, printable HTML receipt, no payment table. Reflects the approved Phase 5 design, not written code. Committed & reviewed 2026-09-06. |
| `docs/requirements/` | Empty. Reserved for a finalized requirements/SRS **if one is ever produced**. Do not invent requirements to fill it. |

---

## 5. LOCKED decisions (the must-not-break list)

These are confirmed in the decision record. Numbers reference `project-decisions.md`.

**OTG / On-the-Go**
- **[D1]** OTG requests require an **authenticated customer account**. No anonymous OTG submissions.
- **[D2]** Customer **cellphone / contact number is mandatory** (used for shop↔customer coordination; there is no in-app messaging).
- **[D3]** OTG captures the customer's location as `latitude` / `longitude` — via browser geolocation **or** landmark/address search (Decisions 58–59), confirmed on the map. No live tracking.
- **[D4]** **No live technician GPS tracking.** No location feed, no history, no moving marker.
- **[D5/D6/D32]** Route + ETA are computed **once at request time**; **`service_requests.eta_minutes` is a frozen snapshot** — never recomputed for display.
- **[D33]** **No route geometry/polyline is persisted.** A map line may be re-drawn from the two fixed endpoints, but the displayed ETA stays the stored value.
- **[D10]** OTG statuses in the database: **`pending`, `accepted`, `rejected`, `completed`** — only these four.
- **[D7/D11]** **"Tireman is on the way"** is customer-facing wording for the `accepted` state — not a separate DB status.
- **[D22–D26]** A minimal **`tiremen`** table exists (service providers who perform OTG jobs). A Tireman is **not** an Admin account, **not** a login/role, has **no** dashboard, and carries **no** GPS/telemetry. Columns: `tireman_id`, `name`, `contact_number`, `is_active`, `created_at`, `updated_at`. The **Admin** adds / edits / views / activates / deactivates Tiremen, and assigns an **active** Tireman to an **accepted** request via `service_requests.tireman_id` (nullable). Once assigned, the customer sees the Tireman's name + contact number alongside the "Tireman is on the way" status and the frozen ETA.

**Sales & inventory**
- **[D12/D30]** **Online / gateway payment (GCash etc.) is OUT OF SCOPE** — no gateway, no payment APIs, no payment/transaction tables, no online-payment fields. *(In-person cash handling in the POS UI is still allowed — see §12.)*
- **[D13]** Sales are recorded by an **admin in the shop**. No online customer checkout.
- **[D14]** **Walk-in customers must be supported** — a sale can exist with `sales.customer_id = NULL`.
- **[D15/D36]** **Products and services share ONE `items` table** (`item_type` distinguishes them). Inventory is **one admin module**; "Manage Products" is a sub-function, not a separate module.
- **[D16]** Product sales reduce stock; service lines do not.
- **[D17]** `sale_items.unit_price` is **frozen at time of sale** (historical accuracy).
- **[D35]** `sales.sale_date` = actual-sale timestamp, **system-controlled, not manually editable in v1** (no backdating). `sales.created_at` = record-creation timestamp. Reports use `sale_date`.

**Accounts & roles**
- **[D18/D40]** **No public "Sign up as Admin".** Admin accounts are **internally provisioned** (a seeded row or a protected internal-only page is fine for v1). Public registration is for **customers only**.
- **[D19]** One internal **Admin** role for v1. No separate Staff role.

**Deactivation**
- **[D27–D29]** `items.is_active` and `vehicles.is_active` (`TINYINT(1) NOT NULL DEFAULT 1`). Deactivate by setting `0`; **do not hard-delete** rows that have historical references. Inactive items/vehicles disappear from active selection but stay visible on historical records.

**Configuration**
- **[D21/D37]** Shop location (`latitude`, `longitude`, `address`) lives in **application config** (e.g. `vulcatrack/config/shop.php`). **No `shop_settings` table in v1.** All route/ETA code reads from that one source.

**Relationship**
- **[D39]** **`customers` 1 : 0..N `service_requests`.** A customer may have zero or many requests; each request belongs to exactly one customer; `service_requests.customer_id` is **`NOT NULL`**.

**Design process**
- **[D38]** `docs/ERD/schema.dbml` is the DB source of truth; the ERD PNG is a visual aid.

---

## 6. What the system does — feature areas

These describe intended scope. They are **not** all "designed and locked" — cross-check the Figma and flows when building each.

### Customer side
- Public landing / features / "How It Works" pages; entry point to "Book a Rescue" (OTG).
- Customer registration and login (email + password).
- Customer dashboard / home.
- Customer profile (includes the **required** contact number).
- Saved vehicles (multiple per customer).
- On-the-Go service request (see **§9**) — location set by browser geolocation or landmark/address search, confirmed with a draggable map marker.
- Request confirmation screen (route + ETA snapshot).
- Request status screen ("Tireman is on the way", assigned Tireman name + contact number, ETA, map).
- Request history / "My Bookings".

### Admin side
- Admin login (separate from customer auth).
- Admin dashboard.
- **POS / Sales** (see **§10**).
- **Inventory** — one module for products **and** services (add/edit, stock for products, low-stock monitoring, activate/deactivate).
- **OTG / Rescue request management** — list, view detail (customer, vehicle, problem, location, route, ETA), accept / reject, assign an active Tireman, mark completed.
- **Manage Tiremen** — add / edit / view / activate / deactivate the service providers who perform OTG jobs. These are **not** admin accounts and have no login.
- **Reports** — sales reporting (grouped by `sale_date`).
- **Settings** — scope minimal for v1.
- Customer/account management **only where explicitly approved** (see **§17** — "Manage Customer Accounts" is still open).

### POS / Sales
- Search/select products and services; category grouping; cart; quantity; running total.
- In-person transaction handling; optionally link to a registered customer (optional — walk-in allowed).
- Stock deduction for product lines on completion.
- Transaction completion; printable receipt as an HTML/print view (**no receipt table** — see §10).

### Inventory
- Products and services unified under the **`items`** table.
- One inventory module. Do **not** split products vs services into separate DB domains or separate top-level modules unless a later approved decision changes it.

---

## 7. What the system does NOT do (v1 out of scope — do not invent)

- Live technician / Tireman GPS tracking, location history, moving markers, real-time telemetry, ride-hailing-style tracking.
- Continuously updating ETA.
- Persisted route polyline / route geometry / route history.
- Online GCash payment, payment gateways/APIs, online payment processing, payment/transaction tables, online-payment fields, saved online payment methods.
- Customer shopping cart for **online** purchase, online checkout, customer online purchasing.
- Public "Sign up as Admin" / self-service admin registration.
- Supplier / procurement management; multi-location inventory.
- Separate Staff role; Tireman login portal / dashboard / authentication; technician scheduling, ratings, payroll, employee management.
- Advanced dispatch / routing-optimization algorithms.
- Status-history / audit tables (including per-status timestamp trails).
- In-app customer↔technician messaging.

---

## 8. Database

### Confirmed tables (8)

1. **`customers`** — account; `contact_number` **required/NOT NULL**; owns 0..N vehicles; has 0..N service_requests.
2. **`admins`** — internal role; internally provisioned; separate from customers (no shared user table).
3. **`tiremen`** — service providers who perform OTG jobs (`tireman_id`, `name`, `contact_number`, `is_active`, `created_at`, `updated_at`). **Not** an admin account, **not** a login/role, no dashboard, no GPS. Managed by an Admin. *(Decisions 22–26; owner-reaffirmed 2026-08-31.)*
4. **`vehicles`** — belong to a customer; multiple per customer; `is_active` flag.
5. **`items`** — unified products **and** services (`item_type`); `category` is a plain nullable field (no category table in v1); `stock_quantity` / `reorder_level` for products only; `is_active` flag.
6. **`sales`** — a completed in-person sale; `customer_id` **nullable** (walk-in); `admin_id` required; `sale_date` system-controlled; `total_amount` is the **only** money value stored.
7. **`sale_items`** — links `sales`↔`items`; `unit_price` frozen at sale time; `subtotal = quantity × unit_price`.
8. **`service_requests`** — OTG request; `customer_id` **NOT NULL**; `vehicle_id` required; `admin_id` nullable; **`tireman_id` nullable** (assigned Tireman, set on/after accept); `latitude`/`longitude`; `eta_minutes` frozen snapshot; `status` ∈ {pending, accepted, rejected, completed}; `requested_at` / `updated_at` only (no per-status timestamps).

> **Actor model:** **Customer** = person requesting OTG service · **Admin** = system user who manages the system and handles requests · **Tireman** = person who performs the OTG service. A Tireman record is a service provider only — never an Admin account or a login role. Only an Admin assigns a Tireman to an accepted request.

### Key rules

- Walk-in sales: `sales.customer_id` may be `NULL`.
- `customers` 1 : 0..N `service_requests`; request-side `customer_id` is `NOT NULL`.
- `customers` 1 : 0..N `vehicles`; 1 : 0..N `sales`.
- `admins` 1 : 1..N `sales` (every sale has exactly one recording admin).
- `admins` 1 : 0..N `service_requests`; `tiremen` 1 : 0..N `service_requests` (both nullable; set when the admin handles / assigns).
- `sales` 1 : 1..N `sale_items`; `items` 1 : 0..N `sale_items`.
- Deactivate (`is_active = 0` on `items`, `vehicles`, `tiremen`), don't hard-delete rows with history. An inactive Tireman cannot be newly assigned but stays visible on requests already assigned to them.
- `email` unique **within** `customers` and **within** `admins` independently (global uniqueness is an open question).
- **Do not change the schema because of a UI element** — route changes through the decision record.

The full field-level schema is in **`docs/ERD/schema.dbml`**. Column data types there are *indicative* MySQL types (not themselves locked); table/column existence, keys, FKs, nullability, and defaults **are** per the decision record.

---

## 9. OTG / On-the-Go services (how it works)

**Principle: there is NO live technician tracking.** The map is for the customer's location, route visualization, and the one-time route/ETA calculation — nothing else.

### Intended v1 flow

1. Customer logs in (account required — **D1**).
2. Customer selects a saved vehicle (or adds one) and provides required vehicle info.
3. Customer describes the problem / service needed.
4. Customer's required contact number is on file / confirmed (**D2**).
5. Customer sets their location — **browser geolocation** ("use my current location") **or** **landmark / address search** (type a place → explicit Search → pick a result) — confirms it on the map (marker is **draggable**), and the confirmed `latitude` / `longitude` are captured (**D3**, Decisions 58–59). The dragged marker position wins over the initial GPS/geocoder point.
6. System renders the straight line: **customer location → fixed shop location** (shop coords from `config/shop.php`).
7. System calculates an **ETA at request time**.
8. Customer reviews and submits.
9. Request saved with **`status = pending`**, **`eta_minutes` frozen** as a snapshot.
10. Admin reviews the request (customer, vehicle, problem, location, route, ETA).
11. Admin **accepts** or **rejects**.
12. If accepted, the Admin **assigns an active Tireman** to the request (`service_requests.tireman_id`).
13. Customer sees an "on the way" status ("**Tireman is on the way**"), the **assigned Tireman's name + contact number**, the **stored ETA**, and the route/map.
14. Customer and Tireman/shop coordinate **by phone** using the customer's contact number (and the Tireman's contact number shown to the customer).
15. **No** live location. **No** continuously changing ETA.
16. When the service is done, admin sets **`status = completed`**.

### Location / ETA specifics
- Shop location is a **fixed application-config value** (`config/shop.php`: `SHOP_LAT` / `SHOP_LNG` / `SHOP_ADDRESS` conceptually). **No `shop_settings` table in v1.**
- **`service_requests.eta_minutes`** is stored once and **displayed from storage thereafter** — never silently recomputed on re-open.
- Route geometry is **not** persisted; re-render it from the stored endpoints if a map is shown again.
- **Location selection (Decisions 58–59):** two first-class methods — browser geolocation, or landmark/address search via `customer/geocode.php` (server-side proxy to OSM Nominatim: identifying `User-Agent`, ≥ 1 req/sec app-wide, cached identical queries, explicit-button only / no autocomplete, PH + Bulacan soft bias, all provider data re-validated + escaped). Both end at a **draggable** Leaflet marker the customer confirms; the final marker position is stored. A location is still required to submit. Geocoding is `src/Support/Geocoder` + `NominatimGeocoder` / `ArrayGeocoder` / `GeocoderFactory` / `GeocodeCache` — a replaceable layer; **not** a routing API, ETA method unchanged. `geocoding.driver = 'none'` runs it fully offline from a local landmark list.

### "Tireman" — service provider (approved)
- A **Tireman** is the person who performs the OTG job. It **is** a database entity: the `tiremen` table (see §8), managed by an Admin.
- It is **not** a login role, **not** an Admin account, has **no** dashboard, and carries **no** GPS/telemetry.
- "Tireman is on the way" remains customer-facing status wording for the `accepted` state; assigning a Tireman (`tireman_id`) is a separate Admin action from the status change.
- The customer sees the assigned Tireman's `name` and `contact_number` once `tireman_id` is set.

---

## 10. POS & sales details

- POS is **admin-operated, in-person**. No online checkout. Reachable through a minimal admin shell/nav (Dashboard · POS · Inventory) — approved as a Phase 5 prerequisite (Decision 53). **Sales reporting / history UI is Phase 6, not Phase 5** (Decision 49); Phase 5's `SaleRepository` may still expose receipt-read methods.
- Flow: select products/services → cart (quantity, line subtotal) → total → optionally link an **existing** registered customer, else walk-in (`sales.customer_id = NULL`) → **in-person cash handling in the UI** → admin confirms → in one DB transaction: save `sales` + `sale_items`, freeze `unit_price`, deduct stock for product lines only → show/print receipt. Full rollback on any failure. The POS never creates a customer account (Decision 51).
- **In-person cash handling (UI only, Decision 54):** the server may receive `cash_tendered` for a one-off check against the server-authoritative total and may compute change for the response; **neither is persisted** — only `sales.total_amount` (and per-line `sale_items` values). No payment table.
- **Money maths use integer centavos** end to end — never PHP float arithmetic (Decision 55).
- **Application-layer validation for Phase 5 (Decision 52):** `price >= 0`, `quantity > 0`, `stock >= 0`, enforced in the service/repository layer. The approved Phase 2 schema is **not** changed to add CHECK constraints unless a real correctness problem forces a rethink.
- **Session-backed cart (Decision 56):** a temporary `$_SESSION` cart is acceptable *only* to preserve cart contents across a validation/stock error. It is not a persistent e-commerce cart.
- **Receipt (Decision 50):** printable **HTML/print view** from `sales` + `sale_items`. Fields: shop name + address, `sale_id` as the receipt number, sale date/time, cashier/Admin name, linked customer name or "Walk-in", per line {name, quantity, frozen unit price, subtotal}, total, print button/styling. **No receipt table**, no TIN/BIR/tax fields, no "official receipt" claim.
- `sales.sale_date` is system-set at completion (no backdating in v1); reports group by `sale_date`.

---

## 11. Walk-in customers

**Must be supported.** A normal in-person POS sale does **not** require a customer account. `sales.customer_id` is nullable specifically for this. Do not force account creation at the POS. Customer accounts matter for customer-facing features (saved vehicles, OTG requests, profile, request history), not for buying something at the counter.

---

## 12. Payment / GCash stance

| | |
|---|---|
| **Online payment / gateway (GCash, payment APIs, gateway credentials, transaction tables, online payment status)** | **OUT OF SCOPE for v1.** Parked/future; needs explicit owner approval to add. |
| **In-person POS cash handling** | **In scope** — UI computes total / tendered / change, blocks on insufficient payment. Not persisted beyond `total_amount` (see §10). |

The Figma prototype's saved-GCash / payment-method concepts are **not** an approved backend requirement. Do not build payment infrastructure because Figma shows it.

---

## 13. Admin security

- **No unrestricted public "Sign up as Admin".** If the Figma shows an admin sign-up toggle, that is a known discrepancy (see §15) — do not implement it.
- Customers self-register; admins are provisioned internally (seeded row or protected internal-only page is acceptable for v1).
- Admin authorization is handled **separately** from customer access (separate login, separate session/role handling).

---

## 14. Status vocabulary — database vs Figma

| Layer | Values |
|---|---|
| **Database (`service_requests.status`)** | `pending`, `accepted`, `rejected`, `completed` — **only these four** (Decision 10). |
| **Figma / customer-facing labels** (presentation only) | e.g. `Pending`, `IN-PROGRESS`, `Tireman Assigned – On the Way`, `Completed`. |

The Figma labels are a **presentation mapping** over the four DB values. For example, `accepted` may be displayed as "Tireman Assigned – On the Way" once `service_requests.tireman_id` is set (the assignment is a column value, not a status value). **Do not add new DB status values** to match Figma wording unless an approved design requires it. "Tireman is on the way" / "Tireman Assigned" is wording + an assignment column, not a separate technician-tracking state.

---

## 15. Figma context & known Figma/flow differences

**Figma is strong UI/UX context** — it is the team's current agreed visual direction and should guide frontend layout, navigation, and interaction. **It does not override explicit technical/scope decisions.** The prototype is **external to the repo**. Owner-supplied link (2026-09-01): `https://www.figma.com/design/dFFRqFrAVZgkr3l4RT6Yeh/VulcaTrack--Copy-`. It is a design link only — the frames are not exported into the repo — so Phase 4 customer pages were built to the **reported** structure below with clean minimal UI, to be visually aligned to the prototype in a later pass.

**Missing-design / mobile standing rule (owner, 2026-09-08):** where Figma has no frame for an approved feature or state, that is **not** a blocker — reuse the closest existing VulcaTrack UI patterns (shell, cards, tables, badges, forms, spacing) and build the smallest clean functional screen, then flag the gap for the later Figma pass. **Mobile support is a conservative responsive adaptation of the desktop UI, not a separate design:** preserve the visual identity; only stack/wrap/full-width controls, keep touch targets usable, let wide admin tables scroll horizontally in their own box; do not convert tables to cards or invent new mobile UX. Customer-facing screens (login, vehicles, Book-a-Rescue, map) get priority; check ~360/390/430px + tablet + desktop per UI chunk.

**Reported Figma contents** (per the owner; not yet independently cross-checked against the repo — ~18 frames):
- **Public:** Landing, Features, How It Works, Login, "Book a Rescue"/OTG entry.
- **Auth:** Customer login, Admin login, Customer sign-up.
- **Customer:** Home/dashboard, Book-a-Rescue/OTG screens, Rescue confirmation, Tracking/status screen, Profile, Vehicles/account info.
- **Admin:** Dashboard, POS.
- **Navigation references (may be placeholders, not full screens):** Inventory, Rescue Management, Reports, Settings, My Bookings.

**Known Figma ↔ decisions/flow differences (follow the decisions, not the older Figma behaviour):**
| Figma / older idea | Current decision |
|---|---|
| Live technician tracking / moving marker | **Removed.** No live tracking (§7, §9). |
| GCash / saved payment methods | **Not approved for v1** (§12). |
| Admin sign-up toggle | **Not allowed** — internal provisioning only (§13). |
| Anonymous OTG requests (implied by some older flows) | **Account required** (D1). Figma's account-based direction is the correct one. |
| Some nav items ("Inventory", "Reports", "Settings", "My Bookings", "Rescue Management") | May be **navigation placeholders** without finished screens. |
| Mixed terminology: "Rescue", "Booking", "Emergency Vulcanizing Request" | Same underlying feature = the **OTG service request**. Customer-facing label per Figma; DB/status vocabulary per §14. |
| Mixed terminology: "Tireman" vs "Technician" | Same concept = the **`tiremen`** service-provider entity (§8). Use "Tireman" as the customer-facing term. Not an admin/login role. |

When frontend implementation begins, **cross-check each screen against this document and the decision record.**

---

## 16. Reconciliation status & outstanding documentation items

### 16.1 "Tireman" as a database entity — ✅ RESOLVED (2026-08-31)

**Owner decision: option (a) — KEEP `tiremen` + `service_requests.tireman_id`.**

The 8-table design (Decisions 22–26) is the **current approved design**. `docs/decisions/project-decisions.md`, `docs/ERD/schema.dbml`, `docs/VulcaTrack-Database-Notes_1.md`, and this document all consistently reflect it. Summary of the approved model:
- **`tiremen`** is a service-provider record (name, contact number, `is_active`) — **not** an Admin account, **not** a login/role, no dashboard, no GPS/telemetry.
- An **Admin** manages Tiremen (add / edit / view / activate / deactivate) and, on an **accepted** OTG request, assigns an **active** Tireman via `service_requests.tireman_id` (nullable).
- The customer then sees the assigned Tireman's name + contact number with the "Tireman is on the way" status and the frozen ETA.
- OTG location behavior is unchanged and still locked (no live tracking, no location history, frozen ETA snapshot).

The earlier handoff-draft wording ("Tireman is not a DB entity", 7 tables) is **superseded**.

### 16.2 Diagrams pending regeneration (non-blocking, tracked in the decision record as D1–D5)
- ERD PNG, use-case PNG, and OTG flowcharts 2 & 5 are **POSSIBLY OUTDATED** relative to the decision record. `schema.dbml` is the current DB truth. These are documentation-catch-up items, not blockers.

### 16.3 Minor doc drift
- The decision record's Technology table was updated in the 2026-09-06 stabilization pass to name **MariaDB 10.4 / MySQL-compatible** and **Vue (where it helps)**, matching this document. ✅
- The empty-stub file `project-decisions.md.txt` (former conflict C5) is gone from disk; the decision record's C5 row and its "`docs/requirements/` does not exist yet" wording were corrected in the 2026-09-06 pass. `docs/requirements/` exists but is intentionally empty. ✅
- **`README.md` (repo root)** ends mid-sentence with an unclosed ```` ``` ```` code fence (the "Database" section is truncated). Low impact — the app README at `vulcatrack/README.md` is complete and current. Left for the owner to finish or trim during the Phase 5 documentation pass; not corrected here to avoid a content rewrite outside the stabilization scope.

---

## 17. Open questions — DO NOT resolve silently

If a task needs one of these answered, **stop and ask the owner**:

1. Exact scope of **"Manage Customer Accounts"** (admin) — is it in scope at all?
2. ~~Exact **denied-geolocation** fallback UX.~~ **Resolved 2026-09-06 (Decisions 58–59):** landmark/address search is a first-class alternative to browser GPS, both confirmed on the map with a draggable marker. A location is still required to submit. Figma visual alignment still later.
3. Whether **`service_requests.admin_id`** becomes mandatory once a request is accepted (current: nullable throughout).
4. Whether **shop location** ever becomes admin-editable (would move from `config/shop.php` to a `shop_settings` table). v1 = config value.
5. Exact **saved-vehicle management UI**. *(Phase 4 ships a clean list + add/edit + soft-delete/restore; align to Figma later.)*
6. ~~**`items.category`** — stay a plain field, or become its own table?~~ **Settled for Phase 5 (Decision 52):** stays the existing nullable free-text `items.category`; no category table in Phase 5. A datalist of existing values is acceptable later. Whether it *ever* becomes a table is still open beyond Phase 5.
7. ~~Whether **Admin can manually create customer accounts**.~~ **Settled for Phase 5 (Decision 51):** the POS does **not** create customer accounts — it only links an existing one, else walk-in (`customer_id = NULL`). A general "admin creates customers" flow remains out of scope / open for later phases.
8. ~~Final **receipt requirements**.~~ **Settled for Phase 5 (Decision 50):** printable HTML only; fields = shop name + address, `sale_id` as receipt number, sale date/time, cashier/Admin name, linked customer name or "Walk-in", per line {item/service name, quantity, frozen unit price, subtotal}, total, print button/styling. No receipt table, no TIN/BIR/tax, no payment fields, no "official receipt" claim.
9. Any Figma details not yet confirmed against the decisions. *(Figma link supplied 2026-09-01 but the frames are not exported into the repo; customer pages built to the reported structure with clean minimal UI, to be visually aligned later.)*
10. Whether **`sale_date`** should ever be manually adjustable at creation (v1 = system-controlled, no backdating — Decision 35).

*(Resolved: "is Tireman a database entity?" — 2026-08-31, see §16.1. **Phase 3 auth (Decisions 41–47).** **Phase 4 (Decision 48):** OTG ETA = straight-line distance ÷ `otg.average_speed_kmph` config, floored — a frozen snapshot, no routing API. **Phase 5 pre-decisions (Decisions 49–57, 2026-09-06):** reporting deferred to Phase 6; receipt fields fixed; POS customer-linking optional/existing-only; `items.category` stays free-text; minimal admin shell approved; app-layer validation limits; integer-centavo money; cash tender/change never persisted; session cart only for error recovery. **Rescue location (Decisions 58–59, 2026-09-06):** landmark/address search + draggable-marker map confirmation alongside browser GPS; server-side geocode proxy respecting the OSM Nominatim policy; not a routing API.)*

---

## 18. Development phases

Incremental. **Do one phase at a time. Do not auto-start the next phase.**

| Phase | Scope |
|---|---|
| **Phase 0** | Environment & repository preparation. *(Complete — folded into Phase 1 by owner on 2026-09-01.)* |
| **Phase 1** | Application foundation. *(Complete 2026-09-01: scaffold moved to `C:\IPT102\vulcatrack\`; Apache junction `C:\xampp\htdocs\vulcatrack` → `C:\IPT102\vulcatrack` created; Git initialised on `main` with root `.gitignore`/`.gitattributes` and `origin` remote; PHP→Apache→MariaDB health check passing.)* |
| **Phase 2** | Database / MySQL foundation — 8-table schema from `schema.dbml`. *(Complete 2026-09-01: `vulcatrack/database/schema.sql` built and verified against MariaDB 10.4.32.)* |
| **Phase 3** | Authentication & authorization — customer auth + separate admin auth; no public admin registration. *(Complete 2026-09-01: register/login/logout for customers, login/logout for admins, CLI `seed_admin.php`, hardened sessions, `require_customer()` / `require_admin()` guards. Owner decisions A–I → Decisions 41–47.)* |
| **Phase 4** | Customer-side functionality — dashboard, profile, saved vehicles, OTG request submission + status/history. *(Complete 2026-09-01. Enhanced 2026-09-06 — Decisions 58–59: Book-a-Rescue location can be set by browser geolocation OR landmark/address search (`customer/geocode.php` → OSM Nominatim, policy-respecting), both confirmed with a draggable map marker. `src/Support/Geocoder*`. Still `status='pending'`, still a frozen ETA, no schema change.)* |
| **Phase 4.5** | Stabilization pass (not a feature phase). *(Complete 2026-09-06: committed dependency-free test harness `vulcatrack/tests/`; documentation-drift fixes; finalized the OTG activity + POS sequence diagrams; recorded the settled Phase 5 pre-decisions as Decisions 49–57. No feature code, no schema change.)* |
| **Phase 5** | POS & inventory — unified `items`, one inventory module, POS with walk-in support and stock deduction, printable HTML receipt, minimal admin shell. Reporting/history UI is **not** in Phase 5 (Decision 49). *(IN PROGRESS since 2026-09-07: admin shell + `Money` helper + `ItemRepository` + the full Inventory module done and tested; POS cart / atomic checkout / receipt still to build.)* |
| **Phase 6** | OTG / On-the-Go service — admin request handling (accept/reject/complete), map/route/ETA display, status screen. Also: sales reporting / history UI. |
| **Phase 7** | Integration, testing, bug fixing, presentation readiness. |

**When asked to start a phase:** read this file → read the decision record → inspect current code + relevant docs → implement **only that phase** → verify → report what was done and what remains → **STOP**.

Because of the presentation deadline, prefer a **working vertical slice** over polish.

---

## 19. Rules for when development begins

1. Read `PROJECT-CONTEXT.md` first, then `docs/decisions/project-decisions.md`.
2. Inspect existing code before modifying it.
3. Do not invent requirements. Do not silently change locked decisions. Do not revive superseded features.
4. **No** live technician tracking. **No** GCash/gateway integration. **No** public admin registration.
5. **Support walk-in POS customers** (no forced account).
6. Keep architecture modular, readable, with clear separation of concerns; appropriate for a BSIT project.
7. Follow the Figma for UI direction **where it does not conflict** with approved decisions (§15).
8. Work **one phase at a time**; test what you implement; do not roll into the next phase automatically.
9. If you hit an item in §16 or §17, **stop and ask**.
10. Never commit DB credentials, secrets, API keys, or machine-specific config (`vulcatrack/config/config.php` must be git-ignored; `config.example.php` is the tracked template).

---

## 20. Change log for this document

| Date | Change |
|---|---|
| 2026-09-08 (rev. 8) | **Small mid-phase sync (no decision change).** Recorded **Phase 5 as in progress**: the minimal Admin app shell and the full Inventory module (`admin/inventory.php` browse/search/filter/low-stock + `admin/item-edit.php` create/edit + activate/deactivate), built on a new `Money` integer-centavo helper (Decision 55), Phase-5 `Validator` methods (Decisions 52/56) and `ItemRepository` on the unified `items` table (no category table — Decision 52). Updated the header phase line, §0, §3, §18. Test harness now **111 tests / 861 assertions**. **Correctness fix:** `vulcatrack_url()` now emits **host-relative** URLs so the app works over a LAN IP / deployment hostname, not only `localhost` (a phone on the LAN could load the page but not submit the login — form action pointed at `localhost` = the phone). **New standing rules (§15):** missing Figma coverage is not a blocker (reuse existing patterns, flag the gap); mobile = conservative responsive adaptation of the desktop UI, never a separate mobile design. **No schema change — still exactly 8 tables. POS/Sales not started.** |
| 2026-08-31 | Initial creation. Consolidated from `docs/decisions/project-decisions.md` (Decisions 1–40 + open questions + conflict log), `docs/ERD/schema.dbml`, `docs/VulcaTrack-Database-Notes_1.md`, the six flowcharts, the use-case diagram, the verified XAMPP environment state, and owner instructions from the setup conversation. Flagged the **`tiremen` entity conflict** (§16.1) as unresolved. |
| 2026-08-31 (rev. 2) | Owner resolved §16.1 — **option (a): keep `tiremen` + `service_requests.tireman_id`.** Updated §0, §1, §5, §6, §8, §9, §14, §15, §16.1, §17, §18 to present the **8-table** design as approved and the three-actor model (Customer / Admin / Tireman) explicitly. No other decisions changed; title unchanged; no new tables or features. |
| 2026-09-01 (rev. 3) | **Status-only correction.** Phase 0 folded into Phase 1 by owner; recorded **Phase 1 — Application Foundation as COMPLETE** (repo initialised on `main`, scaffold at `C:\IPT102\vulcatrack\`, Apache junction created, health check passing). Updated the header phase line, §3 "Current environment state", and the §18 phase table. **No requirements, decisions, architecture, or schema changed.** |
| 2026-09-01 (rev. 4) | Recorded **Phase 2 (database schema) and Phase 3 (authentication & authorization) COMPLETE.** Phase 3 owner decisions A–I captured as **Decisions 41–47** in the decision record: email-only login identifier; email uniqueness stays per-table; **Remember-me deferred entirely (no token table)**; 8-char minimum password; 30-minute sliding idle timeout; CLI-only admin provisioning (`database/seed_admin.php`). Updated the header phase line, §3, §17 (removed the two now-resolved auth questions), and §18. **Schema unchanged — still exactly 8 tables.** |
| 2026-09-01 (rev. 5) | Recorded **Phase 4 (customer-side functionality) COMPLETE** — customer dashboard, profile (name / contact / password), saved vehicles with `is_active` soft-delete, OTG rescue submission (browser geolocation → one-time frozen ETA), request history + customer-facing status ("Tireman is on the way" shown once an admin assigns a Tireman). Added **Decision 48** (OTG ETA computation method: straight-line distance ÷ `otg.average_speed_kmph`, floored — a frozen snapshot; no routing API). `config/shop.php` at that time held sample coordinates *(superseded — see rev. 6; the real Baliwag location was committed in `f2043d5`, 2026-09-04)*. Map = vendored Leaflet + OpenStreetMap tiles, graceful degradation. Updated the header, §3, §17 (annotated the geolocation-fallback / saved-vehicle-UI / Figma items), §18. **No schema change — still exactly 8 tables; no new status values.** |
| 2026-09-06 (rev. 7) | **Phase 4 enhancement — Book-a-Rescue location selection (Decisions 58–59). No schema change.** The location step now offers **browser geolocation OR landmark/address search**; both are confirmed on the existing Leaflet map with a **draggable marker**, and the final marker position is stored. Added `customer/geocode.php` (authenticated, CSRF, server-side proxy to OSM Nominatim — identifying User-Agent, ≥1 req/sec app-wide, cached identical queries, explicit-Search-button only, PH+Bulacan bias, all provider data re-validated + escaped) and `src/Support/Geocoder` + `NominatimGeocoder` / `ArrayGeocoder` / `GeocoderFactory` / `GeocodeCache`. Landmark-search UI in `customer/rescue.php`; search handling in `assets/js/otg-map.js`; `geocoding` block in `config/config.example.php`. Test harness now 82 tests / 429 assertions. Moved "denied geolocation UX" out of Open Questions. Not a routing API — ETA method unchanged (Decision 48). **Same-day follow-up fix:** verified end-to-end in real headless Chrome (Apache + live Nominatim) — worked; the reported "Enter/Search does nothing" was a **stale browser cache** of `otg-map.js` (Apache serves `assets/` with no `Cache-Control`). Fix: `vulcatrack_asset()` stamps `?v=<filemtime>` on app-owned CSS/JS; the search field also got an inline `onkeydown` Enter guard so a stale/failed script can never submit the form. |
| 2026-09-06 (rev. 6) | **Phase 4.5 stabilization pass — no feature code, no schema change.** (1) Added a committed dependency-free test harness at `vulcatrack/tests/` (60 tests / 339 assertions; unit + integration + end-to-end HTTP) as the Phase 1–4 regression net. (2) Fixed known documentation drift: GitHub remote is `Iyani99/VulcaTrack.git` (was written as `IPT102.git`); `config/shop.php` holds the **real** shop location, not "sample coordinates" (§3, §10, rev. 5 note, `vulcatrack/README.md`); decision-record Technology table now names MariaDB + Vue; former conflict C5 (stub file) closed; `docs/requirements/` noted as existing-but-empty. (3) Added the OTG activity diagram + POS sequence diagram (`docs/flows/VulcaTrack-Activity-Diagram-OTG.*`, `VulcaTrack-Sequence-Diagram-POS.*`) to version control — reviewed as consistent with the locked design (no Tireman swimlane, Tireman as off-system note, four statuses, no payment table, printable HTML receipt). (4) Recorded the settled Phase 5 pre-decisions as **Decisions 49–57** and annotated §10 and §17. Ran the Phase 1–4 regression + smoke verification: all green. |
