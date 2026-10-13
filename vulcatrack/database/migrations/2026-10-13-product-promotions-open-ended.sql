-- Apply once after 2026-10-13-product-promotions.sql to allow promotions
-- without an automatic end date. Existing end dates and Sale lines are kept.
-- Check first: SHOW COLUMNS FROM product_promotions LIKE 'ends_on';
-- Expected before: Null = NO. After: Null = YES.
-- Rollback only after assigning an end date to every NULL row:
-- ALTER TABLE product_promotions MODIFY COLUMN ends_on DATE NOT NULL;

ALTER TABLE `product_promotions` MODIFY COLUMN `ends_on` DATE NULL;
