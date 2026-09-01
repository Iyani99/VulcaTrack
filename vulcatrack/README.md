# VulcaTrack -- Application

**VulcaTrack: Sales and Inventory with On-the-Go Services.**
Local development runs on XAMPP (Apache + PHP + MariaDB).

## Status

**Phase 3 complete -- authentication and authorization.**
Implemented: customer registration/login/logout, admin login/logout, CLI admin
provisioning, session handling, and the customer / admin authorization guards.

Not yet implemented (later phases): customer dashboard, vehicles, POS, inventory,
OTG requests, maps, Tireman assignment, payments, receipts. Do not add these
until the relevant phase is explicitly approved.

## Design / decision documents

Kept separately in `C:\IPT102\docs\`. The Project Decision Record
(`docs/decisions/project-decisions.md`) is authoritative for all confirmed decisions;
the database design source of truth is `docs/ERD/schema.dbml`.

## Local setup

1. Start **Apache** and **MySQL** from the XAMPP Control Panel.
2. If `config/config.php` is missing:
   `copy config\config.example.php config\config.php` and adjust values.
3. Build the database schema (Phase 2) if it is not present:
   `C:\xampp\mysql\bin\mysql -u root vulcatrack < database\schema.sql`
4. Create your first admin account (see below).
5. Open:
   - <http://localhost/vulcatrack/> -- landing page (shows auth status)
   - <http://localhost/vulcatrack/register.php> -- customer registration
   - <http://localhost/vulcatrack/login.php> -- customer login
   - <http://localhost/vulcatrack/admin/login.php> -- admin login
   - <http://localhost/vulcatrack/health.php> -- environment + database check

## Creating an admin account

There is **no public admin registration** (Decision 18/40). Admins are provisioned
from the command line:

```
php vulcatrack/database/seed_admin.php
```

The script is CLI-only (it refuses to run over the web), prompts for full name,
email and password, enforces the 8-character minimum, hashes the password with
`password_hash()`, and inserts one row into `admins`. It never prints or logs the
password. **Do not commit real admin credentials.**

## Authentication / authorization

| Concern | Implementation |
|---|---|
| Passwords | `password_hash($p, PASSWORD_DEFAULT)` / `password_verify()`; opportunistic `password_needs_rehash()` on login. Never stored in plain text. |
| Identifier | Email only (unique within `customers`, and independently within `admins`). |
| Sessions | Hardened in `includes/bootstrap.php`: custom name, `HttpOnly`, `SameSite=Lax`, `Secure` when `session.cookie_secure` is true (HTTPS), `use_strict_mode`, `use_only_cookies`, cookie path `/vulcatrack/`. Session id regenerated on login and logout. |
| Session contents | actor type, actor id, display name, login timestamp, last-activity timestamp. Nothing sensitive. |
| Idle timeout | `session.idle_timeout` in config (default 1800s / 30 min). Sliding window -- resets on authenticated activity; no absolute cap. |
| Guards | `require_customer()` / `require_admin()` in `includes/auth.php`. A customer session never satisfies the admin guard and vice-versa. |
| CSRF | Per-session token (`src/Auth/Csrf.php`), `hash_equals()` check on every auth POST. Logout is POST-only + CSRF-protected. |
| Enumeration | Generic "Invalid email or password"; dummy `password_verify()` when the account does not exist. |
| Data access | `src/Repository/*` -- prepared statements only. Duplicate email caught via the DB unique constraint (SQLSTATE 23000 / 1062). |

**Not in scope (by decision):** Remember Me / persistent tokens, password reset,
email verification, 2FA, CAPTCHA, account lockout / rate limiting, Tireman/Staff login.

## Structure

| Path | Purpose |
|---|---|
| `index.php` | Landing page + auth-status strip |
| `register.php`, `login.php`, `logout.php` | Customer auth entry points |
| `admin/login.php`, `admin/logout.php`, `admin/index.php` | Admin auth entry points + guarded placeholder |
| `account.php` | Guarded customer placeholder (dashboard is a later phase) |
| `health.php` | Environment + DB connectivity check |
| `config/` | Local configuration -- **not web-accessible** (`config.php` git-ignored) |
| `config/shop.php` | Fixed shop location (Decision 37) -- placeholder values |
| `includes/` | `bootstrap.php`, `db.php`, `auth.php` -- **not web-accessible** |
| `src/Auth/` | `Auth.php` (session/actor lifecycle), `Password.php`, `Csrf.php` |
| `src/Repository/` | `CustomerRepository.php`, `AdminRepository.php` |
| `src/Support/` | `Validator.php` |
| `src/Views/` | Auth form templates + shared partials |
| `assets/` | `css/app.css`, `js/`, `img/` |
| `database/` | `schema.sql`, `seed_admin.php` -- **not web-accessible** |
| `storage/` | Logs / generated files -- **not web-accessible** |

## Verified environment

| Component | Version |
|---|---|
| Apache | 2.4 (Win64), port 80 |
| PHP | 8.0.30 (Apache module + CLI) |
| MariaDB | 10.4.32, port 3306, user `root`, no password |
| phpMyAdmin | <http://localhost/phpmyadmin/> |
| Web root | `C:\xampp\htdocs` (junction to `C:\IPT102\vulcatrack`) |

## Stack

PHP 8.0 &middot; Apache 2.4 &middot; MariaDB 10.4 &middot; vanilla HTML / CSS / JS
