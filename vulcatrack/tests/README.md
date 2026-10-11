# VulcaTrack test harness

A small, dependency-free regression harness. **No Composer, no PHPUnit** — just
PHP and the CLI. It is the regression safety net for Phases 1–6 (foundation,
auth, customer side, inventory and POS, admin OTG handling, Tiremen, Sales History
and Reports); each new chunk adds its own tests here.

## Running

```
C:\xampp\php\php.exe vulcatrack/tests/setup-db.php --check  # validate target and schema, no writes
C:\xampp\php\php.exe vulcatrack/tests/setup-db.php          # explicit reset of vulcatrack_test
C:\xampp\php\php.exe vulcatrack/tests/run.php            # everything
C:\xampp\php\php.exe vulcatrack/tests/run.php unit       # one suite
C:\xampp\php\php.exe vulcatrack/tests/run.php --filter=Geo
```

Exit code `0` = all passed, `1` = at least one failure.

**Prerequisites:** Copy `config/config.test.example.php` to the git-ignored
`config/config.test.php`. The template reuses local connection settings from
`config/config.php` but explicitly selects `vulcatrack_test`; it contains no
credentials. All suites require both config files. Unit tests do not need
MariaDB. Integration and HTTP tests require MariaDB and a test database built
with the explicit `setup-db.php` command above.

The runner and its HTTP child server always enable test mode. Missing test
config, any configured name other than exactly `vulcatrack_test`, or a
connection whose `SELECT DATABASE()` differs causes an immediate failure
before DB-backed tests start. There is no fallback to `vulcatrack`. The setup
command validates and discards `schema.sql`'s production `CREATE DATABASE` and
`USE` statements; it runs only its table DDL on `vulcatrack_test`. Never run
`database/schema.sql` unchanged as test setup. Reset is explicit, not part of
the ordinary test command.

For an additional database-level boundary, a dedicated MariaDB user can be
granted privileges only on `vulcatrack_test` and placed in the local test
config. The setup user needs CREATE/DROP/ALTER privileges on that database;
tests need the DML privileges used by the suite. No MariaDB accounts are
created or changed by this harness.

## Layout

| Path | What it covers |
|---|---|
| `run.php` | Test runner. Discovers `*/*Test.php`, runs each `test()` case in isolation, promotes PHP warnings/notices to failures, tallies assertions. |
| `setup-db.php` | Explicitly builds/resets only `vulcatrack_test` from validated canonical schema DDL. `--check` is read-only. |
| `bootstrap.php` | Loads the assertion lib + the application bootstrap. |
| `lib/Assert.php` | `test()` registry and `assert_*()` helpers. |
| `lib/TestDb.php` | `TestDb::rollback()` — run DB work in a transaction that is always rolled back — plus a unique-email helper. |
| `lib/HttpClient.php` | Manages a real `php -S` process (repo root as docroot) and makes cookie-aware requests with libcurl. |
| `unit/` | Pure class logic — `Validator`, `Money` (integer centavos), `Geo` (frozen ETA), `OtgStatus` (4 locked statuses + allowed transitions / final states), `Csrf`, `Password`, `PosCart` (session cart rules), the geocoder layer, the admin Rescue empty-state partial. No DB. |
| `integration/` | `bootstrap` + autoloader, the live 9-table schema + CHECK constraints, `Auth` actor/idle-timeout logic, repositories, atomic `SaleService` checkout and `RestockService` receipts, Sales History and Reports reads, Tireman and Rescue rules, and strict DB sessions. Transaction-owning service tests clean up committed throwaway rows in `finally`. |
| `http/` | End-to-end via `php -S`: auth guards, customer/admin actor separation, CSRF enforcement, every Phase 4 page reachable under the right session, geocoding, host-relative (LAN) URLs, the admin shell, Inventory browse + mutations, the POS (cart, customer link, cash checks, checkout), the Transaction Summary, Tiremen management, the admin Rescue list / detail and its actions (accept / reassign / reject / complete, CSRF, stale requests refused, the customer's status view), Sales History and Sales Reports (admin-only, date filters, empty and error states), and no PHP warnings in any response or in the server log. Seeds throwaway rows and deletes them afterwards. |

## Conventions for new tests

- One `test('description', function () { ... })` per behaviour.
- DB tests: wrap the body in `TestDb::rollback($pdo, function () { ... })`.
- Never rely on rows surviving between tests; seed what you need.
- Sales data: seed fixed past dates (the sales tests use 2001) and query closed
  ranges, so live development sales and today's date cannot change the result.
- Keep assertions specific — assert the value, not just "not null".
