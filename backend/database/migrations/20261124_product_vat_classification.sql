-- VATable is the explicit default for existing and new products. Managers can
-- change individual products to Non-VATable in Products & Sites.
ALTER TABLE products
    ADD COLUMN vat_classification VARCHAR(20) NOT NULL DEFAULT 'vatable' AFTER qb_list_id;

-- Verify after migration:
-- SELECT vat_classification, COUNT(*) FROM products GROUP BY vat_classification;
-- Rollback (only if the feature has not been used): ALTER TABLE products DROP COLUMN vat_classification;
