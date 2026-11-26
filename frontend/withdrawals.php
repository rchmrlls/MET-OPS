<?php
$page_title = 'Withdrawal Register';
require __DIR__ . '/includes/layout_start.php';
$pdo = db();
$q = trim($_GET['q'] ?? '');
$batchStart = trim((string) ($_GET['batch_start'] ?? ''));
$batchEnd = trim((string) ($_GET['batch_end'] ?? ''));
$batchStartObject = $batchStart !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $batchStart)
    ? DateTimeImmutable::createFromFormat('!Y-m-d', $batchStart)
    : false;
$batchEndObject = $batchEnd !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $batchEnd)
    ? DateTimeImmutable::createFromFormat('!Y-m-d', $batchEnd)
    : false;
$batchStartValid = $batchStartObject instanceof DateTimeImmutable && $batchStartObject->format('Y-m-d') === $batchStart;
$batchEndValid = $batchEndObject instanceof DateTimeImmutable && $batchEndObject->format('Y-m-d') === $batchEnd;
$batchRangeSelected = $batchStart !== '' || $batchEnd !== '';
$batchRangeValid = $batchStartValid && $batchEndValid && $batchStart <= $batchEnd;
$batchDateError = $batchRangeSelected && !$batchRangeValid;
$activeBatchStart = $batchRangeValid ? $batchStart : '';
$activeBatchEnd = $batchRangeValid ? $batchEnd : '';
$sql = "SELECT w.id,w.withdrawal_no,w.form_date,w.status,w.internal_note,c.client_name,COUNT(wi.id) item_count,MAX(i.status) invoice_status,MAX(i.invoice_number) invoice_number FROM withdrawals w JOIN clients c ON c.id=w.client_id LEFT JOIN withdrawal_items wi ON wi.withdrawal_id=w.id LEFT JOIN invoices i ON i.withdrawal_id=w.id WHERE (?='' OR w.withdrawal_no LIKE ? OR c.client_name LIKE ? OR w.status LIKE ? OR w.internal_note LIKE ? OR COALESCE(i.status,'') LIKE ?) AND (?='' OR w.form_date BETWEEN ? AND ?) GROUP BY w.id ORDER BY CASE WHEN ?='' THEN w.id END DESC,CASE WHEN ?<>'' THEN w.withdrawal_no END,w.id";
$s = $pdo->prepare($sql);
$like = '%' . $q . '%';
$s->execute([$q, $like, $like, $like, $like, $like, $activeBatchStart, $activeBatchStart, $activeBatchEnd, $activeBatchStart, $activeBatchStart]);
$records = $s->fetchAll();
?>
<div class="page-intro">
    <div>
        <h2>Withdrawal Register</h2>
        <p>Live records stored in MySQL.</p>
    </div>

    <div class="toolbar">
        <a href="<?= e(APP_BASE_URL) ?>/backend/exports/withdrawals.php"
            class="btn btn-light">
            Export CSV
        </a>

        <a href="withdrawal_form.php" class="btn btn-primary">
            <i class="bi bi-plus-lg" aria-hidden="true"></i>
            New Withdrawal
        </a>
    </div>
</div>
<?php if ($batchDateError): ?><div class="alert alert-danger" role="alert">Choose a valid start and end date, and make sure the end date is not earlier than the start date.</div><?php endif; ?>
<section class="panel mb-3" aria-labelledby="batchWithdrawalTitle">
    <div class="panel-header flex-wrap">
        <div><h3 id="batchWithdrawalTitle">Date-scoped batch actions</h3><p>Select a date range; only withdrawals within that range can be exported or printed.</p></div>
        <form method="get" class="toolbar" id="batchDateForm">
            <input type="hidden" name="q" value="<?= e($q) ?>">
            <label class="visually-hidden" for="batchStartDate">Start date</label>
            <input class="form-control" id="batchStartDate" name="batch_start" type="date" value="<?= e($batchStart) ?>" required>
            <label class="visually-hidden" for="batchEndDate">End date</label>
            <input class="form-control" id="batchEndDate" name="batch_end" type="date" value="<?= e($batchEnd) ?>" required>
            <button class="btn btn-light" type="submit">Show Range</button>
            <?php if ($batchRangeSelected): ?><a class="btn btn-light" href="withdrawals.php<?= $q !== '' ? '?q=' . rawurlencode($q) : '' ?>">Clear Range</a><?php endif; ?>
        </form>
    </div>
    <?php if ($batchRangeValid): ?>
        <form id="withdrawalBatchForm" class="panel-body pt-0">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="withdrawal_start_date" value="<?= e($batchStart) ?>">
            <input type="hidden" name="withdrawal_end_date" value="<?= e($batchEnd) ?>">
            <div class="d-flex flex-wrap align-items-center gap-3 justify-content-between">
                <div>
                    <strong><?= e($batchStartObject->format('F d, Y')) ?> &ndash; <?= e($batchEndObject->format('F d, Y')) ?></strong>
                    <span class="text-secondary ms-2"><?= count($records) ?> withdrawal(s) available</span>
                    <span class="text-secondary ms-2" id="batchSelectionCount" aria-live="polite">0 selected</span>
                    <span class="text-secondary ms-2">Up to 25 per batch</span>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-light btn-sm" id="selectAllBatch" <?= !$records ? 'disabled' : '' ?>>Select all</button>
                    <button type="button" class="btn btn-outline-danger btn-sm" data-batch-action="export" disabled>Export Selected PDFs</button>
                    <button type="button" class="btn btn-outline-primary btn-sm" data-batch-action="print" disabled>Print Selected Withdrawals</button>
                </div>
            </div>
            <div class="small text-secondary mt-2" id="batchStatus" role="status"></div>
        </form>
    <?php else: ?>
        <div class="panel-body pt-0 text-secondary">Choose a start and end date to enable batch selection.</div>
    <?php endif; ?>
</section>
<section class="panel">
    <div class="panel-header">
        <div>
            <h3>All Withdrawals</h3>
            <p><?= count($records) ?> record(s)</p>
        </div>
        <form class="toolbar" method="get" role="search"><label class="visually-hidden" for="withdrawalSearch">Search withdrawals</label><input class="form-control" id="withdrawalSearch" name="q" value="<?= e($q) ?>" placeholder="Search number, client, status, or note"><button class="btn btn-primary" type="submit"><i class="bi bi-search" aria-hidden="true"></i> Search</button><?php if ($q !== ''): ?><a href="withdrawals.php" class="btn btn-light">Clear search</a><?php endif; ?></form>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <?php if ($batchRangeValid): ?><th scope="col"><span class="visually-hidden">Select</span></th><?php endif; ?>
                    <th>Withdrawal No.</th>
                    <th>Client</th>
                    <th>Internal Note</th>
                    <th>Form Date</th>
                    <th>Item Lines</th>
                    <th>Status</th>
                    <th>Invoice Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                    <?php if (!$records): ?><tr>
                        <td colspan="<?= $batchRangeValid ? '9' : '8' ?>">
                            <div class="empty-state"><strong>No withdrawals found</strong>Create a withdrawal or change your search.</div>
                        </td>
                    </tr><?php endif; ?>
                <?php foreach ($records as $r): ?><tr>
                        <?php if ($batchRangeValid): ?><td><input class="form-check-input batch-withdrawal-select" type="checkbox" name="withdrawal_ids[]" value="<?= (int) $r['id'] ?>" form="withdrawalBatchForm" aria-label="Select withdrawal <?= e($r['withdrawal_no']) ?>"></td><?php endif; ?>
                        <td><a class="record-no" href="withdrawal_form.php?withdrawal_no=<?= e($r['withdrawal_no']) ?>"><?= e($r['withdrawal_no']) ?></a></td>
                        <td><?= e($r['client_name']) ?></td>
                        <td>
                            <?php if (($r['internal_note'] ?? '') !== ''): ?>
                                <details class="withdrawal-register-note">
                                    <summary>
                                        <?= e($r['internal_note']) ?>
                                    </summary>
                                </details>
                            <?php else: ?>
                                <span class="muted">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e(date('F d, Y', strtotime($r['form_date']))) ?></td>
                        <td><?= (int)$r['item_count'] ?></td>
                        <td><span class="status-pill <?= e(status_class($r['status'])) ?>"><?= e($r['status']) ?></span></td>
                        <td><?php if (empty($r['invoice_status'])): ?><span class="status-pill status-muted">Not prepared</span><?php elseif (trim((string) ($r['invoice_number'] ?? '')) === ''): ?><span class="status-pill status-warning">Not saved</span><?php elseif ($r['invoice_status'] === 'Draft'): ?><span class="status-pill status-info">Saved draft</span><?php else: ?><span class="status-pill <?= e(status_class($r['invoice_status'])) ?>"><?= e($r['invoice_status']) ?></span><?php endif; ?></td>

                        <td>
                            <div class="row-actions">
                                <a class="btn btn-light btn-sm"
                                    href="withdrawal_form.php?withdrawal_no=<?= e($r['withdrawal_no']) ?>">
                                    Open
                                </a>

                                <a class="btn btn-outline-secondary btn-sm"
                                    href="withdrawal_print.php?withdrawal_no=<?= e($r['withdrawal_no']) ?>"
                                    target="_blank">
                                    Print
                                </a>

                                <a class="btn btn-outline-danger btn-sm"
                                    href="<?= e(APP_BASE_URL) ?>/backend/exports/withdrawal_pdf.php?withdrawal_id=<?= (int) $r['id'] ?>"
                                    aria-label="Export withdrawal <?= e($r['withdrawal_no']) ?> as PDF"
                                    title="Export PDF">
                                    <i class="bi bi-file-earmark-pdf" aria-hidden="true"></i>
                                    Export PDF
                                </a>

                                <a class="btn btn-outline-secondary btn-sm"
                                    href="invoice_preparation.php?withdrawal_no=<?= e($r['withdrawal_no']) ?>">
                                    Invoice
                                </a>
                                <a class="btn btn-outline-primary btn-sm"
                                    href="sales_order_preparation.php?withdrawal_no=<?= e($r['withdrawal_no']) ?>">
                                    Sales Order
                                </a>
                            </div>
                        </td>
                    </tr><?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('withdrawalBatchForm');
    if (!form) return;
    const checkboxes = [...document.querySelectorAll('.batch-withdrawal-select')];
    const buttons = [...form.querySelectorAll('[data-batch-action]')];
    const count = document.getElementById('batchSelectionCount');
    const status = document.getElementById('batchStatus');
    const selectAll = document.getElementById('selectAllBatch');
    const refresh = () => {
        const selected = checkboxes.filter(box => box.checked).length;
        count.textContent = `${selected} selected`;
        buttons.forEach(button => button.disabled = selected === 0 || button.dataset.busy === '1');
        selectAll.textContent = selected === checkboxes.length && selected ? 'Deselect all' : 'Select all';
    };
    checkboxes.forEach(box => box.addEventListener('change', refresh));
    selectAll?.addEventListener('click', () => {
        const shouldSelect = !checkboxes.every(box => box.checked);
        checkboxes.forEach(box => box.checked = shouldSelect);
        refresh();
    });
    buttons.forEach(button => button.addEventListener('click', async () => {
        const ids = checkboxes.filter(box => box.checked).map(box => box.value);
        if (!ids.length) return;
        const action = button.dataset.batchAction;
        const printWindow = action === 'print' ? window.open('about:blank', '_blank') : null;
        if (action === 'print' && !printWindow) {
            status.textContent = 'Allow pop-ups for this site to prepare the batch print document.';
            return;
        }
        buttons.forEach(item => { item.dataset.busy = '1'; item.disabled = true; });
        status.textContent = action === 'export' ? 'Generating PDFs and ZIP archive…' : 'Preparing the combined print document…';
        const payload = new FormData(form);
        payload.set('action', action);
        try {
            const response = await fetch('<?= e(APP_BASE_URL) ?>/backend/exports/withdrawals_batch.php', {
                method: 'POST', credentials: 'same-origin', body: payload, headers: { Accept: action === 'export' ? 'application/zip' : 'text/html' }
            });
            if (!response.ok) throw new Error((await response.text()) || 'The batch could not be completed.');
            if (action === 'export') {
                const blob = await response.blob();
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = url;
                link.download = response.headers.get('Content-Disposition')?.match(/filename="?([^";]+)"?/i)?.[1] || `Withdrawals_${form.elements.withdrawal_start_date.value}_to_${form.elements.withdrawal_end_date.value}.zip`;
                document.body.appendChild(link);
                link.click();
                link.remove();
                setTimeout(() => URL.revokeObjectURL(url), 1000);
                status.textContent = `ZIP downloaded for ${ids.length} withdrawal(s) from ${form.elements.withdrawal_start_date.value} through ${form.elements.withdrawal_end_date.value}.`;
            } else {
                printWindow.document.open();
                printWindow.document.write(await response.text());
                printWindow.document.close();
                status.textContent = `Combined print document prepared for ${ids.length} withdrawal(s). Complete printing in the print dialog.`;
            }
        } catch (error) {
            if (printWindow && !printWindow.closed) printWindow.close();
            status.textContent = error.message || 'The batch could not be completed. Please try again.';
        } finally {
            buttons.forEach(item => { delete item.dataset.busy; });
            refresh();
        }
    }));
    refresh();
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
