-- Optional Product-specific Sales inactivity threshold. Apply once after
-- inventory_settings; NULL continues to use the shop default. No backfill.
ALTER TABLE `items`
  ADD COLUMN `slow_moving_days_override` SMALLINT UNSIGNED NULL AFTER `reorder_level`,
  ADD CONSTRAINT `chk_items_slow_moving_override`
    CHECK (`slow_moving_days_override` IS NULL OR
           (`item_type` = 'product' AND `slow_moving_days_override` BETWEEN 1 AND 365));
