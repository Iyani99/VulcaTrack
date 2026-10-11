-- Inventory Foundation refinement: retain the stock movement at receipt time.
-- Apply once after 2026-10-12-inventory-restocks.sql. The table must have no
-- existing rows: old receipts cannot be given truthful stock snapshots later.
-- Check first: SELECT COUNT(*) FROM inventory_restocks; (must be zero).
-- This changes no existing items, stock, or other business data.

ALTER TABLE `inventory_restocks`
  ADD COLUMN `stock_before` INT NOT NULL AFTER `quantity`,
  ADD COLUMN `stock_after` INT NOT NULL AFTER `stock_before`,
  ADD COLUMN `note` VARCHAR(500) NULL AFTER `supplier_name`,
  ADD CONSTRAINT `chk_inventory_restocks_movement`
    CHECK (`stock_before` >= 0 AND `stock_after` = `stock_before` + `quantity`);
