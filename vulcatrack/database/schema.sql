-- =============================================================================
-- VulcaTrack: Sales and Inventory with On-the-Go Services
-- Phase 2 — Database Schema (v1)
-- Structural revision: Phase 7.3d-a (2026-09-28) — sales.service_request_id
--   (optional link from a sale to one Rescue request; see the sales table).
-- Structural revision: Phase 7.4b-e2 (2026-09-29) — customers.avatar_filename
--   (optional profile picture reference; see the customers table).
--
-- Source of truth : docs/ERD/schema.dbml               (structure)
-- Rationale       : docs/VulcaTrack-Database-Notes_1.md (field-by-field)
-- Decisions       : docs/decisions/project-decisions.md (authoritative)
--
-- Target engine   : MariaDB 10.4.32 (XAMPP) — MySQL-compatible syntax.
-- Character set    : utf8mb4 / utf8mb4_unicode_ci
-- Storage engine   : InnoDB (required for foreign-key enforcement).
--
-- This file builds the 8 application tables and nothing else. There is NO
-- payment table, NO receipt table, NO shop_settings table, NO status-history /
-- audit table, NO location-history / live-tracking table, and NO separate
-- Staff / Tireman login table — by explicit decision (see the decision record).
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
-- since Phase 7.3d-a) -> vehicles -> items / tiremen / admins -> customers.
DROP TABLE IF EXISTS `sale_items`;
DROP TABLE IF EXISTS `sales`;
DROP TABLE IF EXISTS `service_requests`;
DROP TABLE IF EXISTS `vehicles`;
DROP TABLE IF EXISTS `items`;
DROP TABLE IF EXISTS `tiremen`;
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
  `is_active`      TINYINT(1)     NOT NULL DEFAULT 1,
  `created_at`     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`item_id`),
  CONSTRAINT `chk_items_item_type` CHECK (`item_type` IN ('product','service'))
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
    CHECK (`status` IN ('pending','accepted','rejected','completed'))
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
  `quantity`     INT            NOT NULL,
  `unit_price`   DECIMAL(10,2)  NOT NULL,
  `subtotal`     DECIMAL(10,2)  NOT NULL,
  PRIMARY KEY (`sale_item_id`),
  KEY `ix_sale_items_sale` (`sale_id`),
  KEY `ix_sale_items_item` (`item_id`),
  CONSTRAINT `fk_sale_items_sale`
    FOREIGN KEY (`sale_id`) REFERENCES `sales` (`sale_id`)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_sale_items_item`
    FOREIGN KEY (`item_id`) REFERENCES `items` (`item_id`)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- End of schema. 8 application tables:
--   customers, admins, tiremen, items, vehicles, sales, sale_items,
--   service_requests
-- =============================================================================
