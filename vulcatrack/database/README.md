# database/

**Phase 2 — schema built.** `schema.sql` in this folder is the executable v1
database schema (11 application tables after manual stock adjustments). It is derived from, and must stay
consistent with, the design source of truth:

- `C:\IPT102\docs\ERD\schema.dbml` — maintainable text schema (authority for structure)
- `C:\IPT102\docs\VulcaTrack-Database-Notes_1.md` — field-by-field rationale
- `C:\IPT102\docs\decisions\project-decisions.md` — authoritative decisions

## Tables (11)

`customers`, `admins`, `tiremen`, `items`, `inventory_restocks`, `inventory_adjustments`, `vehicles`,
`sales`, `sale_items`, `service_requests`, `inventory_settings`.

No payment table, no customer receipt table, no `shop_settings` table, no
status-history / general Activity Log table, no location-history table, and no Tireman
login table yet. Later post-defense domains remain separate batches.

A sale may be linked to at most one Rescue request through the nullable
`sales.service_request_id` (Phase 7.3d-a) — a column, not a new table.

## Fresh install (local MariaDB, XAMPP)

1. Start **MySQL** from the XAMPP Control Panel.
2. From `C:\IPT102\vulcatrack\`:

   ```
   C:\xampp\mysql\bin\mysql -u root vulcatrack < database\schema.sql
   ```

   or import `database\schema.sql` through phpMyAdmin.

`schema.sql` always builds the **current** schema (it already includes every
migration below), so a fresh install never needs the migration files.

**Warning — re-running `schema.sql` wipes the database.** It begins with
`CREATE DATABASE IF NOT EXISTS vulcatrack` and `DROP TABLE IF EXISTS …` for every
table, so running it against a database that already has customers, sales or
rescue requests deletes all of that data and recreates empty tables. Only do
that when you mean to start over.

## Updating an existing database (migrations)

A database built from an older `schema.sql` is brought up to date — without
losing data — by applying the one-off files in `database/migrations/`, oldest
first, **once each**. Each file explains how to check whether it is already
applied and how to roll it back.

| File | Change |
|---|---|
| `migrations/2026-09-28-sales-service-request.sql` | Phase 7.3d-a: adds nullable `sales.service_request_id` (UNIQUE, FK → `service_requests.request_id`, RESTRICT). Existing sales stay `NULL`. |
| `migrations/2026-09-29-customers-avatar-filename.sql` | Phase 7.4b-e2 (Decision 77): adds nullable `customers.avatar_filename VARCHAR(64)` — the generated file name of a customer's private profile picture. Existing customers stay `NULL`. |
| `migrations/2026-09-29-service-requests-feedback.sql` | Phase 7.4c (Decision 78): adds nullable `service_requests.feedback_rating` (TINYINT UNSIGNED, CHECK 1–5 `chk_service_requests_feedback_rating`), `feedback_comment` (VARCHAR(500)) and `feedback_submitted_at` (DATETIME). Existing requests stay `NULL` (no feedback). |
| `migrations/2026-10-12-inventory-restocks.sql` | Inventory Foundation (Decision 87): adds Product receipt history with quantity, optional free-text supplier, recording Admin, and timestamp. Existing stock stays unchanged; no historical receipts are invented. |
| `migrations/2026-10-12-restock-snapshots.sql` | Adds immutable stock-before/stock-after receipt snapshots and an optional 500-character note. Apply after the first restock migration while `inventory_restocks` is empty; existing Product stock is untouched. |
| `migrations/2026-10-12-inventory-settings.sql` | Adds one `inventory_settings` row with a 1–365 day slow-moving threshold, initially 60, and nullable last-updating Admin attribution. Apply after the Inventory Foundation migrations. This does not alter Product or Sales data. |
| `migrations/2026-10-12-product-slow-moving-override.sql` | Adds the nullable, Product-only 1–365 day override; NULL uses the existing shop default. Apply once after Inventory Intelligence. |
| `migrations/2026-10-12-inventory-adjustments.sql` | Adds append-only manual Product stock corrections with direction, reason, note, before/after stock, Admin, and time. Apply once; no historical backfill. |

```
C:\xampp\mysql\bin\mysql -u root vulcatrack < database\migrations\2026-09-28-sales-service-request.sql
C:\xampp\mysql\bin\mysql -u root vulcatrack < database\migrations\2026-09-29-customers-avatar-filename.sql
C:\xampp\mysql\bin\mysql -u root vulcatrack < database\migrations\2026-09-29-service-requests-feedback.sql
C:\xampp\mysql\bin\mysql -u root vulcatrack < database\migrations\2026-10-12-inventory-restocks.sql
C:\xampp\mysql\bin\mysql -u root vulcatrack < database\migrations\2026-10-12-restock-snapshots.sql
C:\xampp\mysql\bin\mysql -u root vulcatrack < database\migrations\2026-10-12-inventory-settings.sql
```

The Inventory Intelligence migration was applied to the local real/demo database
on 2026-10-12 after a verified backup. Do not reapply it there. For another
database, inspect its current schema and back it up before applying this one-off
migration. Do not rerun `schema.sql` against an existing database.

Running a migration a second time fails with a clear error (e.g. `Duplicate
column name`) and changes nothing.

## Profile picture files (`storage/avatars/`)

The database stores only each picture's file name; the images themselves live in
`vulcatrack/storage/avatars/` (created on the first upload, git-ignored, and denied
to the web by `storage/.htaccess` — they are served only through
`customer/avatar.php`). Back this folder up together with the database, keep it
when redeploying the code, and make sure PHP can write to it. After rebuilding the
database from `schema.sql`, the files left in the folder are no longer referenced
and can be deleted.

## Creating an admin account

There is no public admin registration (Decision 18/40/46). The **first** admin is
created from the command line with `seed_admin.php`, which also stays the recovery
and development path. Additional admins can instead be created by a signed-in
admin on the authenticated Admin Accounts page (`admin/accounts.php`,
Decision 79). The command-line script:

```
php vulcatrack/database/seed_admin.php
```

Run it from a terminal with MySQL started. It refuses to run over the web,
prompts for full name, email and password (password entry is hidden on Windows
PowerShell and on POSIX shells; it falls back to visible input elsewhere),
enforces the 8-character minimum, hashes the password with `password_hash()`,
and inserts one row into `admins`. A duplicate email is rejected by the unique
key and the script exits non-zero. The plaintext password is never printed,
logged, or stored. Do not commit real credentials.

## Environment

MariaDB 10.4.32 · engine InnoDB · charset `utf8mb4` / `utf8mb4_unicode_ci`.
