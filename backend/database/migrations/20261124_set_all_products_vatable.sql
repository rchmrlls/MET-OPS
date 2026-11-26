-- Set every existing product to VATable, as requested, and use VATable for
-- future products unless explicitly changed to Non-VATable.
UPDATE products
SET vat_classification = 'vatable';

ALTER TABLE products
    MODIFY COLUMN vat_classification VARCHAR(20) NOT NULL DEFAULT 'vatable';

-- Verify after migration:
-- SELECT vat_classification, COUNT(*) FROM products GROUP BY vat_classification;
-- Rollback schema default/nullability only (product classifications are not restored):
-- ALTER TABLE products MODIFY COLUMN vat_classification VARCHAR(20) NULL DEFAULT NULL;
