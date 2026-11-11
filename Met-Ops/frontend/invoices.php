<?php

declare(strict_types=1);

$page_title = 'Invoice Archive';
require_once __DIR__ . '/../backend/config/app.php';
require_login();

$pdo = db();
$search = trim((string) ($_GET['q'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$error = '';

if (mb_strlen($search) > 180) {
    $search = mb_substr($search, 0, 180);
}

$validDate = static function (string $value): bool {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    return $date !== false
        && $date->format('Y-m-d') === $value
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
};

if (($dateFrom !== '' && !$validDate($dateFrom)) || ($dateTo !== '' && !$validDate($dateTo))) {
    $error = 'Enter valid invoice date filters.';
} elseif ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    $error = 'The start date must be on or before the end date.';
}

$conditions = [];
$params = [];
if ($search !== '') {
    $conditions[] = '(i.invoice_number LIKE ? OR i.customer_name_snapshot LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
if ($dateFrom !== '' && $validDate($dateFrom)) {
    $conditions[] = 'i.invoice_date >= ?';
    $params[] = $dateFrom;
}
if ($dateTo !== '' && $validDate($dateTo)) {
    $conditions[] = 'i.invoice_date <= ?';
    $params[] = $dateTo;
}
$whereSql = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
$pageSize = 20;
$requestedPage = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
$count = $pdo->prepare(
    'SELECT COUNT(*) FROM invoices i ' . $whereSql
);
$count->execute($params);
$total = (int) $count->fetchColumn();
$totalPages = max(1, (int) ceil($total / $pageSize));
$page = min($requestedPage, $totalPages);
$offset = ($page - 1) * $pageSize;
$query = $pdo->prepare(
    'SELECT i.id,i.invoice_number,i.invoice_date,i.status,
            i.customer_name_snapshot AS customer_name,
            w.withdrawal_no,COALESCE(invoice_lines.item_count,0) AS item_count,
            COALESCE(i.print_total_due,invoice_lines.line_total) AS total_due
     FROM invoices i
     LEFT JOIN withdrawals w ON w.id=i.withdrawal_id
     LEFT JOIN (
         SELECT invoice_id,COUNT(*) AS item_count,SUM(amount) AS line_total
         FROM invoice_items GROUP BY invoice_id
     ) invoice_lines ON invoice_lines.invoice_id=i.id
     ' . $whereSql . '
     ORDER BY i.invoice_date DESC,i.id DESC
     LIMIT ' . $pageSize . ' OFFSET ' . $offset
);
$query->execute($params);
$invoices = $query->fetchAll();

require __DIR__ . '/includes/layout_start.php';
?>
<div class="page-intro">
    <div>
        <h2>Invoice Archive</h2>
        <p>Search saved invoices by invoice number, customer, or invoice date and open a print-ready preview.</p>
    </div>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<section class="panel p-3 p-md-4">
    <form method="get" action="invoices.php" class="row g-3 align-items-end mb-4">
        <div class="col-md-5">
            <label class="form-label" for="invoiceSearch">Invoice number or customer</label>
            <input class="form-control" id="invoiceSearch" name="q" type="search" value="<?= e($search) ?>" maxlength="180">
        </div>
        <div class="col-md-2">
            <label class="form-label" for="invoiceDateFrom">From</label>
            <input class="form-control" id="invoiceDateFrom" name="date_from" type="date" value="<?= e($dateFrom) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label" for="invoiceDateTo">To</label>
            <input class="form-control" id="invoiceDateTo" name="date_to" type="date" value="<?= e($dateTo) ?>">
        </div>
        <div class="col-md-3 d-flex gap-2">
            <button class="btn btn-primary" type="submit">Search</button>
            <a class="btn btn-light" href="invoices.php">Clear</a>
        </div>
    </form>

    <?php if ($invoices): ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Invoice Number</th>
                        <th>Date</th>
                        <th>Customer</th>
                        <th>Withdrawal</th>
                        <th>Items</th>
                        <th class="text-end">Invoice Amount (PHP)</th>
                        <th>Status</th>
                        <th><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $invoice): ?>
                        <tr>
                            <td><?= e($invoice['invoice_number'] ?: '—') ?></td>
                            <td><?= e($invoice['invoice_date']) ?></td>
                            <td><?= e($invoice['customer_name'] ?: '—') ?></td>
                            <td><?= e($invoice['withdrawal_no'] ?: '—') ?></td>
                            <td><?= (int) $invoice['item_count'] ?></td>
                            <td class="text-end"><?= $invoice['total_due'] === null ? '—' : number_format((float) $invoice['total_due'], 2) ?></td>
                            <td><span class="status-pill <?= e(status_class($invoice['status'])) ?>"><?= e($invoice['status']) ?></span></td>
                            <td class="text-end">
                                <?php if ($invoice['invoice_number'] && (int) $invoice['item_count'] > 0): ?>
                                    <a class="btn btn-sm btn-outline-primary" href="invoice_print.php?invoice_id=<?= (int) $invoice['id'] ?>">
                                        <i class="bi bi-printer" aria-hidden="true"></i> Preview / Print
                                    </a>
                                <?php else: ?>
                                    <span class="text-secondary small">Not printable</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span class="small text-secondary">Showing <?= $offset + 1 ?>–<?= min($offset + $pageSize, $total) ?> of <?= $total ?> invoices</span>
            <?php if ($totalPages > 1): ?>
                <nav aria-label="Invoice archive pages" class="d-flex gap-2">
                    <?php
                    $queryParams = ['q' => $search, 'date_from' => $dateFrom, 'date_to' => $dateTo];
                    ?>
                    <a class="btn btn-sm btn-light <?= $page <= 1 ? 'disabled' : '' ?>" href="?<?= e(http_build_query($queryParams + ['page' => max(1, $page - 1)])) ?>">Previous</a>
                    <span class="align-self-center small">Page <?= $page ?> of <?= $totalPages ?></span>
                    <a class="btn btn-sm btn-light <?= $page >= $totalPages ? 'disabled' : '' ?>" href="?<?= e(http_build_query($queryParams + ['page' => min($totalPages, $page + 1)])) ?>">Next</a>
                </nav>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="text-center text-secondary py-5">
            <i class="bi bi-receipt fs-2 d-block mb-2" aria-hidden="true"></i>
            <?= $search !== '' || $dateFrom !== '' || $dateTo !== '' ? 'No invoices match these filters.' : 'No saved invoices found.' ?>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>