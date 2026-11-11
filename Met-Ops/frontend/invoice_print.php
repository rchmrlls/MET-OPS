<?php

declare(strict_types=1);

require_once __DIR__ . '/../backend/config/app.php';
require_login();

$invoiceId = filter_input(INPUT_GET, 'invoice_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$invoiceId) {
    http_response_code(400);
    exit('A valid invoice identifier is required.');
}

try {
    $pdo = db();
    $headerQuery = $pdo->prepare(
        'SELECT i.* FROM invoices i WHERE i.id=? LIMIT 1'
    );
    $headerQuery->execute([$invoiceId]);
    $invoice = $headerQuery->fetch();
    if (!$invoice) {
        http_response_code(404);
        exit('Invoice not found.');
    }

    $lineQuery = $pdo->prepare(
        'SELECT ii.id,ii.product_name,ii.description,ii.u_m,ii.quantity,ii.price_each,ii.amount
         FROM invoice_items ii WHERE ii.invoice_id=? ORDER BY ii.id'
    );
    $lineQuery->execute([$invoiceId]);
    $lines = $lineQuery->fetchAll();
} catch (PDOException $error) {
    error_log('Invoice print request failed: ' . $error->getMessage());
    http_response_code(500);
    exit('Unable to load this invoice for printing. Please try again.');
}
if (trim((string) $invoice['invoice_number']) === '') {
    http_response_code(409);
    exit('This invoice has not been saved with an invoice number and cannot be printed.');
}

if (!$lines) {
    http_response_code(409);
    exit('This invoice has no saved line items and cannot be printed.');
}

$printLines = [];
foreach ($lines as $line) {
    $description = trim(
        (string) $line['product_name'] . ' '
        . (string) ($line['description'] ?? '')
    );
    $description = str_replace(["\r\n", "\r", "\n"], ' ', $description);
    $printLines[] = [
        'line' => $line,
        'description' => $description,
    ];
}
$pageLines = array_chunk($printLines, 14);
$financialFields = [
    'print_vat_mode', 'print_ewt_mode',
    'print_subtotal', 'print_discount_amount', 'print_regular_sales', 'print_vat_amount',
    'print_zero_rated_sales', 'print_non_vat_sales', 'print_withholding_amount', 'print_total_due',
];
$hasFinancialSnapshot = true;
foreach ($financialFields as $field) {
    if ($invoice[$field] === null) {
        $hasFinancialSnapshot = false;
        break;
    }
}
$missingCustomerDetails = $invoice['customer_name_snapshot'] === null
    || $invoice['customer_tin_snapshot'] === null
    || $invoice['customer_address_snapshot'] === null;
$formatMoney = static fn(mixed $value): string => number_format((float) $value, 2, '.', ',');
$formatDate = static function (?string $value): string {
    if (!$value) {
        return '';
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date ? $date->format('m/d/Y') : '';
};
$rowHeights = [
    1 => 1.5, 2 => 17.25, 3 => 16.5, 4 => 17.25,
    5 => 10.5, 6 => 19.5, 7 => 29.25, 8 => 9,
    9 => 15, 10 => 41.25, 11 => 10.5, 12 => 12.75,
    13 => 12.75, 14 => 12.75, 15 => 12.75, 16 => 12.75,
    17 => 12.75, 18 => 12.75, 19 => 12.75, 20 => 12.75,
    21 => 12.75, 22 => 12.75, 23 => 12.75, 24 => 12.75,
    25 => 12.75, 26 => 12.75, 27 => 68.25, 28 => 17.25,
    29 => 16.5, 30 => 28.5, 31 => 18, 32 => 16.5,
    33 => 12.75, 34 => 15, 35 => 18.75,
];
$columnWidths = [48, 28.5, 60.75, 7.5, 129, 69.75, 48, 60.75, 40.5, 82.5];
$columnTemplate = implode(' ', array_map(
    static fn(float $width): string => number_format($width * 0.72, 2, '.', '') . 'pt',
    $columnWidths
));
$worksheetWidth = number_format(array_sum($columnWidths) * 0.72, 2, '.', '') . 'pt';
$discountOptions = invoice_print_discount_options();
$discountPercent = isset($discountOptions[$invoice['print_discount_name'] ?? ''])
    ? number_format($discountOptions[$invoice['print_discount_name']] / 100, 2) . '%'
    : '0.00%';
$invoiceTitle = 'Invoice ' . (string) $invoice['invoice_number'];
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($invoiceTitle) ?> | <?= e(APP_NAME) ?></title>
    <style>
        @page { size: 162.9mm 208.9mm; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; color: #000; font-family: Calibri, Arial, Helvetica, sans-serif; }
        body { background: #e9edf2; font-size: 8.64pt; }
        .print-actions {
            position: sticky; top: 0; z-index: 2; display: flex; justify-content: center;
            align-items: center; gap: 8px; padding: 12px; background: #fff;
            border-bottom: 1px solid #d5dbe3; font: 14px Arial, Helvetica, sans-serif;
        }
        .print-actions a, .print-actions button {
            border: 1px solid #8794a5; border-radius: 4px; background: #fff; color: #172334;
            padding: 8px 14px; font: inherit; text-decoration: none; cursor: pointer;
        }
        .print-page {
            width: 162.9mm; height: 208.9mm; margin: 18px auto; padding: 43.18mm 18pt 12mm;
            background: #fff; box-shadow: 0 2px 12px #16283d26;
            page-break-after: always; break-after: page;
        }
        .print-page:last-of-type { page-break-after: auto; break-after: auto; }
        .worksheet {
            width: <?= e($worksheetWidth) ?>; margin: 0; display: block;
        }
        .worksheet-row {
            display: grid; grid-template-columns: <?= e($columnTemplate) ?>;
            min-height: 0; align-items: start;
        }
        .worksheet-cell {
            min-width: 0; overflow-wrap: anywhere; white-space: normal;
            line-height: 1.05; font-family: Calibri, Arial, Helvetica, sans-serif;
            font-size: 8.64pt; font-weight: 400;
        }
        .cell-overflow { overflow: visible; white-space: nowrap; overflow-wrap: normal; }
        .cell-clip { overflow: hidden; white-space: nowrap; overflow-wrap: normal; }
        .cell-item-description {
            overflow: hidden; white-space: nowrap; overflow-wrap: normal; text-overflow: clip;
        }
        .cell-left { padding-left: 4pt; }
        .cell-date { padding-left: 12pt; font-size: 9.36pt; }
        .cell-customer { font-size: 9.36pt; }
        .cell-large { font-size: 9.36pt; }
        .cell-quantity { padding-right: 5pt; }
        .cell-right-edge { margin-right: -5pt; }
        .align-center { text-align: center; }
        .align-right { text-align: right; white-space: nowrap; }
        .page-warning {
            max-width: 162.9mm; margin: 12px auto; padding: 10px 12px; border: 1px solid #f0c36d;
            border-radius: 4px; background: #fff8e6; color: #664d03;
            font: 14px Arial, Helvetica, sans-serif;
        }
        @media print {
            html, body { background: #fff !important; color: #000 !important; }
            .print-actions, .page-warning { display: none !important; }
            .print-page {
                width: 162.9mm; height: 208.9mm; margin: 0; padding: 43.18mm 18pt 12mm;
                background: #fff; box-shadow: none; color: #000;
            }
            .worksheet-row, .worksheet-cell { page-break-inside: avoid; }
            .print-page, .worksheet, .worksheet-row, .worksheet-cell {
                break-inside: avoid; -webkit-print-color-adjust: exact; print-color-adjust: exact;
            }
            .print-page:last-of-type { page-break-after: auto; break-after: auto; }
        }
    </style>
</head>

<body>
    <div class="print-actions">
        <a href="invoices.php">Invoice archive</a>
        <span><?= e($invoiceTitle) ?></span>
        <button type="button" id="printInvoice">Print / Save as PDF</button>
    </div>

    <?php if ($missingCustomerDetails || !$hasFinancialSnapshot): ?>
        <div class="page-warning" role="status">
            This historical invoice has fields that were not saved in MET-OPS. The original PrintForm layout is preserved; unavailable values remain blank.
        </div>
    <?php endif; ?>

    <?php foreach ($pageLines as $pageIndex => $invoiceLines): ?>
        <?php
        $cells = [];
        $setCell = static function (
            int $row,
            int $column,
            string $value,
            string $align = '',
            int $span = 1
        ) use (&$cells): void {
            $cells[$row][$column] = ['value' => $value, 'align' => $align, 'span' => $span];
        };
        $formatDateValue = $formatDate($invoice['invoice_date']);
        $setCell(2, 9, $formatDateValue, 'cell-overflow cell-date', 2);
        $setCell(4, 4, (string) ($invoice['customer_name_snapshot'] ?? ''), 'cell-overflow cell-left cell-customer', 4);
        $setCell(6, 3, (string) ($invoice['customer_tin_snapshot'] ?? ''), 'cell-overflow cell-left cell-large');
        $setCell(7, 5, (string) ($invoice['customer_address_snapshot'] ?? ''), 'cell-overflow cell-left', 5);
        for ($lineIndex = 0; $lineIndex < 14; $lineIndex++) {
            $row = 12 + $lineIndex;
            $printLine = $invoiceLines[$lineIndex] ?? null;
            if ($printLine) {
                $line = $printLine['line'];
                $setCell($row, 3, $printLine['description'], 'cell-item-description cell-left', 4);
                $setCell($row, 7, number_format((int) $line['quantity']), 'align-right cell-quantity');
                $setCell($row, 8, $formatMoney($line['price_each']), 'align-right cell-right-edge');
                $setCell($row, 10, $formatMoney($line['amount']), 'align-right cell-right-edge');
            }
        }

        if ($hasFinancialSnapshot && $pageIndex === count($pageLines) - 1) {
            $setCell(26, 4, 'Discount:', 'cell-clip');
            $setCell(26, 5, $discountPercent, 'align-right');
            if ($invoice['print_vat_mode'] === 'Regular') {
                $taxableSales = (float) $invoice['print_subtotal'] - (float) $invoice['print_non_vat_sales'];
                $setCell(28, 10, $formatMoney(max(0, $taxableSales)), 'align-right cell-right-edge');
            }
            $vatDisplay = in_array($invoice['print_vat_mode'], ['Discount', 'Zero Rated'], true)
                || (float) $invoice['print_vat_amount'] === 0.0
                ? ''
                : $formatMoney($invoice['print_vat_amount']);
            $salesValue = match ($invoice['print_vat_mode']) {
                'Regular' => $invoice['print_regular_sales'],
                'Non-VAT' => $invoice['print_discount_name'] !== null
                    ? number_format((float) $invoice['print_non_vat_sales'] / 1.12, 2, '.', '')
                    : null,
                default => null,
            };
            $salesDisplay = $salesValue === null ? '' : $formatMoney($salesValue);
            $zeroRatedDisplay = $invoice['print_vat_mode'] === 'Zero Rated'
                ? $formatMoney($invoice['print_zero_rated_sales'])
                : '';
            $nonVatDisplay = in_array($invoice['print_vat_mode'], ['Regular', 'Non-VAT'], true)
                ? $formatMoney($invoice['print_non_vat_sales'])
                : '';
            $withholdingDisplay = (float) $invoice['print_withholding_amount'] > 0
                ? $formatMoney($invoice['print_withholding_amount'])
                : '';
            $discountAmount = (float) $invoice['print_discount_amount'];
            $discountDisplay = $discountAmount > 0 ? $formatMoney($discountAmount) : '-';
            $setCell(29, 10, $vatDisplay, 'align-right cell-right-edge');
            $setCell(30, 10, $salesDisplay, 'align-right cell-right-edge');
            $setCell(31, 4, $salesDisplay, 'align-right', 2);
            $setCell(31, 10, $discountDisplay, 'align-right cell-right-edge');
            $setCell(32, 4, $vatDisplay, 'align-right', 2);
            $setCell(32, 10, $vatDisplay, 'align-right cell-right-edge');
            $setCell(34, 5, $zeroRatedDisplay, 'align-right');
            $setCell(34, 10, $withholdingDisplay, 'align-right cell-right-edge');
            $setCell(35, 4, $nonVatDisplay, 'align-center', 2);
            $setCell(35, 10, $formatMoney($invoice['print_total_due']), 'align-right cell-right-edge');
        }
        ?>
        <main class="print-page">
            <section class="worksheet" aria-label="Invoice print form">
                <?php for ($row = 1; $row <= 35; $row++): ?>
                    <?php
                    $height = ($rowHeights[$row] ?? 15) * 0.72;
                    $rowStyle = 'height:' . number_format($height, 2, '.', '') . 'pt;';
                    ?>
                    <div class="worksheet-row" style="<?= e($rowStyle) ?>">
                        <?php for ($column = 1; $column <= 10; $column++): ?>
                            <?php $cell = $cells[$row][$column] ?? null; ?>
                            <?php if ($cell): ?>
                                <div class="worksheet-cell <?= e($cell['align'] ?? '') ?>" style="grid-column: <?= $column ?> / span <?= (int) ($cell['span'] ?? 1) ?>">
                                    <?= nl2br(e($cell['value'])) ?>
                                </div>
                            <?php endif; ?>
                        <?php endfor; ?>
                    </div>
                <?php endfor; ?>
            </section>
        </main>
    <?php endforeach; ?>

    <script>
        document.getElementById('printInvoice').addEventListener('click', () => {
            try {
                window.print();
            } catch (error) {
                window.alert('The browser could not open the print dialog. Please try again or use the browser menu.');
            }
        });
    </script>
</body>
</html>
