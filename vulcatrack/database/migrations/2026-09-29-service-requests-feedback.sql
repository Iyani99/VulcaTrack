-- =============================================================================
-- VulcaTrack — one-off migration (Phase 7.4c, 2026-09-29, Decision 78)
-- Adds optional customer feedback on a COMPLETED Rescue request to
-- `service_requests`:
--
--   feedback_rating        TINYINT UNSIGNED NULL  — 1..5
--   feedback_comment       VARCHAR(500)     NULL  — optional; NULL when blank
--   feedback_submitted_at  DATETIME         NULL  — set by the server on submit
--   CHECK chk_service_requests_feedback_rating:
--     feedback_rating IS NULL OR feedback_rating BETWEEN 1 AND 5
--
-- Every existing request keeps all three columns NULL (no feedback). Nothing is
-- backfilled. This is feedback on the request, not a Tireman rating system.
--
-- WHO NEEDS THIS: an EXISTING database built from the schema.sql that came
-- before Phase 7.4c. A FRESH install does not — database/schema.sql already
-- creates the columns and the CHECK.
--
-- HOW (run ONCE, with MySQL started, against the VulcaTrack database):
--   C:\xampp\mysql\bin\mysql -u root vulcatrack < database\migrations\2026-09-29-service-requests-feedback.sql
-- or phpMyAdmin: select the database, SQL tab, paste the ALTER TABLE below.
--
-- 1. PRE-CHECK (optional) — is it already applied?
--      SELECT COUNT(*) FROM information_schema.COLUMNS
--       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_requests'
--         AND COLUMN_NAME = 'feedback_rating';
--    0 = not applied yet (run the ALTER); 1 = already applied (do not run it).
--
-- 2. APPLY — ONE ALTER TABLE statement, so it is all-or-nothing. Running it a
--    second time stops with
--      ERROR 1060 (42S21): Duplicate column name 'feedback_rating'
--    and changes nothing — that error means the migration was already applied.
--    It never drops or recreates a table.
--
-- 3. VERIFY — SHOW CREATE TABLE service_requests;  should list the three
--    columns after updated_at and the constraint
--    chk_service_requests_feedback_rating.
--
-- ROLLBACK (the CHECK names feedback_rating, so drop it first):
--      ALTER TABLE service_requests DROP CONSTRAINT chk_service_requests_feedback_rating;
--      ALTER TABLE service_requests
--        DROP COLUMN feedback_submitted_at,
--        DROP COLUMN feedback_comment,
--        DROP COLUMN feedback_rating;
--   This deletes all submitted feedback; application code that reads the
--   columns (Phase 7.4c) must be reverted first.
-- =============================================================================

ALTER TABLE `service_requests`
  ADD COLUMN `feedback_rating` TINYINT UNSIGNED NULL AFTER `updated_at`,
  ADD COLUMN `feedback_comment` VARCHAR(500) NULL AFTER `feedback_rating`,
  ADD COLUMN `feedback_submitted_at` DATETIME NULL AFTER `feedback_comment`,
  ADD CONSTRAINT `chk_service_requests_feedback_rating`
    CHECK (`feedback_rating` IS NULL OR `feedback_rating` BETWEEN 1 AND 5);
