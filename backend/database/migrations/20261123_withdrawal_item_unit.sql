-- Add per-line withdrawal units. Existing and future rows use Box by default.
ALTER TABLE withdrawal_items
    ADD COLUMN unit VARCHAR(10) NOT NULL DEFAULT 'box' AFTER quantity;
