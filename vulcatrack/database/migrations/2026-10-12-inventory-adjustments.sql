-- Future manual stock corrections only. Existing stock is the opening state;
-- no historical adjustment rows are fabricated.
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
