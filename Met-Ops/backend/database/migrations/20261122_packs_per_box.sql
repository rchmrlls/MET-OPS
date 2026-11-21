-- Narrow replacement for the quantity conversion configuration migration.
-- Use where the former quantity-conversion schema was already applied.
DROP TABLE IF EXISTS site_product_conversions;

ALTER TABLE invoice_items
    DROP COLUMN conversion_source_snapshot,
    DROP COLUMN withdrawal_uom_snapshot,
    DROP COLUMN conversion_factor_snapshot,
    DROP COLUMN withdrawal_quantity_snapshot;

ALTER TABLE products
    DROP COLUMN conversion_factor,
    DROP COLUMN withdrawal_uom,
    ADD COLUMN packs_per_box INT UNSIGNED NULL AFTER kg_per_box;
