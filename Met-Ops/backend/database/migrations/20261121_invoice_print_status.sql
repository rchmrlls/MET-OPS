-- Track the first time the application registers an invoice print initiation.
-- Existing invoices remain NULL and therefore display as NOT PRINTED.
ALTER TABLE invoices
    ADD COLUMN printed_at DATETIME NULL AFTER print_total_due;
