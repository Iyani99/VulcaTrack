# VulcaTrack test harness

A small, dependency-free regression harness. **No Composer, no PHPUnit** — just
PHP and the CLI. It is the regression safety net for Phases 1–5 (foundation,
auth, customer side, inventory and POS); each new chunk adds its own tests here.

## Running

```
C:\xampp\php\php.exe vulcatrack/tests/run.php            # everything
C:\xampp\php\php.exe vulcatrack/tests/run.php unit       # one suite
C:\xampp\php\php.exe vulcatrack/tests/run.php --filter=Geo
```

Exit code `0` = all passed, `1` = at least one failure.

**Prerequisites:** MariaDB must be running (XAMPP Control Panel → MySQL → Start),
the `vulcatrack` schema must be built (`database/schema.sql`), and
`config/config.php` must exist. The `unit` suite needs none of that.

## Layout

| Path | What it covers |
|---|---|
| `run.php` | Test runner. Discovers `*/*Test.php`, runs each `test()` case in isolation, promotes PHP warnings/notices to failures, tallies assertions. |
| `bootstrap.php` | Loads the assertion lib + the application bootstrap. |
| `lib/Assert.php` | `test()` registry and `assert_*()` helpers. |
| `lib/TestDb.php` | `TestDb::rollback()` — run DB work in a transaction that is always rolled back — plus a unique-email helper. |
| `lib/HttpClient.php` | Manages a real `php -S` process (repo root as docroot) and makes cookie-aware requests with libcurl. |
| `unit/` | Pure class logic — `Validator`, `Money` (integer centavos), `Geo` (frozen ETA), `OtgStatus` (4 locked statuses), `Csrf`, `Password`, `PosCart` (session cart rules), the geocoder layer. No DB. |
| `integration/` | `bootstrap` + autoloader, the live 8-table schema + CHECK constraints, `Auth` actor/idle-timeout logic, the Phase 3/4 repositories (ownership isolation, soft delete, always-pending OTG), `ItemRepository`, `SaleRepository`, `SaleService` (atomic checkout, DB-authoritative prices, `FOR UPDATE` locking, full rollback) and the strict DB session. Every DB test rolls back — except `SaleServiceTest`, which cannot (the service owns its own transaction) and instead deletes its seeded rows in a `finally`. |
| `http/` | End-to-end via `php -S`: auth guards, customer/admin actor separation, CSRF enforcement, every Phase 4 page reachable under the right session, geocoding, host-relative (LAN) URLs, the admin shell, Inventory browse + mutations, the POS (cart, customer link, cash checks, checkout) and the Transaction Summary, and no PHP warnings in any response or in the server log. Seeds throwaway rows and deletes them afterwards. |

## Conventions for new tests

- One `test('description', function () { ... })` per behaviour.
- DB tests: wrap the body in `TestDb::rollback($pdo, function () { ... })`.
- Never rely on rows surviving between tests; seed what you need.
- Keep assertions specific — assert the value, not just "not null".
