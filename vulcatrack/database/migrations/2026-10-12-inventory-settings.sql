-- Inventory Intelligence: one persisted, Admin-editable slow-moving threshold.
-- Apply once after the Inventory Foundation migrations. This creates only a
-- configuration row; it does not alter items, Sales, or Restock history.
-- Check first: SHOW TABLES LIKE 'inventory_settings'; (no row = not applied).
-- Fresh installations already include this table in database/schema.sql.

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

INSERT INTO `inventory_settings` (`setting_id`, `slow_moving_days`) VALUES (1, 60);
