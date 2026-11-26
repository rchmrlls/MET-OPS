-- Additive invoice-print fields. Existing records remain unchanged and retain
-- NULL snapshots so historical printouts can display missing fields as blank.
ALTER TABLE clients
    ADD COLUMN tin VARCHAR(40) NULL AFTER qb_list_id,
    ADD COLUMN business_address TEXT NULL AFTER tin,
    ADD COLUMN payment_terms VARCHAR(100) NULL AFTER business_address;

ALTER TABLE invoices
    ADD COLUMN customer_name_snapshot VARCHAR(180) NULL AFTER customer_id,
    ADD COLUMN customer_tin_snapshot VARCHAR(40) NULL AFTER customer_name_snapshot,
    ADD COLUMN customer_address_snapshot TEXT NULL AFTER customer_tin_snapshot,
    ADD COLUMN customer_terms_snapshot VARCHAR(100) NULL AFTER customer_address_snapshot,
    ADD COLUMN print_vat_mode VARCHAR(20) NULL AFTER memo,
    ADD COLUMN print_discount_name VARCHAR(80) NULL AFTER print_vat_mode,
    ADD COLUMN print_ewt_mode VARCHAR(20) NULL AFTER print_discount_name,
    ADD COLUMN print_subtotal DECIMAL(14,2) NULL AFTER print_ewt_mode,
    ADD COLUMN print_discount_amount DECIMAL(14,2) NULL AFTER print_subtotal,
    ADD COLUMN print_regular_sales DECIMAL(14,2) NULL AFTER print_discount_amount,
    ADD COLUMN print_vat_amount DECIMAL(14,2) NULL AFTER print_regular_sales,
    ADD COLUMN print_zero_rated_sales DECIMAL(14,2) NULL AFTER print_vat_amount,
    ADD COLUMN print_non_vat_sales DECIMAL(14,2) NULL AFTER print_zero_rated_sales,
    ADD COLUMN print_withholding_amount DECIMAL(14,2) NULL AFTER print_non_vat_sales,
    ADD COLUMN print_total_due DECIMAL(14,2) NULL AFTER print_withholding_amount;

ALTER TABLE invoice_items
    ADD COLUMN print_type VARCHAR(20) NULL AFTER tax_code;
