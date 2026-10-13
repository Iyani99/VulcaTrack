-- =============================================================================
-- VulcaTrack: Sales and Inventory with On-the-Go Services
-- Phase 2 — Database Schema (v1)
-- Structural revision: Phase 7.3d-a (2026-09-28) — sales.service_request_id
--   (optional link from a sale to one Rescue request; see the sales table).
-- Structural revision: Phase 7.4b-e2 (2026-09-29) — customers.avatar_filename
--   (optional profile picture reference; see the customers table).
-- Structural revision: Phase 7.4c (2026-09-29) — service_requests.feedback_*
--   (optional customer feedback on a completed request; see service_requests).
-- Structural revision: Inventory Foundation (2026-10-12) — inventory_restocks.
-- Structural revision: Inventory Intelligence (2026-10-12) — inventory_settings.
-- Structural revision: per-Product thresholds and manual stock adjustments.
-- Structural revision: Product Promotions (2026-10-13), in-shop pricing only.
--
-- Source of truth : docs/ERD/schema.dbml               (structure)
-- Rationale       : docs/VulcaTrack-Database-Notes_1.md (field-by-field)
-- Decisions       : docs/decisions/project-decisions.md (authoritative)
--
-- Target engine   : MariaDB 10.4.32 (XAMPP) — MySQL-compatible syntax.
-- Character set    : utf8mb4 / utf8mb4_unicode_ci
-- Storage engine   : InnoDB (required for foreign-key enforcement).
--
-- This file builds the 12 application tables and nothing else. There is NO
-- payment table, NO customer transaction receipt table, NO shop_settings table, NO status-history /
-- general Activity Log table, NO location-history / live-tracking table, and no Tireman
-- login table yet. Later post-defense domains are approved but not in this batch.
--
-- Use this file for a FRESH install:  mysql -u root vulcatrack < database/schema.sql
--
-- WARNING — DESTRUCTIVE ON AN EXISTING DATABASE: the DROP TABLE statements
-- below delete every application table AND ALL OF ITS DATA (customers, admins,
-- sales, rescue requests, …) before recreating them empty. Re-run it only when
-- you intend to wipe the database. To bring an EXISTING database up to date
-- without losing data, apply the one-off files in database/migrations/ instead
-- (see database/README.md).
--
-- Order: tables are dropped children-first and created parents-first, so every
-- foreign key's target exists when it is created and no table is dropped while
-- another still references it.
-- =============================================================================

CREATE DATABASE IF NOT EXISTS `vulcatrack`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `vulcatrack`;

-- Drop in reverse dependency order so foreign keys never block the rebuild:
-- sale_items -> sales -> service_requests (sales references service_requests
-- since Phase 7.3d-a) -> vehicles / inventory_adjustments / inventory_restocks /
-- product_promotions -> items / tiremen /
-- inventory_settings -> admins -> customers.
DROP TABLE IF EXISTS `sale_items`;
DROP TABLE IF EXISTS `sales`;
DROP TABLE IF EXISTS `service_requests`;
DROP TABLE IF EXISTS `vehicles`;
DROP TABLE IF EXISTS `inventory_adjustments`;
DROP TABLE IF EXISTS `inventory_restocks`;
DROP TABLE IF EXISTS `product_promotions`;
DROP TABLE IF EXISTS `items`;
DROP TABLE IF EXISTS `tiremen`;
DROP TABLE IF EXISTS `inventory_settings`;
DROP TABLE IF EXISTS `admins`;
DROP TABLE IF EXISTS `customers`;

-- -----------------------------------------------------------------------------
-- customers
-- Public self-registration. Separate from admins — no shared user table.
-- contact_number is MANDATORY (Decision 2): direct phone contact for OTG.
-- email is unique within customers only (independent of admins).
-- avatar_filename (Phase 7.4b-e2, Decision 77): optional profile picture —
-- ONLY the server-generated file name (<customer_id>_<32 hex>.<jpg|png|webp>)
-- of a private file under storage/avatars/; never a path or URL. NULL = none.
-- -----------------------------------------------------------------------------
CREATE TABLE `customers` (
  `customer_id`    INT           NOT NULL AUTO_INCREMENT,
  `full_name`      VARCHAR(150)  NOT NULL,
  `email`          VARCHAR(190)  NOT NULL,
  `contact_number` VARCHAR(30)   NOT NULL,
  `password_hash`  VARCHAR(255)  NOT NULL,
  `avatar_filename` VARCHAR(64)  NULL DEFAULT NULL,
  `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`customer_id`),
  UNIQUE KEY `uq_customers_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- admins
-- Internally provisioned only (seeded row or protected internal page).
-- No public admin sign-up (Decision 18/40). One internal role (Decision 19).
-- email is unique within admins only (independent of customers).
-- -----------------------------------------------------------------------------
CREATE TABLE `admins` (
  `admin_id`      INT           NOT NULL AUTO_INCREMENT,
  `full_name`     VARCHAR(150)  NOT NULL,
  `email`         VARCHAR(190)  NOT NULL,
  `password_hash` VARCHAR(255)  NOT NULL,
  `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`admin_id`),
  UNIQUE KEY `uq_admins_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One management setting, seeded for a fresh installation. This is
-- configuration, not historical Product or Sales data.
CREATE TABLE `inventory_settings` (
  `setting_id`           TINYINT UNSIGNED NOT NULL,
  `slow_moving_days`     SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  `updated_by_admin_id` INT                   NULL,
  `updated_at`          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_id`),
  CONSTRAINT `chk_inventory_settings_single_row` CHECK (`setting_id` = 1),
  CONSTRAINT `chk_inventory_settings_days` CHECK (`slow_moving_days` BETWEEN 1 AND 365),
  CONSTRAINT `fk_inventory_settings_admin`
    FOREIGN KEY (`updated_by_admin_id`) REFERENCES `admins` (`admin_id`)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- tiremen  (Decisions 22-26)
-- Identity / contact / assignment ONLY. No login, no dashboard, no GPS,
-- no scheduling / ratings / payroll. name + contact_number are shown to the
-- customer once a Tireman is assigned to their request.
-- is_active = 0 -> cannot be newly assigned; still shown on past assignments.
-- -----------------------------------------------------------------------------
CREATE TABLE `tiremen` (
  `tireman_id`     INT           NOT NULL AUTO_INCREMENT,
  `name`           VARCHAR(150)  NOT NULL,
  `contact_number` VARCHAR(30)   NOT NULL,
  `is_active`      TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`tireman_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- items  (Decision 15/36)
-- ONE unified table for products AND services, distinguished by item_type.
-- stock_quantity / reorder_level are meaningful for item_type = 'product' only
-- and stay NULL for services (Decision 16 — services do not use stock).
-- category is a plain nullable grouping label (no category table in v1).
-- is_active = 0 -> hidden from POS + active inventory; still on past sale_items.
-- -----------------------------------------------------------------------------
CREATE TABLE `items` (
  `item_id`        INT            NOT NULL AUTO_INCREMENT,
  `item_name`      VARCHAR(150)   NOT NULL,
  `item_type`      VARCHAR(10)    NOT NULL,
  `category`       VARCHAR(60)        NULL,
  `price`          DECIMAL(10,2)  NOT NULL,
  `stock_quantity` INT                NULL,
  `reorder_level`  INT                NULL,
  `slow_moving_days_override` SMALLINT UNSIGNED NULL,
  `is_active`      TINYINT(1)     NOT NULL DEFAULT 1,
  `created_at`     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`item_id`),
  CONSTRAINT `chk_items_item_type` CHECK (`item_type` IN ('product','service')),
  CONSTRAINT `chk_items_slow_moving_override` CHECK (`slow_moving_days_override` IS NULL OR
    (`item_type` = 'product' AND `slow_moving_days_override` BETWEEN 1 AND 365))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin-created Product sale prices. Terms are immutable; cancellation is
-- attributed. units_consumed is the checkout concurrency authority and each
-- charged promotional unit is also linked from a frozen sale_items line.
CREATE TABLE `product_promotions` (
  `promotion_id` INT NOT NULL AUTO_INCREMENT,
  `item_id` INT NOT NULL,
  `sale_price` DECIMAL(10,2) NOT NULL,
  `regular_price_snapshot` DECIMAL(10,2) NOT NULL,
  `starts_on` DATE NOT NULL,
  `ends_on` DATE NULL,
  `unit_limit` INT NULL,
  `units_consumed` INT NOT NULL DEFAULT 0,
  `note` VARCHAR(500) NULL,
  `created_by_admin_id` INT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `cancelled_at` DATETIME NULL,
  `cancelled_by_admin_id` INT NULL,
  PRIMARY KEY (`promotion_id`),
  UNIQUE KEY `uq_product_promotions_id_item` (`promotion_id`, `item_id`),
  KEY `ix_product_promotions_item_dates` (`item_id`, `starts_on`, `ends_on`),
  KEY `ix_product_promotions_created_admin` (`created_by_admin_id`),
  KEY `ix_product_promotions_cancelled_admin` (`cancelled_by_admin_id`),
  CONSTRAINT `chk_product_promotions_prices` CHECK (`sale_price` > 0 AND `sale_price` < `regular_price_snapshot`),
  CONSTRAINT `chk_product_promotions_dates` CHECK (`starts_on` <= `ends_on`),
  CONSTRAINT `chk_product_promotions_units` CHECK (`units_consumed` >= 0 AND
    (`unit_limit` IS NULL OR (`unit_limit` > 0 AND `units_consumed` <= `unit_limit`))),
  CONSTRAINT `chk_product_promotions_cancellation` CHECK (
    (`cancelled_at` IS NULL AND `cancelled_by_admin_id` IS NULL) OR
    (`cancelled_at` IS NOT NULL AND `cancelled_by_admin_id` IS NOT NULL)),
  CONSTRAINT `fk_product_promotions_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`item_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_product_promotions_created_admin` FOREIGN KEY (`created_by_admin_id`) REFERENCES `admins` (`admin_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_product_promotions_cancelled_admin` FOREIGN KEY (`cancelled_by_admin_id`) REFERENCES `admins` (`admin_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Future receipts only. Existing items.stock_quantity is opening stock with
-- unknown receipt history; no historical rows are generated.
CREATE TABLE `inventory_restocks` (
  `restock_id`    INT          NOT NULL AUTO_INCREMENT,
  `item_id`       INT          NOT NULL,
  `quantity`      INT          NOT NULL,
  `stock_before`  INT          NOT NULL,
  `stock_after`   INT          NOT NULL,
  `supplier_name` VARCHAR(150)     NULL,
  `note`          VARCHAR(500)     NULL,
  `admin_id`      INT          NOT NULL,
  `restocked_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`restock_id`),
  KEY `ix_inventory_restocks_item_date` (`item_id`, `restocked_at`),
  KEY `ix_inventory_restocks_admin` (`admin_id`),
  CONSTRAINT `chk_inventory_restocks_quantity` CHECK (`quantity` > 0),
  CONSTRAINT `chk_inventory_restocks_movement`
    CHECK (`stock_before` >= 0 AND `stock_after` = `stock_before` + `quantity`),
  CONSTRAINT `fk_inventory_restocks_item`
    FOREIGN KEY (`item_id`) REFERENCES `items` (`item_id`)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_inventory_restocks_admin`
    FOREIGN KEY (`admin_id`) REFERENCES `admins` (`admin_id`)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Manual count corrections only. Restocks and Sales have separate histories.
CREATE TABLE `inventory_adjustments` (
  `adjustment_id` INT NOT NULL AUTO_INCREMENT,
  `item_id` INT NOT NULL,
  `direction` VARCHAR(8) NOT NULL,
  `quantity` INT NOT NULL,
  `reason` VARCHAR(20) NOT NULL,
  `note` VARCHAR(500) NULL,
  `stock_before` INT NOT NULL,
  `stock_after` INT NOT NULL,
  `admin_id` INT NOT NULL,
  `adjusted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`adjustment_id`),
  KEY `ix_inventory_adjustments_item_date` (`item_id`, `adjusted_at`),
  KEY `ix_inventory_adjustments_admin` (`admin_id`),
  CONSTRAINT `chk_inventory_adjustments_direction` CHECK (`direction` IN ('increase','decrease')),
  CONSTRAINT `chk_inventory_adjustments_quantity` CHECK (`quantity` > 0),
  CONSTRAINT `chk_inventory_adjustments_reason` CHECK (`reason` IN ('lost','damaged','count_correction','other')),
  CONSTRAINT `chk_inventory_adjustments_note` CHECK (`reason` <> 'other' OR (`note` IS NOT NULL AND CHAR_LENGTH(TRIM(`note`)) > 0)),
  CONSTRAINT `chk_inventory_adjustments_movement` CHECK (`stock_before` >= 0 AND `stock_after` >= 0 AND
    `stock_after` = `stock_before` + IF(`direction` = 'increase', `quantity`, -`quantity`)),
  CONSTRAINT `fk_inventory_adjustments_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`item_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_inventory_adjustments_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`admin_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- vehicles
-- A customer's saved vehicle(s); a customer may have multiple.
-- vehicle_type / make / model are optional details.
-- is_active = 0 -> hidden from active selection; still on past service_requests.
-- -----------------------------------------------------------------------------
CREATE TABLE `vehicles` (
  `vehicle_id`   INT          NOT NULL AUTO_INCREMENT,
  `customer_id`  INT          NOT NULL,
  `plate_number` VARCHAR(20)  NOT NULL,
  `vehicle_type` VARCHAR(40)      NULL,
  `make`         VARCHAR(60)      NULL,
  `model`        VARCHAR(60)      NULL,
  `is_active`    TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`vehicle_id`),
  KEY `ix_vehicles_customer` (`customer_id`),
  CONSTRAINT `fk_vehicles_customer`
    FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- service_requests  (On-the-Go / OTG)
-- Submitted by an authenticated customer (customer_id NOT NULL — Decisions 1/39)
-- for one of that customer's vehicles (vehicle_id NOT NULL).
-- admin_id  is NULLABLE — the last admin who changed the status or Tireman
--            assignment (Decision 67); NULL while pending.
-- tireman_id is NULLABLE — set in the same admin action that accepts the request
--            (an active Tireman, Decision 66); may be reassigned while accepted;
--            kept on rejected / completed requests as history (Decision 68).
--            NULL while pending and on requests rejected while pending.
-- latitude / longitude — captured ONCE at submission via browser geolocation;
--            stay NULL until a successful capture. No location history.
-- eta_minutes — FROZEN snapshot computed once at submission; never recomputed
--            for display (Decision 32). No route geometry is persisted.
-- status — exactly four values (Decision 10). "Tireman is on the way" is UI
--            wording for 'accepted', not a status value. Admin transitions
--            (Decision 65, enforced in the application): pending -> accepted |
--            rejected; accepted -> completed (Tireman required) | rejected;
--            rejected and completed are final.
-- No per-status timestamp columns and no status-history table in v1 (Decision 34).
-- feedback_rating / feedback_comment / feedback_submitted_at (Phase 7.4c,
--            Decision 78) — the requesting customer's optional one-time feedback
--            on the COMPLETED request: rating 1–5 (chk_service_requests_feedback_rating),
--            comment up to 500 characters (NULL when blank), time set by the
--            server. All NULL = no feedback. Not a Tireman rating: no scores,
--            averages or rankings. Submitting it never changes status,
--            tireman_id, admin_id or updated_at.
-- Shop endpoint for route/ETA comes from config/shop.php (Decision 37) — not a table.
-- Created before `sales`, which references it (sales.service_request_id).
-- -----------------------------------------------------------------------------
CREATE TABLE `service_requests` (
  `request_id`          INT            NOT NULL AUTO_INCREMENT,
  `customer_id`         INT            NOT NULL,
  `vehicle_id`          INT            NOT NULL,
  `admin_id`            INT                NULL,
  `tireman_id`          INT                NULL,
  `problem_description` TEXT           NOT NULL,
  `latitude`            DECIMAL(10,7)      NULL,
  `longitude`           DECIMAL(10,7)      NULL,
  `eta_minutes`         INT                NULL,
  `status`              VARCHAR(10)    NOT NULL,
  `requested_at`        DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `feedback_rating`     TINYINT UNSIGNED   NULL,
  `feedback_comment`    VARCHAR(500)       NULL,
  `feedback_submitted_at` DATETIME         NULL,
  PRIMARY KEY (`request_id`),
  KEY `ix_service_requests_customer` (`customer_id`),
  KEY `ix_service_requests_vehicle` (`vehicle_id`),
  KEY `ix_service_requests_admin` (`admin_id`),
  KEY `ix_service_requests_tireman` (`tireman_id`),
  CONSTRAINT `fk_service_requests_customer`
    FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_service_requests_vehicle`
    FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`vehicle_id`)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_service_requests_admin`
    FOREIGN KEY (`admin_id`) REFERENCES `admins` (`admin_id`)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_service_requests_tireman`
    FOREIGN KEY (`tireman_id`) REFERENCES `tiremen` (`tireman_id`)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_service_requests_status`
    CHECK (`status` IN ('pending','accepted','rejected','completed')),
  CONSTRAINT `chk_service_requests_feedback_rating`
    CHECK (`feedback_rating` IS NULL OR `feedback_rating` BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- sales
-- One completed transaction, recorded by an admin at the POS.
-- customer_id is NULLABLE — walk-in sales are supported (Decision 14).
-- admin_id is REQUIRED — every sale has exactly one recording admin.
-- service_request_id is NULLABLE — the Rescue (OTG) request this sale was
--   recorded for (Phase 7.3d); NULL = an ordinary POS sale not linked to a
--   Rescue. UNIQUE: at most one sale per request (zero-or-one). Sales recorded
--   before this column existed stay NULL (no backfill). ON DELETE / UPDATE
--   RESTRICT like every other foreign key here.
-- total_amount is the ONLY monetary value stored per sale (Decision 30):
-- amount tendered / change due are UI-only and never persisted.
-- sale_date = actual-sale timestamp, system-controlled, set by the app on
-- completion; no default here so it is always an explicit application value
-- (Decision 35 — no backdating in v1). created_at = DB record-creation time.
-- -----------------------------------------------------------------------------
CREATE TABLE `sales` (
  `sale_id`            INT            NOT NULL AUTO_INCREMENT,
  `customer_id`        INT                NULL,
  `service_request_id` INT                NULL,
  `admin_id`           INT            NOT NULL,
  `sale_date`          DATETIME       NOT NULL,
  `total_amount`       DECIMAL(10,2)  NOT NULL,
  `created_at`         DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`sale_id`),
  UNIQUE KEY `uq_sales_service_request` (`service_request_id`),
  KEY `ix_sales_customer` (`customer_id`),
  KEY `ix_sales_admin` (`admin_id`),
  CONSTRAINT `fk_sales_customer`
    FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_sales_admin`
    FOREIGN KEY (`admin_id`) REFERENCES `admins` (`admin_id`)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_sales_service_request`
    FOREIGN KEY (`service_request_id`) REFERENCES `service_requests` (`request_id`)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- sale_items
-- Line items for a sale — the join between sales and items. One sale may mix
-- product and service lines.
-- unit_price is FROZEN at time of sale (Decision 17) — independent of
-- items.price, so later price changes do not rewrite history.
-- subtotal = quantity * unit_price.
-- Product lines decrease items.stock_quantity (application logic); service
-- lines do not (Decision 16).
-- -----------------------------------------------------------------------------
CREATE TABLE `sale_items` (
  `sale_item_id` INT            NOT NULL AUTO_INCREMENT,
  `sale_id`      INT            NOT NULL,
  `item_id`      INT            NOT NULL,
  `promotion_id` INT                NULL,
  `quantity`     INT            NOT NULL,
  `unit_price`   DECIMAL(10,2)  NOT NULL,
  `subtotal`     DECIMAL(10,2)  NOT NULL,
  PRIMARY KEY (`sale_item_id`),
  KEY `ix_sale_items_sale` (`sale_id`),
  KEY `ix_sale_items_item` (`item_id`),
  KEY `ix_sale_items_promotion_item` (`promotion_id`, `item_id`),
  CONSTRAINT `fk_sale_items_sale`
    FOREIGN KEY (`sale_id`) REFERENCES `sales` (`sale_id`)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_sale_items_item`
    FOREIGN KEY (`item_id`) REFERENCES `items` (`item_id`)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_sale_items_promotion_item`
    FOREIGN KEY (`promotion_id`, `item_id`) REFERENCES `product_promotions` (`promotion_id`, `item_id`)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `inventory_settings` (`setting_id`, `slow_moving_days`) VALUES (1, 60);

-- =============================================================================
-- End of schema. 12 application tables, including Product Promotions.
-- =============================================================================
