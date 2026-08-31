# VulcaTrack -- Application

**VulcaTrack: Sales and Inventory with On-the-Go Services.**
Local development runs on XAMPP (Apache + PHP + MariaDB).

## Status

**Phase 1 -- environment setup and scaffold only. No application features implemented.**
Do not add authentication, POS, inventory, OTG services, or frontend pages until the
next phase is explicitly approved.

## Design / decision documents

Kept separately in `C:\IPT102\docs\`. The Project Decision Record
(`docs/decisions/project-decisions.md`) is authoritative for all confirmed decisions;
the database design source of truth is `docs/ERD/schema.dbml`.

## Local setup

1. Start **Apache** and **MySQL** from the XAMPP Control Panel.
2. If `config/config.php` is missing:
   `copy config\config.example.php config\config.php` and adjust values.
3. Open:
   - <http://localhost/vulcatrack/> -- placeholder landing page
   - <http://localhost/vulcatrack/health.php> -- environment + database check

## Verified environment (Phase 1)

| Component | Version |
|---|---|
| Apache | 2.4.58 (Win64), port 80 |
| PHP | 8.0.30 (Apache module + CLI) |
| MariaDB | 10.4.32, port 3306, user `root`, no password |
| phpMyAdmin | <http://localhost/phpmyadmin/> |
| Web root | `C:\xampp\htdocs` |

## Structure

| Path | Purpose |
|---|---|
| `index.php` | Placeholder landing page |
| `health.php` | Phase 1 environment + DB connectivity check |
| `config/` | Local configuration -- **not web-accessible** (`config.php` git-ignored) |
| `config/shop.php` | Fixed shop location (Decision 37) -- placeholder values |
| `includes/` | `bootstrap.php` + `db.php` -- **not web-accessible** |
| `src/` | Future application PHP -- **not web-accessible** |
| `assets/` | `css/`, `js/`, `img/` |
| `database/` | Schema notes (schema not built yet) -- **not web-accessible** |
| `storage/` | Logs / generated files -- **not web-accessible** |

## Stack

PHP 8.0 &middot; Apache 2.4 &middot; MariaDB 10.4 &middot; vanilla HTML / CSS / JS
