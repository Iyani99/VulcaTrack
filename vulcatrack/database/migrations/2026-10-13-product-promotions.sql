-- Additive, one-time Inventory Promotions migration for an existing database.
-- Check first: SHOW TABLES LIKE 'product_promotions';
--              SHOW COLUMNS FROM sale_items LIKE 'promotion_id';
-- Back up and verify the target database before applying. Never run schema.sql
-- as an upgrade. Existing Sales and Sale lines remain unchanged (NULL link).

CREATE TABLE `product_promotions` (
  `promotion_id` INT NOT NULL AUTO_INCREMENT,
  `item_id` INT NOT NULL,
  `sale_price` DECIMAL(10,2) NOT NULL,
  `regular_price_snapshot` DECIMAL(10,2) NOT NULL,
  `starts_on` DATE NOT NULL,
  `ends_on` DATE NOT NULL,
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

ALTER TABLE `sale_items`
  ADD COLUMN `promotion_id` INT NULL AFTER `item_id`,
  ADD KEY `ix_sale_items_promotion_item` (`promotion_id`, `item_id`),
  ADD CONSTRAINT `fk_sale_items_promotion_item` FOREIGN KEY (`promotion_id`, `item_id`)
    REFERENCES `product_promotions` (`promotion_id`, `item_id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
