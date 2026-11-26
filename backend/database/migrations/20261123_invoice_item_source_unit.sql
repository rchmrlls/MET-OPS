-- Preserve the source withdrawal unit on invoice lines even if the linked
-- withdrawal item is later replaced or deleted.
ALTER TABLE invoice_items
    ADD COLUMN source_unit VARCHAR(10) NULL AFTER source_type;

UPDATE invoice_items ii
INNER JOIN withdrawal_items wi ON wi.id = ii.withdrawal_item_id
SET ii.source_unit = COALESCE(wi.unit, 'box')
WHERE ii.source_type = 'WITHDRAWAL';
