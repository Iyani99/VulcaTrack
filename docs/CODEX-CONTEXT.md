# VulcaTrack — Codex continuity context

Verified against the local repository on **2026-10-08 (Asia/Manila)**. This is the starting context for future Codex work, not a new product decision or a claim that the deployed system was tested. Status terms below distinguish committed implementation, historical reports, deferred decisions, and open questions.

## 1. Purpose and authority

**DO NOT EDIT `docs/PROJECT-CONTEXT.md`.** It is the original Claude-era broad project and development history, retained as a read-only reference for Codex even where its older summaries have drifted. This file is the current Codex-era continuity document. It records the cross-check at the stated checkpoint; it does not replace source inspection or approve features.

| Source | Role |
|---|---|
| `docs/CODEX-CONTEXT.md` | Current Codex continuity and verification provenance. Read first in future Codex sessions. |
| `docs/PROJECT-CONTEXT.md` | Original Claude-era historical/project context. **Read-only for Codex.** |
| `docs/decisions/project-decisions.md` | Approved decision history, including Decisions 60 and 65–79, with a dated clarification of Decision 77's later header display. Read historical entries in their dated context. |
| `docs/ERD/schema.dbml` | Authoritative database design. |
| `docs/DEVELOPMENT-LOG.md` | Chronological post-handoff log, currently an untracked local file. Preserve it; it is not a substitute for Git or current code. |

## 2. Project identity

- **Title:** VulcaTrack: Sales and Inventory with On-the-Go Services.
- **Client:** Gerald Tabayag Vulcanizing Shop, a single vulcanizing/tire shop.
- **Repository:** `C:\IPT102`; application: `C:\IPT102\vulcatrack`; documentation: `C:\IPT102\docs`.
- **Local Apache path:** `C:\xampp\htdocs\vulcatrack` is a verified Windows junction to `C:\IPT102\vulcatrack`, not a second source copy.
- **Branch:** local `main`. Configured `origin` fetch/push URL: `https://github.com/Iyani99/VulcaTrack.git`. A live GitHub query was not made.

## 3. Claude → Codex boundary

The annotated `checkpoint-2026-10-03` tag has message **“Development checkpoint before tooling handoff”** and its tag object names commit `5ed81e41fc89e7fa213b53a644f7ab6b51d2bff6`. That commit is `docs(diagrams): show admin accounts in the level 1 DFD and refresh diagram status notes`. The immediately preceding application-code commit is `545e73b feat(admin): add admin accounts page for creating additional admins`. Commits `cc4e22c`, `7b5c2ee`, `4cf9add`, `d00a00c`, and `58b8f0a` also exist in local history with the expected subjects. At the 2026-10-08 pre-closure inspection, HEAD still equaled the boundary; later commits must be checked in Git.

The reported clean working tree at the **original** boundary is historical. Later pre-commit tree states are recorded in §4 and §21.

## 4. Pre-commit Git state (historical)

**2026-10-08, before creating this file:** `HEAD`, local `origin/main`, and local `origin/HEAD` each resolved to `5ed81e41fc89e7fa213b53a644f7ab6b51d2bff6`; branch `main`; no staged or modified tracked files; `docs/DEVELOPMENT-LOG.md` untracked. The configured remote URL was checked with `git remote -v`. **Local remote-tracking refs were not proof of the live GitHub state**, since no fetch or live remote query was performed. At its creation, this file became a second untracked file; neither document was staged. This is historical provenance, not a claim about later Git state.

## 5. Source-of-truth hierarchy

For current Git state, inspect the actual local repository. For domain conflicts, use this order unless newer repository evidence clearly proves a later deliberate decision: (1) latest explicitly approved **and committed** behavior, (2) latest explicitly approved decision, (3) decision record, (4) `schema.dbml`, (5) read-only `PROJECT-CONTEXT.md`, (6) `database/schema.sql`, (7) implementation, (8) automated tests, (9) regenerated diagrams, (10) Figma for visual reference, (11) older documentation, (12) assumptions. A source's old status sentence cannot override later committed work. Record genuine conflicts; do not silently rewrite a decision.

## 6. Tech stack

The committed application uses server-rendered PHP pages, PDO/MariaDB, HTML/CSS, vanilla JavaScript, and vendored Leaflet with OpenStreetMap tiles. It has shared view partials, repositories, `SaleService`, support classes, and a dependency-free PHP test runner; no Composer framework or JS build runtime is present. The local PHP CLI is **8.0.30**; `schema.sql` targets MariaDB **10.4.32** and the project uses XAMPP/Apache. On 2026-10-08, the configured local database connection reached a running **10.4.32-MariaDB** server; no service start or configuration change was needed. Apache's running version was not verified. No Laravel, React, Firebase, PostgreSQL, SPA, or active Vue implementation was found. Vue was historically allowed only where useful, not adopted as the architecture.

## 7. Actors and authentication

- **Customer — VERIFIED CURRENT:** public signup; separate customer login; dashboard, profile/password, private avatar, saved Vehicles, Book a Rescue, bookings/status/history, and one-time completed-Rescue feedback. `customers.contact_number` is required.
- **Admin — VERIFIED CURRENT:** separate `/admin/login.php`, Dashboard, Inventory, POS, Sales History, Transaction Summary, Rescue handling, Tiremen, Reports, and authenticated Admin Accounts creation. All Admin accounts have equal access; `admins` has no role or permission column. Authorized shop personnel may be given Admin accounts, but this does not grant every worker access automatically. No public Admin signup, Customer/Admin selector, RBAC, or super-admin. Customer login remains customer-focused and links to the separate Admin route. First/recovery Admin seeding remains CLI-only through `database/seed_admin.php`.
- **Tireman — VERIFIED CURRENT:** business/assignment record with contact information and `is_active`; Admin manages it and assigns an active Tireman. There is **no Tireman account, password, login, dashboard, or live GPS**.

`Auth` permits one authenticated actor type per session, and customer/admin guards reject the other actor. The browser does not choose the authorizing Admin on Admin Accounts: the signed-in Admin's own current password is verified against the session-derived Admin row. A newly created Admin is not signed in; the acting Admin remains signed in. No Admin invitation, routine edit, deletion, or deactivation workflow is implemented.

## 8. Database model

**VERIFIED CURRENT in DBML, fresh-install SQL, three migrations, application references, and the local database on 2026-10-08: exactly 8 main application tables.** Read-only inspection of the configured `vulcatrack` database found all eight tables with the expected columns and InnoDB engine. It confirmed nullable `sales.service_request_id` with `uq_sales_service_request` and a RESTRICT foreign key to `service_requests.request_id`; nullable `customers.avatar_filename` (`varchar(64)`); and nullable `service_requests.feedback_rating` (`tinyint unsigned`), `feedback_comment` (`varchar(500)`), and `feedback_submitted_at` (`datetime`) with the named 1–5 rating CHECK.

| Table | Current design and invariants |
|---|---|
| `customers` | Unique email within customers, required contact, password hash, nullable `avatar_filename`; no `is_active`. |
| `admins` | Unique email within admins, password hash; no role column. Customer and Admin email uniqueness are independent. |
| `tiremen` | Name/contact and `is_active`; no login credentials. |
| `vehicles` | Belongs to a customer; `is_active` soft selection state. |
| `items` | Product or Service, nullable free-text category, `is_active`; product stock/reorder fields, no Service stock deduction. Sold item type is protected in application code by Decision 69. |
| `sales` | Nullable customer (`NULL` = Walk-in), required recording Admin, system-set `sale_date`, `total_amount`, nullable **UNIQUE** `service_request_id` FK. No stored cash tender, change, payment method, or payment status. |
| `sale_items` | `quantity`, frozen `unit_price`, frozen `subtotal` linked to sale and item. |
| `service_requests` | Required Customer/Vehicle, nullable handling Admin/Tireman, problem, coordinates, frozen `eta_minutes`, four-value status, nullable `feedback_rating`/`feedback_comment`/`feedback_submitted_at`. |

The three committed one-off migrations add `sales.service_request_id`, `customers.avatar_filename`, and the three feedback columns plus rating check. Fresh `schema.sql` already includes them; it **drops all eight tables** when rerun and must never be used as an upgrade. There is no separate payment, feedback, category, cart, avatar, status-history, shop-settings, or GPS/route table. The inspected **local** database has the resulting schema; whether migrations were applied on any other database remains **UNVERIFIED**.

## 9. Sales / POS invariants

**VERIFIED CURRENT in `SaleService`, repositories, POS, and integration/HTTP tests:** `SaleService::checkout()` alone owns one database transaction and refuses nesting. It uses DB prices, active item/type/stock data, and a system-set sale timestamp. It freezes prices/subtotals and calculates money as integer centavos with overflow limits. The optional expected total is a stale-screen assertion; a mismatch aborts the whole transaction. Item rows are locked `FOR UPDATE` in ascending ID order. Products require sufficient stock and use a guarded nonnegative decrement; Services leave stock alone. Sale header, lines, Rescue link, and stock changes roll back together on failure. A normal in-shop sale may be Walk-in or optionally linked to an existing registered Customer; POS does not create Customer accounts. The session cart is temporary UI state, not a database cart. Once an item appears in `sale_items`, its `item_type` cannot be changed through the application; stale inventory edits cannot restore stock deducted by POS.

## 10. Rescue domain rules

**Booking — VERIFIED CURRENT:** an authenticated Customer with a required contact number chooses their own active saved Vehicle, supplies a problem and location, then submits. Location may come from browser geolocation, an explicit landmark/address search through the authenticated Nominatim proxy, manual coordinate input, or map-marker adjustment; final validated coordinates are stored. The server uses `Geo::haversineKm()` with the configured average speed, rounds up and applies a minimum, then saves `eta_minutes` once. Later screens read that frozen value. The displayed line is a straight-line map visualization. There is no road routing, live Tireman location, moving marker, telemetry, stored route, or continuous ETA.

**Status — VERIFIED CURRENT:** exactly `pending`, `accepted`, `rejected`, `completed`. The only state changes are `pending → accepted` (active Tireman assigned in the same guarded action), `pending → rejected`, `accepted → completed` (Tireman assigned, even if later inactive), and `accepted → rejected` (only with no linked Sale). `accepted → accepted` is a guarded reassignment to a different active Tireman with an expected current assignee, not a new status. Rejected/completed are final. Guarded updates check the expected status; `admin_id` records the last acting Admin. Assigned Tiremen remain on final requests as history. “Tireman is on the way” is display text for an assigned accepted request, not a fifth status.

**Rescue Sale — VERIFIED CURRENT:** `sales.service_request_id` gives a zero-or-one Sale per Rescue and zero-or-one Rescue per Sale. Only accepted/completed requests are eligible; pending/rejected are not. The Rescue's Customer is authoritative; the sale cannot be Walk-in or linked to someone else. The same POS starts Rescue mode only from a clean cart, keeps the request ID in the session, checks stale tabs, and rechecks eligibility at checkout. `SaleService` locks the request before items, checks for a linked Sale after item locks, and relies on the UNIQUE key as the final duplicate guard. Recording a Sale does **not** complete or otherwise change the Rescue; completion does not require a Sale; a completed Rescue may receive a later Sale. Once a Sale is linked, rejection is refused. There is no unlink workflow.

**Feedback — VERIFIED CURRENT:** the owning Customer can submit one rating from 1–5 and optional comment on a completed Rescue with an assigned Tireman. A guarded update prevents overwrites and leaves request status/assignment/Admin timestamps and Sales unchanged. Admin sees it read-only. It is feedback on the Rescue, **not** a Tireman score/ranking system; no averages, public profile, tips, wallet, refund, or dispatch influence exist.

## 11. Transaction Summary and cash tender

**VERIFIED CURRENT:** POS accepts cash received, validates it against the authoritative total, and calculates/displays change. It stores neither tender nor change. The database stores `sales.total_amount` and frozen line money only; no payment method/status is recorded. Persistence of `sales.cash_tendered DECIMAL(10,2) NULL` was discussed but **not approved or implemented** at this checkpoint; derived rather than stored change was part of that discussion. Its absence is not a defect.

The printable document is named **Transaction Summary**. It reads recorded sale values, includes Sale source/Rescue link where applicable, and print CSS hides app chrome/actions. It says “For transaction reference only. Not an official BIR invoice.” It is not an Official Receipt or official invoice. Item, cashier, and Customer names are live joins, while monetary values and `sale_date` are stored snapshots.

## 12. Current implemented features

**VERIFIED CURRENT in committed files, with relevant test coverage present:** PHP foundation and eight-table model; customer registration/login/logout and separate Admin login; public landing page; customer dashboard/shell and responsive/Figma alignment; profile/password/private avatar upload, removal, owner-only serving, and header display; saved Vehicles; Book a Rescue with geolocation, landmark search, map adjustment and frozen ETA; booking confirmation/status/history; Tireman management; Admin Rescue list/detail, guarded actions and active assignment/reassignment; Inventory; POS with atomic SaleService, Walk-in and registered-Customer in-shop Sales; Rescue-linked Sales; Sales History; Reports with Sales Performance chart and Sales by Source; printable Transaction Summary; completed-Rescue feedback; redesigned Admin login; Admin Dashboard summary/attention cards; and Admin Accounts/Create Admin. **Phase 7 is not evidenced as closed.** Do not rebuild a feature because an older phase summary still calls it future work.

## 13. Security invariants

Preserve password hashing/verification and login-time rehash; fake verification for unknown accounts and generic invalid-credential messages; session ID regeneration and actor separation; the configured 1,800-second sliding idle timeout; customer/admin guards; POST-only, CSRF-protected logout and state-changing forms; prepared SQL and output escaping; ownership checks for Vehicles, bookings, feedback and private avatar delivery; upload content/size checks and web-denied avatar storage; strict SQL mode set on each PDO session; guarded Rescue/inventory updates; POS row locks, stale-total check, stock guard and full rollback; Admin Accounts reauthentication against the trusted session identity. The CLI seed path is for bootstrap/recovery/development, not public registration.

## 14. Automated test state

| Provenance | Result | Interpretation |
|---|---|---|
| Older repository-documented run at `7d158e6` | **233 passed, 0 failed, 3,486 assertions, 41 files** | Historical; repeated in older status text. |
| Later handoff-reported pre-boundary run for content committed at `545e73b` | **263 passed, 0 failed, 4,668 assertions, 49 files** | Historical **supplied report** in the handoff and untracked Development Log; not independently reproduced or found as a committed test result. |
| Fresh 2026-10-08 unit run: `C:\xampp\php\php.exe vulcatrack\tests\run.php unit` | **96 passed, 0 failed, 581 assertions, 13 files; OK** | Verified now. |
| Fresh 2026-10-08 full-suite attempt: `C:\xampp\php\php.exe vulcatrack\tests\run.php` | **238 passed, 25 failed, 1,532 assertions, 49 files** | Local MariaDB/schema was compatible; integration and unit tests passed. HTTP tests failed: the first expected a 302 redirect but received 200, and the remaining 24 reported `proc_open(...vulcatrack_test_18376_serverlog.txt): Failed to open stream: Permission denied` under the system Temp directory. This is an environment/setup-blocked attempt, **not a passing full-suite baseline**. |
| Fresh 2026-10-08 HTTP-only retry: `C:\xampp\php\php.exe vulcatrack\tests\run.php http` | **0 passed, 25 failed, 29 assertions, 21 files** | A verified writable, dedicated directory under the user's local Temp area was supplied through process-only `TEMP`/`TMP` overrides. The first case again received 200 instead of 302; the remaining 24 again could not open the shared server log. A PHP child from the first case remained listening on its test port 8702 after the harness tried to stop it, leaving the log inaccessible. That confirmed a test-server teardown/locked-log blocker, not a generally unwritable Temp directory. The orphan was positively identified and stopped; no full-suite rerun followed. |
| Fresh 2026-10-08 HTTP subset after harness fix, with normal XAMPP Temp access | **25 passed, 0 failed, 3,169 assertions, 21 files; OK** | `C:\xampp\php\php.exe vulcatrack\tests\run.php http`; no PHP warnings/notices or leftover test server. The earlier 302/200 result disappeared when PHP sessions could use configured `C:\xampp\tmp`. |
| Prior Codex-era complete-suite baseline, 2026-10-08, with normal XAMPP Temp access | **263 passed, 0 failed, 4,672 assertions, 49 files; OK** | `C:\xampp\php\php.exe vulcatrack\tests\run.php` from `C:\IPT102`; separate from the historical handoff report of 263/0/4,668/49. No global PHP or Windows setting was changed. |
| Customer UI/UX checkpoint full suite, 2026-10-08, with normal XAMPP Temp access | **263 passed, 0 failed, 4,676 assertions, 49 files; OK** | Fresh `C:\xampp\php\php.exe vulcatrack\tests\run.php` result for that checkpoint. |
| **Admin UI foundation full suite, 2026-10-09**, with normal XAMPP Temp access | **263 passed, 0 failed, 4,708 assertions, 49 files; OK** | Fresh `C:\xampp\php\php.exe vulcatrack\tests\run.php` from `C:\IPT102` after Admin Chunks 1, 1B and 1C. |

Current test discovery has **49 files**: 13 unit, 15 integration, 21 HTTP. The dependency-free runner treats PHP warnings during a test as failures. The full-suite command is `C:\xampp\php\php.exe vulcatrack\tests\run.php`. The earlier failed attempts were caused by two test-environment issues: the HTTP helper's string `proc_open()` command launched a Windows shell wrapper (its PID differed from the server PID), so teardown left the PHP server holding its log; and sandboxed HTTP server processes could not write to XAMPP's configured `C:\xampp\tmp` for sessions/uploads. `tests/lib/HttpClient.php` now launches PHP directly with an argument array and `bypass_shell`, terminates that process through its handle, and cleans up on startup timeout. Two consecutive lifecycle probes confirmed handle PID = listening PID, port release, and log reopen after each stop. The green runs were launched outside Codex filesystem sandbox so PHP could use its existing XAMPP Temp configuration; no global PHP or Windows setting or application code changed. Counts in all eight application tables matched before and after the final suite; this is a net-count check, not proof that every row's contents were unchanged.

## 15. Documentation drift and known conflicts

| Area | Stale/conflicting source | Current verified truth | Action needed |
|---|---|---|---|
| Header avatar — **CLARIFIED** | Decision 77's original display bullet says account panel only. | Later committed `7b5c2ee` deliberately shows the signed-in owner's avatar in the Customer header. | A dated implementation clarification follows Decision 77; its original wording remains historical. |
| Phases/test status — **CURRENT DOCS ALIGNED** | `PROJECT-CONTEXT.md` stays read-only and historically stops at older Phase 7 work. | The decision record and app README state later built features; the Admin UI baseline is **263/0/4,708/49**, after the Customer UI **263/0/4,676/49** and earlier results recorded above. | Preserve dated history; Phase 7 is still open. |
| Reports and item type — **CORRECTED** | Older Reports prose said no chart/source split and sold item type editable. | App README now describes Sales Performance, Sales by Source, and protected sold item types. | Historical development entries are unchanged. |
| Location — **CORRECTED** | A DBML note and Database Notes said browser GPS only. | Both now describe browser geolocation or landmark/address search with marker adjustment. | No schema structure or application behavior changed. |
| Paper and technical diagrams — **SEPARATED** | Older Level 0 and use-case image lacked current flows; activity and POS sequence showed more detail than requested for the paper. | Paper Level 0, two-lane Book a Rescue activity, three-participant POS sequence and use case are separate from the maintained detailed Activity, POS Sequence, Use Case and Level 1 DFD; the canonical ERD is shared. | Paper simplification does not redefine implemented behavior. Older flowcharts 2, 4 and 5 remain separately flagged. |
| UI backlog — **STALE DOCUMENTATION** | Old responsive, date, navigation and Transaction Summary back-link items appear unfinished. | Responsive/Figma changes, several friendly date displays, and links to Sales History/Rescue are committed; remaining screen-specific issues need a browser review. | Reassess each item before treating it as work. |
| Root README — **CORRECTED** | Database code fence was unclosed. | Fence closed in the current documentation pass. | No further action for that fence. |

The avatar display difference is recorded as a later implementation clarification; private, owner-only access remains the same. The older `PROJECT-CONTEXT.md` and dated revision entries remain historical.

## 16. Paper and diagram direction

**Diagram policy (2026-10-08, uncommitted documentation pass):** paper figures are intentionally simplified for research presentation and do not redefine application behavior. `docs/ERD/schema.dbml` defines the shared canonical eight-table ERD. The paper Level 0 DFD and technical Level 1 DFD are both maintained. Activity, POS Sequence, and Use Case each have separate `-Paper` and `-Detailed` SVG/PNG versions with matching PHP sources; `docs/diagram-src/README.md` maps their names. The detailed Activity and Sequence preserve valid pre-alignment system detail. The old Use Case PNG was materially stale, so its technical replacement uses a new current generator. Older flowcharts remain separate artifacts.

**Professor paper presentation direction now reflected in the four updated figures (documentation only):**

- **DFD:** refreshed **Level 0 / Context DFD** is preferred for presentation and includes feedback, Sales History/Reports, Admin Accounts and Transaction Summary. Level 1 remains available for detail.
- **Activity:** one use case, **Book a Rescue**, with exactly Customer and VulcaTrack System lanes; Customer login is a precondition. Customer opens booking, chooses an active saved Vehicle, describes the problem, provides location by browser geolocation or landmark/address search with optional map-marker adjustment, confirms contact number and submits. An **unlabeled merge in the System lane** leads to validation. Invalid input: System shows errors → Customer corrects and resubmits → back to merge → validation; no direct correction-to-confirmation link and no bidirectional correction/merge connector. Valid input: System computes ETA once, saves Pending, then Customer views confirmation/ETA/Pending and ends in Customer lane. Exclude Admin review, assignment, roadside work, POS and feedback from this diagram.
- **POS sequence:** **Admin / VulcaTrack POS System / Database** only. Show item/price/stock lookup, cart total, optional registered Customer or Walk-in, cash tender/checkout validation, recorded transaction, Sale number/total/change, and optional printable Transaction Summary. Keep SQL locks/commit/rollback/expected-total internals in code, not the paper diagram.
- **Use case:** the generated figure uses “Book a Rescue,” “Process Sale (POS),” “Manage Rescue Requests,” “View Rescue Status / Bookings,” and “Submit Rescue Feedback,” with shared Log In and bottom Log Out. Tireman remains a managed record with no login.

## 17. Deferred and future work

- **APPROVED BUT DEFERRED:** Decision 60 permits one-time road-following route display and road distance feeding the existing server-side, frozen ETA, with haversine fallback and no new table/route persistence. Current code still uses haversine. This is distinct from live tracking.
- **DISCUSSED / NOT APPROVED:** persisting cash tender (`sales.cash_tendered DECIMAL(10,2) NULL` was considered); Customer deactivation, Rescue cancellation, emailed Transaction Summary, rate limiting/lockout, password reset, 2FA, historical-name snapshots, pagination, `sale_date` indexing, demo data, cosmetic/deployment refinements, and broader Admin customer-management scope remain candidate work or open questions, not current requirements. No `cancelled` status or ninth table is approved here.
- **INTENTIONAL NON-FEATURES:** live GPS, moving Tireman marker, continuous ETA, in-app chat, customer online cart/checkout, online/GCash payment, Tireman login/dashboard/rankings, Admin RBAC/public Admin signup, Customer hard delete/current deactivation, current Customer Rescue cancellation, status-history/audit table, supplier/procurement module, multi-location stock, official BIR invoicing, persisted tender or route geometry. Do not label these absences bugs without a new decision.

## 18. Deployment state

**UNVERIFIED as production:** no committed evidence establishes a production deployment, production database migration state, HTTPS configuration, or successful current live site. The prior Cloudflare Quick Tunnel was temporary local exposure, not production; old tunnel URLs are ephemeral. Possible hosting such as Hostinger was discussed, not verified. Deployment readiness still includes persistent writable/backed-up private avatar storage, suitable PHP upload limits, production error display settings, HTTPS session cookie configuration, and host-specific geocoder referer. None was tested on a production host here.

## 19. Codex operating rules

Read this file first, then inspect the relevant code, decisions, DBML, migrations, tests and current Git state before editing. Consult `PROJECT-CONTEXT.md` for deeper history but **never edit it**. Do not redo implemented features. Prefer the smallest maintainable change and preserve data integrity/security before cosmetic work. Treat paper presentation as separate from application behavior. Do not silently resolve conflicts or invent requirements. Git staging, commits, pulls, pushes, resets, merges, rebases and tag changes require separate user authorization. Update this file only when explicitly authorized or asked for context synchronization; preserve `DEVELOPMENT-LOG.md` as a chronological log.

## 20. Current open discrepancies and unknowns

1. Decision 77's original display restriction is retained as history; the later committed Customer header behavior is documented by a dated clarification. Any new avatar policy change needs its own decision.
2. Live GitHub branch/tag state was not queried; `origin/main` and `origin/HEAD` here are local refs only.
3. Local MariaDB 10.4.32 and the eight-table schema, including all three later additions, were verified on 2026-10-08. The HTTP harness teardown was repaired and local HTTP/full tests now pass with normal XAMPP Temp access. The current full baseline is **263/0/4,708/49**; prior **263/0/4,676/49**, **263/0/4,672/49** and supplied handoff **263/0/4,668/49** figures remain historical. Other databases and production HTTP behavior remain unverified.
4. Level 0 and the separate paper and technical Activity, Sequence and Use Case figures are refreshed in this uncommitted documentation pass. Older flowcharts 2, 4 and 5 remain flagged separately; paper placement/approval is not established by this file.
5. Actual production deployment/host configuration is unverified. Candidate work in §17 requires its own decision and scope check.

## 21. Prior verification checkpoint

- **Date:** 2026-10-08 (Asia/Manila).
- **Documentation alignment inspection:** began from `29e30ce126f380f2acda74d183554f294847e5d3` on `main`, with local `origin/main` matching and only `docs/DEVELOPMENT-LOG.md` untracked. This pass changed documentation/diagram files only; no new application suite was run, and no Git staging/commit/push was performed.
- **Pre-closure HEAD:** `5ed81e41fc89e7fa213b53a644f7ab6b51d2bff6` on `main`; annotated checkpoint points there. Local `origin/main` and `origin/HEAD` matched at that inspection; no live remote check.
- **Pre-closure working tree:** before writing this file, only `docs/DEVELOPMENT-LOG.md` was untracked; no staged or modified tracked files. Immediately before closure staging, only `vulcatrack/tests/lib/HttpClient.php` was modified among tracked files; this file and `docs/DEVELOPMENT-LOG.md` were untracked; nothing was staged. Inspect Git for the post-commit state.
- **Database:** configured local MariaDB connection succeeded; server reported **10.4.32-MariaDB**. Exactly eight InnoDB application tables and the three later schema additions were present. No migration or schema script was run. Counts in all eight tables matched before and after the test attempt.
- **Tests:** fresh unit subset **96/0/581/13**. Historical failed attempts: full **238/25/1,532/49**, HTTP-only **0/25/29/21**. After the HTTP test-helper fix and with XAMPP Temp access, HTTP subset **25 passed / 0 failed / 3,169 assertions / 21 files** and fresh complete suite **263 passed / 0 failed / 4,672 assertions / 49 files**. The historical handoff **263 / 0 / 4,668 / 49** remains historical. Apache was not required because HTTP tests start their own PHP server. No test server remained running after the green suite.
- **Original context-creation inspection:** full `PROJECT-CONTEXT.md`; decision entries and status/revision sections; `schema.dbml`, `schema.sql`, all three migrations, `DEVELOPMENT-LOG.md`, relevant READMEs/diagram sources and notes; auth/session/CSRF code; Customer booking/geocode/map/avatar/feedback pages; Admin login/accounts/POS/Rescue/Reports/Transaction Summary pages; SaleService, relevant repositories/support; test runner, DB test helper and representative unit/integration/HTTP tests; Git status/history/remotes/tag. No application, existing documentation, migration, test, diagram or Git object was changed in that original context-creation task.

## 22. Current UI/UX checkpoint

**2026-10-08:** Shared motion tokens and reduced-motion handling now cover buttons, links, forms, navigation, and tables. Prefer smooth transitions unless instant feedback is the better UX. Customer screens have stronger card depth, Quick Action hover/lift, a floating Login card, a fluid nav indicator, and short page-entry motion. Book a Rescue shows green completion feedback for valid editable steps while PHP errors remain authoritative. Request Submitted enters from 32px over 520ms, followed by the success-check draw; the Dashboard primary rescue CTA has scoped hover/focus emphasis. These presentation changes leave domain rules with the server. Fresh full regression: **263 passed, 0 failed, 4,676 assertions across 49 files; OK**.

**2026-10-09 — Admin UI foundation (Chunks 1, 1B, 1C):** Admin workspace and Dashboard grids are fluid and responsive, with shared card/panel depth, refined sidebar/Logout/New Sale states, and reduced-motion support. Only true Admin section navigation receives the 220ms page entrance; a successful POS Add animates Current sale for 650ms. Rescue status tabs have a moving underline. POS has All/Products/Services without Clear; its catalogue groups Services Offered before Products, uses unified card styling, and shows stock status only for Products. Domain and transaction behavior are unchanged. Fresh full regression: **263 passed, 0 failed, 4,708 assertions across 49 files; OK**.
