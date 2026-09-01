# VulcaTrack — Master Project Context & Handoff Document

| | |
|---|---|
| **Project title (LOCKED)** | **VulcaTrack: Sales and Inventory with On-the-Go Services** |
| **Document purpose** | Single authoritative context/handoff file so a brand-new Claude (or developer) conversation can understand the project without any prior conversation memory. |
| **Current project phase** | **Phases 1–4 COMPLETE (2026-09-01):** Application Foundation, Database Schema, Authentication & Authorization, Customer-Side Functionality. Phase 5 (POS & inventory) is the next approved phase. |
| **Generated / last updated** | 2026-09-01 (rev. 5 — Phase 4 customer functionality; ETA method = Decision 48; no schema change) |
| **Status** | **Living document.** Update it whenever a decision changes. If it conflicts with `docs/decisions/project-decisions.md`, the decision record wins and this file must be corrected. |

---

## How to use this document in a new Claude conversation

> **Read this file first.** Then, before taking any development action:
> 1. Read `docs/decisions/project-decisions.md` (the authoritative decision record).
> 2. Read `docs/ERD/schema.dbml` (the database source of truth) and `docs/VulcaTrack-Database-Notes_1.md`.
> 3. Skim the flowcharts in `docs/flows/` and `docs/VulcaTrack-Use-Case-Diagram_1.png`.
> 4. Inspect the current code under `C:\IPT102\vulcatrack\` (or `C:\xampp\htdocs\vulcatrack\`).
> 5. Do **only** the phase you were asked to do. Do not start the next phase automatically.
> 6. If a task needs a decision listed in **§17 Open Questions**, **stop and ask** — do not invent a requirement. Check **§16** for reconciliation status and outstanding documentation items.

---

## 0. TL;DR

VulcaTrack is a **BSIT student, web-based system for a vulcanizing / tire shop**, built with **PHP + MySQL/MariaDB + Apache (XAMPP)** and a **HTML/CSS/JS frontend (Vue where it helps)**. It has a **customer side** (accounts, saved vehicles, On-the-Go roadside service requests with a map + one-time ETA) and an **admin side** (in-shop POS, unified product/service inventory, OTG request handling, reports). Three actors: **Customer** (requests OTG service), **Admin** (manages the system and handles requests), **Tireman** (performs the OTG service — a service-provider record, not a login role). It explicitly **does not** do live technician GPS tracking, online/GCash payment, or public admin sign-up. Walk-in POS sales without an account must work. Development is planned in phases; nothing is coded yet.

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

- **GitHub remote:** `https://github.com/Iyani99/IPT102.git` — newly created, **was empty** at project start.
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
| `vulcatrack` database | **8 tables built** from `vulcatrack/database/schema.sql` (Phase 2). **0 application rows** — no seed data; create an admin with `php vulcatrack/database/seed_admin.php`. |
| Application code | At `C:\IPT102\vulcatrack\`. **Phases 3–4:** auth (customer + admin, hardened sessions, guards, CLI admin seeding) **and** the full customer side — dashboard, profile, saved vehicles (soft-delete), OTG rescue submission (browser geolocation → frozen ETA), request history + customer-facing status. Not built yet: admin OTG handling, POS, inventory, reports (Phases 5–6). |
| Shop location | `vulcatrack/config/shop.php` — **sample** coordinates (generic Cebu City point). Replace with the real shop lat/long/address before deploy/demo; no code change needed (Decision 37). |
| OTG map | Leaflet vendored at `vulcatrack/assets/lib/leaflet/` (no build step); OpenStreetMap tiles at view time; graceful degradation when offline. |
| Apache junction | **Created:** `C:\xampp\htdocs\vulcatrack` → `C:\IPT102\vulcatrack` (Windows directory junction). App reachable at `http://localhost/vulcatrack/`. |
| PHP → Apache → MariaDB health check | **Passing** — `http://localhost/vulcatrack/health.php` reports all checks PASS (verified 2026-09-01). |
| Git | **Initialised** in `C:\IPT102` on branch `main`; initial commit made. Remote `origin` → `https://github.com/Iyani99/IPT102.git` configured (nothing pushed yet). Root `.gitignore` + `.gitattributes` in place. Git 2.55; identity `Lian` / `jokerjesterjay@gmail.com`. |
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
6. Claude's assumptions (lowest — do not act on these alone).

| File | Role |
|---|---|
| `docs/PROJECT-CONTEXT.md` | **This file** — consolidated handoff context. |
| `docs/decisions/project-decisions.md` | **Authoritative** confirmed decisions, scope, change-control. Decisions numbered 1–40 + open questions + conflict log + revision history. |
| `docs/ERD/schema.dbml` | **Database source of truth** (DBML text). |
| `docs/ERD/VulcaTrack-ERD_1.png` | ERD diagram — visual aid only, **behind** `schema.dbml`, pending regeneration. |
| `docs/VulcaTrack-Database-Notes_1.md` | Field-by-field DB rationale; has a 2026-08-31 revision note at the top. |
| `docs/VulcaTrack-Use-Case-Diagram_1.png` | Actors + use cases; changes pending. |
| `docs/flows/VulcaTrack-1..6-*.png` | Overall, Customer, Admin, POS, OTG, Inventory workflows. Flows 2 & 5 are pending updates. |
| `docs/requirements/` | Empty. Reserved for a finalized requirements/SRS **if one is ever produced**. Do not invent requirements to fill it. |

---

## 5. LOCKED decisions (the must-not-break list)

These are confirmed in the decision record. Numbers reference `project-decisions.md`.

**OTG / On-the-Go**
- **[D1]** OTG requests require an **authenticated customer account**. No anonymous OTG submissions.
- **[D2]** Customer **cellphone / contact number is mandatory** (used for shop↔customer coordination; there is no in-app messaging).
- **[D3]** OTG captures the customer's location via browser geolocation → stores `latitude` / `longitude`.
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
- On-the-Go service request (see **§9**).
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
5. Customer shares current location → system captures `latitude` / `longitude` (**D3**).
6. System renders the route: **customer location → fixed shop location** (shop coords from `config/shop.php`).
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
- Denied geolocation: retry/fallback; `latitude`/`longitude` stay unset until a successful capture. Exact UX is an **open question**.

### "Tireman" — service provider (approved)
- A **Tireman** is the person who performs the OTG job. It **is** a database entity: the `tiremen` table (see §8), managed by an Admin.
- It is **not** a login role, **not** an Admin account, has **no** dashboard, and carries **no** GPS/telemetry.
- "Tireman is on the way" remains customer-facing status wording for the `accepted` state; assigning a Tireman (`tireman_id`) is a separate Admin action from the status change.
- The customer sees the assigned Tireman's `name` and `contact_number` once `tireman_id` is set.

---

## 10. POS & sales details

- POS is **admin-operated, in-person**. No online checkout.
- Flow: select products/services → cart (quantity, line subtotal) → total → optionally link a registered customer (optional) → **in-person cash handling in the UI** → admin confirms → save `sales` + `sale_items` → deduct stock for product lines → show/print receipt.
- **In-person cash handling (UI only):** the POS may compute the amount tendered and change, and block completion if payment is insufficient. **These values are not persisted in v1** — only `sales.total_amount` (and per-line `sale_items` values) are stored. *(Decision record locks this as UI-only; the handoff instruction flags it as "keep open if unresolved" — it is resolved in the record. Owner may revisit if desired.)*
- **Receipts:** printable **HTML/print view** generated from `sales` + `sale_items`. **No receipt table** in v1; a receipt "number" can just be `sale_id`.
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

**Figma is strong UI/UX context** — it is the team's current agreed visual direction and should guide frontend layout, navigation, and interaction. **It does not override explicit technical/scope decisions.** The prototype is **external to the repo**. Owner-supplied link (2026-09-01): `https://www.figma.com/design/dFFRqFrAVZgkr3l4RT6Yeh/VulcaTrack--Copy-`. It is a Figma design URL (not machine-readable from a plain fetch), so Phase 4 customer pages were built to the **reported** structure below with clean minimal UI, to be visually aligned to the prototype in a later pass.

**Reported Figma contents** (per the owner; not independently verified by Claude — ~18 frames):
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

### 16.3 Minor doc drift (cosmetic)
- The decision record's Technology table lists only "HTML, CSS, JavaScript" and does not yet mention **Vue** or **MariaDB specifically**; this document reflects the owner's later statements. Update the decision record's Technology section when decision-record edits are next approved.
- The decision record still references the empty-stub file `project-decisions.md.txt` (conflict C5) and says `docs/requirements/` "does not exist yet"; on disk the stub is gone and `docs/requirements/` exists (empty). Harmless; fix on next decision-record edit.

---

## 17. Open questions — DO NOT resolve silently

If a task needs one of these answered, **stop and ask the owner**:

1. Exact scope of **"Manage Customer Accounts"** (admin) — is it in scope at all?
2. Exact **denied-geolocation** fallback UX. *(Phase 4 ships a pragmatic fallback — retry button + map pin-drop + manual lat/long entry; still requires a location to submit. Refine against Figma later; not blocking.)*
3. Whether **`service_requests.admin_id`** becomes mandatory once a request is accepted (current: nullable throughout).
4. Whether **shop location** ever becomes admin-editable (would move from `config/shop.php` to a `shop_settings` table). v1 = config value.
5. Exact **saved-vehicle management UI**. *(Phase 4 ships a clean list + add/edit + soft-delete/restore; align to Figma later.)*
6. **`items.category`** — stay a plain field, or become its own table? (current: plain nullable field).
7. Whether **Admin can manually create customer accounts**.
8. Final **receipt requirements** beyond "printable HTML view, no receipt table".
9. Any Figma details not yet confirmed against the decisions. *(Figma link supplied 2026-09-01 but is not machine-readable here; customer pages built to the reported structure with clean minimal UI, to be visually aligned later.)*
10. Whether **`sale_date`** should ever be manually adjustable at creation (v1 = system-controlled, no backdating — Decision 35).

*(Resolved: "is Tireman a database entity?" — 2026-08-31, see §16.1. **Phase 3 auth (Decisions 41–47):** email-only identifier; email uniqueness per-table; Remember-me deferred entirely; 8-char password; 30-min sliding idle timeout; CLI admin provisioning. **Phase 4 (Decision 48):** OTG ETA = straight-line distance ÷ `otg.average_speed_kmph` config, floored — a frozen snapshot, no routing API.)*

---

## 18. Development phases

Incremental. **Do one phase at a time. Do not auto-start the next phase.**

| Phase | Scope |
|---|---|
| **Phase 0** | Environment & repository preparation. *(Complete — folded into Phase 1 by owner on 2026-09-01.)* |
| **Phase 1** | Application foundation. *(Complete 2026-09-01: scaffold moved to `C:\IPT102\vulcatrack\`; Apache junction `C:\xampp\htdocs\vulcatrack` → `C:\IPT102\vulcatrack` created; Git initialised on `main` with root `.gitignore`/`.gitattributes` and `origin` remote; PHP→Apache→MariaDB health check passing.)* |
| **Phase 2** | Database / MySQL foundation — 8-table schema from `schema.dbml`. *(Complete 2026-09-01: `vulcatrack/database/schema.sql` built and verified against MariaDB 10.4.32.)* |
| **Phase 3** | Authentication & authorization — customer auth + separate admin auth; no public admin registration. *(Complete 2026-09-01: register/login/logout for customers, login/logout for admins, CLI `seed_admin.php`, hardened sessions, `require_customer()` / `require_admin()` guards. Owner decisions A–I → Decisions 41–47.)* |
| **Phase 4** | Customer-side functionality — dashboard, profile, saved vehicles, OTG request submission + status/history. *(Complete 2026-09-01: `vulcatrack/customer/*` pages, `VehicleRepository` / `ServiceRequestRepository`, `Geo` / `OtgStatus` helpers, vendored Leaflet map. OTG requests always created `status='pending'`; ETA frozen at submission — Decision 48. No schema change.)* |
| **Phase 5** | POS & inventory — unified `items`, one inventory module, POS with walk-in support and stock deduction. *(Next approved phase.)* |
| **Phase 6** | OTG / On-the-Go service — admin request handling (accept/reject/complete), map/route/ETA display, status screen. |
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
| 2026-08-31 | Initial creation. Consolidated from `docs/decisions/project-decisions.md` (Decisions 1–40 + open questions + conflict log), `docs/ERD/schema.dbml`, `docs/VulcaTrack-Database-Notes_1.md`, the six flowcharts, the use-case diagram, the verified XAMPP environment state, and owner instructions from the setup conversation. Flagged the **`tiremen` entity conflict** (§16.1) as unresolved. |
| 2026-08-31 (rev. 2) | Owner resolved §16.1 — **option (a): keep `tiremen` + `service_requests.tireman_id`.** Updated §0, §1, §5, §6, §8, §9, §14, §15, §16.1, §17, §18 to present the **8-table** design as approved and the three-actor model (Customer / Admin / Tireman) explicitly. No other decisions changed; title unchanged; no new tables or features. |
| 2026-09-01 (rev. 3) | **Status-only correction.** Phase 0 folded into Phase 1 by owner; recorded **Phase 1 — Application Foundation as COMPLETE** (repo initialised on `main`, scaffold at `C:\IPT102\vulcatrack\`, Apache junction created, health check passing). Updated the header phase line, §3 "Current environment state", and the §18 phase table. **No requirements, decisions, architecture, or schema changed.** |
| 2026-09-01 (rev. 4) | Recorded **Phase 2 (database schema) and Phase 3 (authentication & authorization) COMPLETE.** Phase 3 owner decisions A–I captured as **Decisions 41–47** in the decision record: email-only login identifier; email uniqueness stays per-table; **Remember-me deferred entirely (no token table)**; 8-char minimum password; 30-minute sliding idle timeout; CLI-only admin provisioning (`database/seed_admin.php`). Updated the header phase line, §3, §17 (removed the two now-resolved auth questions), and §18. **Schema unchanged — still exactly 8 tables.** |
| 2026-09-01 (rev. 5) | Recorded **Phase 4 (customer-side functionality) COMPLETE** — customer dashboard, profile (name / contact / password), saved vehicles with `is_active` soft-delete, OTG rescue submission (browser geolocation → one-time frozen ETA), request history + customer-facing status ("Tireman is on the way" shown once an admin assigns a Tireman). Added **Decision 48** (OTG ETA computation method: straight-line distance ÷ `otg.average_speed_kmph`, floored — a frozen snapshot; no routing API). `config/shop.php` now holds **sample** coordinates (was `0.0/0.0`). Map = vendored Leaflet + OpenStreetMap tiles, graceful degradation. Updated the header, §3, §17 (annotated the geolocation-fallback / saved-vehicle-UI / Figma items), §18. **No schema change — still exactly 8 tables; no new status values.** |
