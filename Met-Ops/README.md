# Withdrawal & QuickBooks Invoicing System — Phase 2 (Separated Frontend/Backend)

## Folder structure

```text
withdrawal-qb/
├── index.php
├── frontend/
│   ├── assets/              # CSS and JavaScript
│   ├── includes/            # Shared visual layout only
│   ├── login.php
│   ├── dashboard.php
│   ├── withdrawal_form.php
│   ├── withdrawal_print.php
│   ├── withdrawals.php
│   ├── invoice_preparation.php
│   ├── products_sites.php
│   ├── settings.php
│   └── quickbooks_status.php
└── backend/
    ├── config/app.php       # Application bootstrap/configuration
    ├── core/                # Database, auth and shared business helpers
    ├── controllers/         # POST actions (save withdrawal/invoice, logout)
    ├── api/                 # JSON endpoints
    ├── exports/              # CSV export endpoint
    └── database/schema.sql  # MySQL schema
```

## Install in XAMPP

1. Extract this folder as `C:\xampp\htdocs\withdrawal-qb`.
2. Start Apache and MySQL.
3. Import `backend/database/schema.sql` in phpMyAdmin. It creates `metfood_qb` and example master records. Replace example clients/products/sites with the real company data before production.
4. Confirm database credentials in `backend/core/database.php` or set `WQ_DB_HOST`, `WQ_DB_NAME`, `WQ_DB_USER`, and `WQ_DB_PASS`.
5. If you install under a different folder, set `WQ_BASE_URL` to that URL prefix (for example `/my-folder`). Default is `/withdrawal-qb`.
6. Open `http://localhost/withdrawal-qb/frontend/setup.php` and create the first administrator account. Delete `frontend/setup.php` after initial setup.
7. Sign in at `http://localhost/withdrawal-qb/frontend/login.php`.

### Upgrade an existing database

Back up `metfood_qb` first. For an existing database, apply `20261015_invoice_number.sql` if it has not already been applied, then follow `backend/database/migrations/20261015_product_site_permissions.sql` in phpMyAdmin with `metfood_qb` selected. Apply `20261019_whole_number_quantities.sql` after reviewing its preflight behavior. It stops without changing quantity data if fractional historical quantities exist; reconcile those rows against authoritative records before rerunning. Apply `20261019_invoice_memo.sql` once to add the nullable invoice memo field, `20261019_sales_order_preparation.sql` once to add separate local Sales Order draft tables, and `20261110_invoice_printing.sql` once to add invoice-print fields. The printing migration is additive: it leaves all existing invoice data untouched and leaves new snapshot columns NULL for historical invoices. Do not import `schema.sql` over an existing database. Existing installations still using `withdrawal_qb` can set `WQ_DB_NAME=withdrawal_qb` until their data is renamed or migrated to `metfood_qb`.

The product/site migration is intentionally staged:

1. Run the `ALTER`/`UPDATE` statements through creation of `employee_permissions`. Existing `item_code` and `description` are copied to the QB mapping fields; `product_name` is copied to the initial HT description because it is the existing withdrawal display text. The legacy columns and all product/site/transaction IDs remain intact.
2. Review the duplicate-code queries and the incomplete-mapping report. Resolve QB conflicts against the real item list. Supply each HT Code from the authoritative HT master list; the migration never manufactures one. Products without an HT Code cannot be selected for new withdrawals. Populate descriptions/mappings and set products active only when the required fields are confirmed.
3. Deploy the updated PHP files. Admins can complete legacy mappings in Products & Sites. Admins can grant Employee access in Settings using **Manage Products & Sites**.
4. After confirming the running code no longer reads `products.default_site_id`, inspect `SHOW CREATE TABLE products`, then manually run the commented FK and column drop statements at the end of the migration. Invoice line `site_id` is preserved. Only after reviewing/fixing duplicates, add the two commented unique indexes. Existing DB collation is case-insensitive in the supplied schema.

The migration file includes the review queries and commented final DDL. Keep a copy of the pre-migration backup until the new withdrawal, invoice and employee permission paths are verified in the local database.

## Phase 2 scope

- Local MySQL records for withdrawals and invoice preparation.
- Employee login, role checks, CSRF protection and activity log.
- Withdrawal create/edit/search/register and print view.
- Invoice draft separated from the source withdrawal; supports invoice-only additional lines.
- Product/site/client setup and CSV export.
- QuickBooks status view and queue schema.

## Important scope boundary

QuickBooks Web Connector connectivity is a **required Phase 2 acceptance item but is not yet implemented or verified in this package**. The remaining integration work must verify Web Connector authentication, authorization to the correct QuickBooks company file, qbXML request/response communication, test transactions, successful and rejected responses, duplicate/uncertain-result handling, and documented customer/item mappings in the real QuickBooks Enterprise 24.0 environment. Do not enable transaction posting or claim success until these tests pass.

Sales Order Preparation is a separate local draft workflow. It uses the additive `20261019_sales_order_preparation.sql` migration and does not modify invoice records or submit anything to QuickBooks. CLASS, STATUS, FULFILLED QUANTITY, and TAX are retained as local preparation values pending verified company-file mapping.

Invoice drafts retain local site, lot, item code, product identity/description, unit, price, amount, expiration, and local tax choice. These values are not proof of QuickBooks mappings. In particular, SITE, LOT NUMBER, ITEM CODE, U/M, EXPIRATION DATE, and the local `Non`/`Tax`/`Exempt` values need company-file field/configuration decisions and qbXML verification before posting. Customer and product `qb_list_id` values must also be populated from verified QuickBooks records. Posting remains disabled until a Web Connector service and explicit supported mappings are implemented.

## Invoice print and reprint

1. Review `backend/database/migrations/20261110_invoice_printing.sql` and take a verified backup before applying it to an existing database. New installations receive these fields from `backend/database/schema.sql`. Check the target database's actual schema before deployment; never apply this migration blindly or to production as part of print-layout work.
2. As an administrator, open **Clients** and maintain the customer's TIN, business address, and payment terms. New invoice drafts snapshot these values when the draft is created. Re-saving a draft for the same customer preserves its existing snapshot; explicitly changing the customer records the newly selected customer's details. Historical records with NULL snapshots remain blank and are not backfilled from current client data.
3. In **Invoice Preparation**, select a supported no-discount VAT treatment, withholding treatment, and each line's print type. Workbook discount options and the **Discount** VAT treatment are disabled: the source workbook produces formula errors or conflicting totals for those cases. Do not enable them until the business confirms the intended calculation. Save the draft to store its print values and financial-summary snapshot.
4. Open **Invoice Archive** to search by invoice number/customer and filter by invoice date. **Preview / Print** opens the saved invoice. The browser print dialog can print directly or save the document as PDF.

The workbook's actual print button is on **Input Form**. Its VBA checks that invoice number cell `B5` is nonblank, then calls `PrintForm.PrintOut`; it does not print `Input Form`. The referenced workbook's `PrintForm` is Letter portrait at 72% scale, with 18 pt left/right, 122.4 pt top, and 54 pt bottom margins, no explicit print area, no print titles or headers/footers, and no manual page breaks. The final output is a sparse, one-page form. For comparison, its PDF was exported from Excel with macros disabled and the source workbook opened read-only; the VBA procedure was inspected but not run. The invoice number is only a print precondition and is not included in the printed sheet.

The MET-OPS print page uses portrait custom stock sized **162.9 mm × 208.9 mm**. It uses 18 pt (6.35 mm) left/right CSS margins, 43.18 mm at the top, and 12 mm at the bottom. The top position and column/row measurements follow the actual Excel `PrintForm`: Calibri font sizes and column widths use the workbook's 72% scale, giving the A:J grid a fixed 414.18 pt (146.11 mm) width rather than stretching or squeezing it to fill the browser's printable box. The paper has additional unused space to the right of that grid. Actual Epson driver printable bounds and feed alignment are unverified. MET-OPS retains the workbook's 14-item-row grid and saved invoice line amounts rather than current product prices. The workbook links the printed item description, quantity, unit price, and amount from `Input Form!A`, `F`, `G`, and `I`; its separate U/M field in `H` is not printed. MET-OPS therefore does not append the saved U/M field to the printed description. Any unit text already present in the saved description remains part of that description.

In the supplied sample's actual Excel PDF, the discount cell's text is visibly clipped to **“D”** beside **0.00%**, although the cell formula returns “Discount:”. MET-OPS preserves the printed appearance rather than widening that label.

Product descriptions print on one line in fixed-height rows, matching the PrintForm cells' `WrapText = False` setting. The workbook's first item row is 17.25 pt while later item rows are 13 pt; MET-OPS uses the same 13 pt source row height for every item to meet the consistent-row requirement. Text that exceeds the description column is clipped at the column boundary; it does not wrap, overlap the quantity/price/amount columns, or move the summary. Every page retains the same 14-row item grid, with invoices over 14 items continued on another custom-size page. The workbook sample has four populated items and prints one page; it does not exercise MET-OPS multi-page pagination. The browser output uses the workbook's 72% column scale rather than compressing the grid to fill the page, but actual driver scaling and printable bounds remain unverified without a physical Epson test. Historical invoices retain NULL for print-only fields that were not stored before this feature; those fields print blank and are not substituted from current client data. Preview/print requires a saved invoice number and at least one saved line item.

### Epson LX-310 Windows setup

The CSS page size does **not** configure the physical printer or create a Windows printer form. Configure both Windows and the browser before printing:

1. Open **Settings → Bluetooth & devices → Printers & scanners** (Windows 10: **Settings → Devices → Printers & scanners**) and select **Epson LX-310**.
2. Open **Printing preferences** or **Printer properties**. In the Epson driver, open its paper/page setup and choose **User Defined**, **Custom**, or the equivalent option.
3. Create and save a paper form with **Width 162.9 mm** and **Height 208.9 mm**. If the Epson driver does not offer a custom form, open **Printer properties → Advanced → Print Server Properties → Forms**, create a form with those exact dimensions, save it, and select it in the Epson driver. Administrator permission may be required.
4. Open **Print / Save as PDF** in the invoice preview. Select the Epson LX-310 and the saved custom paper size. Use portrait orientation, **100% / Actual size** (no fit-to-page scaling), and turn off browser **Headers and footers**. Leave the app's custom page size in effect; do not substitute Letter or A4.
5. Before business use, print a one-page test on the actual stock. Check the first-line position, left/right alignment, bottom margin, page length, and perforation/tear-off position. Adjust printer-driver feed/alignment settings if needed; do not compensate by changing invoice amounts or scaling the page.

The browser layout leaves 18 pt left/right page margins plus the unused width after the fixed Excel-scaled grid. Whether the Epson LX-310 driver can position this layout as specified depends on the driver, feed path, and paper handling. Cut sheets and continuous/fanfold stock use different feed paths and may expose different top/bottom and side limits. For cut sheets, select the appropriate sheet/manual feed source and confirm that the custom size is accepted. For continuous/fanfold stock, select tractor feed and verify page length, perforation handling, and tear-off position. Do not assume the same feed settings or margins work for both. The current layout has not been physically tested on an Epson LX-310; verify driver acceptance and alignment on the actual printer and stock.

Browser PDF output uses the browser's built-in print-to-PDF destination; this PHP application does not add a server-side PDF dependency. The exact millimeter dimensions convert to approximately **461.76 × 592.16 points** (208.9 mm is about 592.16 pt, not 592.63 pt). Chromium's generated MediaBox may differ slightly due to unit rounding. Verify the browser's print preview and select **Save as PDF** when a downloadable file is needed.
