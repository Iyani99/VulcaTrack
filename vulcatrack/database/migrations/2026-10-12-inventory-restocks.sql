-- Inventory Foundation: additive receipt history for future Product restocks.
-- Apply once to an existing VulcaTrack database after backing it up.
-- Fresh installations already include this table in database/schema.sql.
-- Existing stock remains opening stock; no receipt history is backfilled.
-- Check first: SHOW TABLES LIKE 'inventory_restocks'; (no row = not applied).
-- Do not rerun after the table exists. Application writes receipt and stock
-- inside one transaction. This migration itself creates no receipt rows.

CREATE TABLE `inventory_restocks` (
  `restock_id`    INT          NOT NULL AUTO_INCREMENT,
  `item_id`       INT          NOT NULL,
  `quantity`      INT          NOT NULL,
  `supplier_name` VARCHAR(150)     NULL,
  `admin_id`      INT          NOT NULL,
  `restocked_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`restock_id`),
  KEY `ix_inventory_restocks_item_date` (`item_id`, `restocked_at`),
  KEY `ix_inventory_restocks_admin` (`admin_id`),
  CONSTRAINT `chk_inventory_restocks_quantity` CHECK (`quantity` > 0),
  CONSTRAINT `fk_inventory_restocks_item`
    FOREIGN KEY (`item_id`) REFERENCES `items` (`item_id`)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_inventory_restocks_admin`
    FOREIGN KEY (`admin_id`) REFERENCES `admins` (`admin_id`)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
