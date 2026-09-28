-- =============================================================================
-- VulcaTrack — one-off migration (Phase 7.3d-a, 2026-09-28)
-- Adds the optional Rescue link to `sales`.
--
--   sales.service_request_id  INT NULL
--     -> service_requests.request_id   (FK, ON DELETE / UPDATE RESTRICT)
--   UNIQUE KEY uq_sales_service_request — at most one sale per Rescue request
--
-- Every existing sale keeps service_request_id = NULL (an ordinary POS sale).
-- Nothing is backfilled: an old sale's link to a Rescue cannot be proven.
--
-- WHO NEEDS THIS: an EXISTING database built from the schema.sql that came
-- before Phase 7.3d-a. A FRESH install does not — database/schema.sql already
-- creates the column, key and foreign key.
--
-- HOW (run ONCE, with MySQL started, against the VulcaTrack database):
--   C:\xampp\mysql\bin\mysql -u root vulcatrack < database\migrations\2026-09-28-sales-service-request.sql
-- or phpMyAdmin: select the database, SQL tab, paste the ALTER TABLE below.
--
-- 1. PRE-CHECK (optional) — is it already applied?
--      SELECT COUNT(*) FROM information_schema.COLUMNS
--       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales'
--         AND COLUMN_NAME = 'service_request_id';
--    0 = not applied yet (run the ALTER); 1 = already applied (do not run it).
--
-- 2. APPLY — the change is ONE ALTER TABLE statement, so it is all-or-nothing:
--    if it fails, the table is left exactly as it was. Running it a second time
--    stops with
--      ERROR 1060 (42S21): Duplicate column name 'service_request_id'
--    and changes nothing — that error means the migration was already applied.
--    It never drops or recreates a table.
--
-- 3. VERIFY — SHOW CREATE TABLE sales;  should list the column, the UNIQUE key
--    uq_sales_service_request and the constraint fk_sales_service_request.
--    (MariaDB omits "ON DELETE/UPDATE RESTRICT" in SHOW CREATE TABLE because
--    RESTRICT is the default; information_schema.REFERENTIAL_CONSTRAINTS shows
--    DELETE_RULE / UPDATE_RULE = RESTRICT.)
--
-- ROLLBACK (reverses this migration; run the three statements in this order —
-- the foreign key uses the unique index, so it must be dropped first):
--      ALTER TABLE sales DROP FOREIGN KEY fk_sales_service_request;
--      ALTER TABLE sales DROP INDEX uq_sales_service_request;
--      ALTER TABLE sales DROP COLUMN service_request_id;
--   Dropping the column discards any sale-to-Rescue links recorded since, and
--   application code that reads the column (later Phase 7.3d steps) must be
--   reverted first. Sales, sale items and Rescue requests themselves are kept.
-- =============================================================================

ALTER TABLE `sales`
  ADD COLUMN `service_request_id` INT NULL AFTER `customer_id`,
  ADD UNIQUE KEY `uq_sales_service_request` (`service_request_id`),
  ADD CONSTRAINT `fk_sales_service_request`
    FOREIGN KEY (`service_request_id`) REFERENCES `service_requests` (`request_id`)
    ON DELETE RESTRICT ON UPDATE RESTRICT;
