<?php
declare(strict_types=1);
require_once __DIR__ . '/../backend/config/app.php';
require_login();

$salesOrderId = filter_input(INPUT_GET, 'sales_order_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$salesOrderId) {
    http_response_code(400);
    exit('A valid Sales Order identifier is required.');
}

try {
    $pdo = db();
    $header = $pdo->prepare(
        'SELECT so.id,so.so_number,so.po_no,so.withdrawal_id,
                c.client_name,c.tin,c.business_address,c.payment_terms,
                w.withdrawal_no,i.invoice_number
         FROM sales_orders so
         JOIN clients c ON c.id=so.customer_id
         JOIN withdrawals w ON w.id=so.withdrawal_id
         LEFT JOIN invoices i ON i.withdrawal_id=so.withdrawal_id
         WHERE so.id=? LIMIT 1'
    );
    $header->execute([$salesOrderId]);
    $order = $header->fetch();
    if (!$order) {
        http_response_code(404);
        exit('Sales Order not found.');
    }
    $lineQuery = $pdo->prepare(
        'SELECT description,ordered_quantity,u_m
         FROM sales_order_items WHERE sales_order_id=? ORDER BY line_no'
    );
    $lineQuery->execute([$salesOrderId]);
    $lines = $lineQuery->fetchAll();
} catch (PDOException $error) {
    error_log('Delivery Receipt request failed: ' . $error->getMessage());
    http_response_code(500);
    exit('Unable to load this Delivery Receipt. Please try again.');
}

if (trim((string)($order['so_number'] ?? '')) === '' || !$lines) {
    http_response_code(409);
    exit('Save the Sales Order number and at least one line before preparing its Delivery Receipt.');
}

$drNumber = trim(is_string($_GET['dr_no'] ?? null) ? $_GET['dr_no'] : '');
$drNumber = mb_substr($drNumber, 0, 100);
$drDate = trim(is_string($_GET['dr_date'] ?? null) ? $_GET['dr_date'] : '');
$dateIsValid = static function (string $value): bool {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    return $date !== false && $date->format('Y-m-d') === $value
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
};
if ($drDate !== '' && !$dateIsValid($drDate)) $drDate = '';
$formatDate = static function (string $value): string {
    if ($value === '') return '';
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date ? $date->format('m/d/Y') : '';
};

$pageSize = 21; // PrintForm rows 15 through 35.
$pages = array_chunk($lines, $pageSize);
$title = 'Delivery Receipt - Sales Order ' . (string)$order['so_number'];
$columnWidths = ['77.25pt','122.25pt','41.25pt','151.5pt','82.5pt','87.75pt'];
$gridTemplate = implode(' ', $columnWidths);
$rowHeights = [7 => 13.5, 9 => 22.5, 10 => 13.5, 11 => 24.75, 12 => 18];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> | <?= e(APP_NAME) ?></title>
    <style>
        @page { size: Letter portrait; margin: 15pt 6.12245pt 11.25pt 9pt; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; color: #000; font-family: Calibri, Arial, sans-serif; }
        body { background: #e9edf2; font-size: 11pt; }
        .actions { position: sticky; top: 0; z-index: 2; display: flex; align-items: center; justify-content: center; gap: 10px; flex-wrap: wrap; padding: 12px; background: #fff; border-bottom: 1px solid #d5dbe3; font: 14px Arial, sans-serif; }
        .actions a, .actions button, .actions input { border: 1px solid #8794a5; border-radius: 4px; background: #fff; color: #172334; padding: 7px 10px; font: inherit; }
        .actions button { cursor: pointer; }
        .actions label { display: flex; align-items: center; gap: 5px; }
        .print-page { width: 8.5in; height: 11in; margin: 18px auto; padding: 15pt 6.12245pt 11.25pt 9pt; background: #fff; box-shadow: 0 2px 12px #16283d26; page-break-after: always; break-after: page; overflow: hidden; }
        .print-page:last-of-type { page-break-after: auto; break-after: auto; }
        .sheet { width: 562.5pt; height: 587.25pt; display: grid; grid-template-columns: <?= e($gridTemplate) ?>; grid-template-rows: <?= e(implode(' ', array_map(static fn(int $row): string => number_format($rowHeights[$row] ?? 15, 3, '.', '') . 'pt', range(1, 38))) ) ?>; align-content: start; transform: scaleX(.6); transform-origin: top left; overflow: hidden; font-size: 11pt; line-height: normal; }
        .cell { min-width: 0; overflow: visible; padding: 0 0 0 2.25pt; white-space: nowrap; font-family: Calibri, Arial, sans-serif; font-weight: 400; }
        .text-right { text-align: right; padding-right: 0; }
        .text-center { text-align: center; padding-left: 0; }
        .text-wrap { white-space: normal; }
        .font-9 { font-size: 9pt; }
        .font-10 { font-size: 10pt; }
        .font-11 { font-size: 11pt; }
        .page-warning { max-width: 900px; margin: 12px auto; padding: 10px 12px; border: 1px solid #f0c36d; background: #fff8e6; color: #664d03; font: 14px Arial, sans-serif; }
        @media print {
            html, body { width: auto; height: auto; background: #fff !important; }
            .actions, .page-warning { display: none !important; }
            .print-page { width: auto; height: auto; margin: 0; padding: 0; box-shadow: none; overflow: visible; }
            .sheet, .cell { break-inside: avoid; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
    <form class="actions" method="get">
        <a href="sales_order_preparation.php?withdrawal_no=<?= rawurlencode((string)$order['withdrawal_no']) ?>">Back to Sales Order</a>
        <span><?= e($title) ?></span>
        <input type="hidden" name="sales_order_id" value="<?= (int)$salesOrderId ?>">
        <label>DR No. <input name="dr_no" maxlength="100" value="<?= e($drNumber) ?>"></label>
        <label>Date <input type="date" name="dr_date" value="<?= e($drDate) ?>"></label>
        <button type="submit">Preview</button>
        <button type="button" onclick="window.print()">Print / Save as PDF</button>
    </form>
    <?php if (count($lines) > $pageSize): ?>
        <div class="page-warning" role="status">This order has <?= count($lines) ?> lines. The reference form provides 21 item rows per page; the receipt is continued on <?= count($pages) ?> Letter pages.</div>
    <?php endif; ?>

    <?php foreach ($pages as $pageIndex => $pageLines): ?>
        <?php
        $cells = [];
        $cell = static function (int $row, int $column, string $value, string $class = '') use (&$cells): void {
            if ($value === '') return;
            $span = 1;
            $cells[$row][$column] = ['value' => $value, 'span' => $span, 'class' => $class];
        };
        $cell(8, 6, $drNumber, 'font-11');
        $cell(9, 2, (string)$order['client_name'], 'font-10 text-right text-wrap');
        $cell(9, 6, $formatDate($drDate), 'font-9 text-center');
        $cell(10, 2, (string)($order['tin'] ?? ''), 'font-10 text-right');
        $cell(10, 6, (string)($order['payment_terms'] ?? ''), 'font-11 text-right');
        $cell(11, 2, (string)($order['business_address'] ?? ''), 'font-9 text-wrap');
        $cell(11, 6, (string)($order['po_no'] ?? ''), 'font-11 text-right');
        $cell(12, 2, (string)$order['so_number'], 'font-10');
        $cell(12, 6, (string)($order['invoice_number'] ?? ''), 'font-11 text-right');
        for ($lineIndex = 0; $lineIndex < $pageSize; $lineIndex++) {
            $row = 15 + $lineIndex;
            $line = $pageLines[$lineIndex] ?? null;
            $cell($row, 1, $line ? (string)(int)$line['ordered_quantity'] : '', 'font-11 text-center');
            $cell($row, 2, $line ? (string)($line['u_m'] ?? '') : '', 'font-11 text-center');
            $cell($row, 4, $line ? (string)$line['description'] : '', 'font-11');
        }
        ?>
        <main class="print-page" aria-label="Delivery Receipt page <?= $pageIndex + 1 ?>">
            <section class="sheet">
                <?php foreach ($cells as $row => $columns): ?>
                    <?php foreach ($columns as $column => $item): ?>
                        <div class="cell <?= e($item['class']) ?>" style="grid-row:<?= (int)$row ?>;grid-column:<?= (int)$column ?>"><?= e($item['value']) ?></div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </section>
        </main>
    <?php endforeach; ?>
</body>
</html>
