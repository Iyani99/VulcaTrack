# VulcaTrack — Data Flow Diagrams (Chapter 3)

Two diagrams, drawn in the traditional academic (Yourdon / DeMarco) notation used
in the course reference:

| Symbol | Meaning |
|---|---|
| Circle | Process |
| Rectangle | External entity |
| Open-ended rectangle | Data store |
| Labelled arrow | Data flow (a double-headed arrow = read/write access to a store) |

| File | What it is |
|---|---|
| `VulcaTrack-DFD-0-Context.png` / `.svg` | **Context diagram (Level 0)** — the whole system as one process with its two external entities and their net data flows. |
| `VulcaTrack-DFD-1-Level1.png` / `.svg` | **Level 1 DFD** — the six major processes, the seven data stores, and the data flows between them. |

The `.svg` files are the editable source (plain text; open in a browser, Figma,
draw.io, or Inkscape). The `.png` files are high-resolution (context 3800 px,
Level 1 5500 px wide) for pasting into the Word document.

## Sources of truth used

`docs/PROJECT-CONTEXT.md`, `docs/decisions/project-decisions.md` (Decisions 1–48),
`docs/ERD/schema.dbml`, and the implemented `vulcatrack/` code. The diagrams show
only approved VulcaTrack scope — nothing from the professor's clothes-shop example
was copied.

## External entities

Only **Customer** and **Admin**. They are the only actors that exchange data with
the software.

- **Supplier** is **not** shown — supplier / procurement is explicitly out of
  scope (project-decisions "Currently Out of Scope"; no `suppliers` table).
- **Tireman** is **not** an external entity — a Tireman is not a system user, has
  no login or dashboard, and exchanges no data with the software (the Admin
  records/assigns Tiremen; the customer phones the assigned Tireman). The Tireman
  therefore appears as stored data (**D7 `tiremen`**), managed by process 4.
- **No "Map / Routing Service" entity** — per Decision 48 the ETA is computed
  inside the system from the captured coordinates and the fixed shop location
  (`config/shop.php`); there is no external routing/directions API. (The older
  2026-08-31 draft DFDs that showed this entity are superseded by these files.)

## Processes (Level 1)

| # | Process | Actor(s) |
|---|---|---|
| 1 | Authenticate & Manage Accounts | Customer, Admin |
| 2 | Manage Vehicles | Customer |
| 3 | Process OTG Request | Customer |
| 4 | Manage Service Requests | Admin |
| 5 | Process Sale (POS) | Admin |
| 6 | Manage Inventory | Admin |

Process 4 also maintains the Tireman roster and assigns an active Tireman to an
accepted request. Processes 3 and 4 exchange nothing directly — the OTG request
is handed over **through data store D6**, which is correct DFD practice.

## Data stores → database tables (`docs/ERD/schema.dbml`)

| Store | Table(s) |
|---|---|
| D1 customers | `customers` |
| D2 admins | `admins` |
| D3 vehicles | `vehicles` |
| D4 items | `items` (unified products + services) |
| D5 sales | `sales` + `sale_items` (master/detail — one store) |
| D6 service_requests | `service_requests` |
| D7 tiremen | `tiremen` |

All 8 approved tables are represented (`sale_items` is folded into D5 as sale line
detail). No store exists for anything outside the 8-table schema — there is no
payments store, receipt store, tracking/location-history store, messaging store,
audit/status-history store, `shop_settings` store, or Tireman-login store.

## Consistency check

1. **External entities exist?** Yes — Customer and Admin only.
2. **Every process is an approved function?** Yes — 1–6 map to Phases 3–6 scope
   (auth, profile/vehicles, OTG submit, OTG handling + Tireman assignment, POS,
   inventory). No "Generate Reports" process is drawn (admin reporting is a thin
   read over D5 and is left to a Level-1 decomposition of a later phase).
3. **Every data store is approved data?** Yes — 1:1 with the 8-table schema
   (sales + sale_items grouped).
4. **Major flows represented?** Customer: credentials/profile, vehicles, OTG
   request + location coordinates, request confirmation/frozen ETA/status/history,
   assigned Tireman name & contact. POS: item selection, cash tendered, stock
   deduction (product lines), sale + line-item records, printable receipt,
   optional customer link. Inventory: item & stock edits, activate/deactivate,
   low-stock alerts. OTG handling: pending queue, accept/reject, Tireman
   assignment, completion.
5. **Invented features?** None.
6. **Out-of-scope features?** None — no live tracking, no continuously updating
   ETA, no persisted route geometry, no online/GCash payment, no cart/checkout,
   no Tireman login.
7. **Professor's symbol conventions followed?** Yes — circle / rectangle /
   open-ended rectangle / labelled arrow, minimal colour, white background.
8. **Arrows are data flows, not control flow?** Yes — every arrow carries a
   noun-phrase data label; process handoffs go through data stores.
9. **Readable for Chapter 3?** Yes — high-resolution PNG, print-safe layout.

## Level chosen

The course reference diagram is a **Level 1 DFD** (several processes, data stores,
detailed labelled flows), so the primary VulcaTrack diagram is **Level 1**. A
separate **Context diagram (Level 0)** is also provided, as is standard for a
Chapter 3, and is kept in its own file (not combined with Level 1).
