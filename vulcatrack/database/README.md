# database/

**Phase 2 — schema built.** `schema.sql` in this folder is the executable v1
database schema (8 application tables). It is derived from, and must stay
consistent with, the design source of truth:

- `C:\IPT102\docs\ERD\schema.dbml` — maintainable text schema (authority for structure)
- `C:\IPT102\docs\VulcaTrack-Database-Notes_1.md` — field-by-field rationale
- `C:\IPT102\docs\decisions\project-decisions.md` — authoritative decisions

## Tables (8)

`customers`, `admins`, `tiremen`, `items`, `vehicles`, `sales`, `sale_items`,
`service_requests`.

No payment table, no receipt table, no `shop_settings` table, no
status-history / audit table, no location-history table, no separate
Staff / Tireman login table — by explicit decision.

## Load / rebuild (local MariaDB, XAMPP)

1. Start **MySQL** from the XAMPP Control Panel.
2. From `C:\IPT102\vulcatrack\`:

   ```
   C:\xampp\mysql\bin\mysql -u root vulcatrack < database\schema.sql
   ```

   or import `database\schema.sql` through phpMyAdmin.

`schema.sql` begins with `CREATE DATABASE IF NOT EXISTS vulcatrack` and
`DROP TABLE IF EXISTS …`, so it is safe to re-run to rebuild a clean schema
during development. The database holds no seed/application data in v1.

## Environment

MariaDB 10.4.32 · engine InnoDB · charset `utf8mb4` / `utf8mb4_unicode_ci`.
