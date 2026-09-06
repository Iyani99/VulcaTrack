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

## Creating an admin account

There is no public admin registration (Decision 18/40/46). Admin accounts are
created from the command line with `seed_admin.php` — this is the supported
mechanism for the first admin and for every additional admin:

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
