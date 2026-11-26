<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/withdrawal_batch.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Use POST to process a withdrawal batch.');
}
verify_csrf();

$action = (string) ($_POST['action'] ?? '');
$startDate = is_string($_POST['withdrawal_start_date'] ?? null) ? trim($_POST['withdrawal_start_date']) : '';
$endDate = is_string($_POST['withdrawal_end_date'] ?? null) ? trim($_POST['withdrawal_end_date']) : '';
$rawIds = $_POST['withdrawal_ids'] ?? null;
if (!in_array($action, ['export', 'print'], true)) {
    http_response_code(400);
    exit('Select a valid batch action.');
}
if (!withdrawal_batch_validate_date($startDate) || !withdrawal_batch_validate_date($endDate) || $startDate > $endDate) {
    http_response_code(400);
    exit('Choose a valid date range with an end date on or after the start date.');
}
if (!is_array($rawIds) || !$rawIds) {
    http_response_code(400);
    exit('Select at least one withdrawal.');
}
if (count($rawIds) > 250) {
    http_response_code(400);
    exit('The withdrawal selection is too large. Select no more than 25 unique withdrawals.');
}

$ids = [];
foreach ($rawIds as $rawId) {
    if (!is_scalar($rawId)) {
        http_response_code(400);
        exit('The withdrawal selection is invalid.');
    }
    $id = filter_var((string) $rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        http_response_code(400);
        exit('The withdrawal selection is invalid.');
    }
    $ids[(int) $id] = (int) $id;
}
$ids = array_values($ids);
if (!$ids) {
    http_response_code(400);
    exit('Select at least one withdrawal.');
}
if (count($ids) > 25) {
    http_response_code(400);
    exit('Select no more than 25 unique withdrawals in one batch.');
}
if ($action === 'export') set_time_limit(min(300, 30 + (15 * count($ids))));

$pdo = db();
$temporaryDirectory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR . 'metops-withdrawal-batch-' . bin2hex(random_bytes(12));
$rendered = [];
$responseStatus = 200;
$responseType = 'text/plain; charset=utf-8';
$responseHeaders = [];
$responseBody = '';
try {
    $pdo->beginTransaction();
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $query = $pdo->prepare(
        "SELECT w.id,w.withdrawal_no,w.form_date,w.client_id,c.client_name
         FROM withdrawals w JOIN clients c ON c.id=w.client_id
         WHERE w.form_date BETWEEN ? AND ? AND w.id IN ({$placeholders})
         ORDER BY w.form_date,w.withdrawal_no,w.id FOR UPDATE"
    );
    $query->execute(array_merge([$startDate, $endDate], $ids));
    $selected = $query->fetchAll(PDO::FETCH_ASSOC);
    if (count($selected) !== count($ids)) {
        throw new RuntimeException('Every selected withdrawal must exist and match the chosen date. Refresh the list and try again.');
    }
    foreach ($selected as $record) {
        [$withdrawal, $html] = withdrawal_batch_render((int) $record['id']);
        $customerName = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($withdrawal['client_name'] ?? ''));
        $customerName = trim((string) $customerName, '_-') ?: 'Customer';
        $reference = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($withdrawal['withdrawal_no'] ?? ''));
        $reference = trim((string) $reference, '_-') ?: ('ID' . (int) $withdrawal['id']);
        $filename = $customerName . '_' . $reference . '.pdf';
        $rendered[] = ['id' => (int) $withdrawal['id'], 'withdrawal' => $withdrawal, 'html' => $html, 'filename' => $filename];
    }
    $pdo->commit();

    if (!mkdir($temporaryDirectory, 0700) && !is_dir($temporaryDirectory)) {
        throw new RuntimeException('Temporary batch storage could not be created.');
    }

    if ($action === 'print') {
        $html = withdrawal_batch_combined_html($rendered, $startDate, $endDate);
        $html = str_replace('</body>', '<script>window.addEventListener("load",()=>setTimeout(()=>window.print(),250));</script></body>', $html);
        $responseType = 'text/html; charset=utf-8';
        $responseHeaders[] = 'Cache-Control: private, no-store, max-age=0';
        $responseBody = $html;
    } else {
        $pdfFiles = withdrawal_batch_generate_pdfs($rendered);
        $zipName = 'Withdrawals_' . $startDate . '_to_' . $endDate . '.zip';
        $zipPath = $temporaryDirectory . DIRECTORY_SEPARATOR . $zipName;
        withdrawal_batch_zip($pdfFiles, $zipPath);
        $zipBytes = file_get_contents($zipPath);
        if (!is_string($zipBytes) || strlen($zipBytes) < 22) throw new RuntimeException('The completed ZIP archive could not be read.');
        $responseType = 'application/zip';
        $responseHeaders[] = 'Content-Disposition: attachment; filename="' . $zipName . '"';
        $responseHeaders[] = 'Content-Length: ' . strlen($zipBytes);
        $responseHeaders[] = 'Cache-Control: private, no-store, max-age=0';
        $responseHeaders[] = 'Pragma: no-cache';
        $responseBody = $zipBytes;
    }
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Withdrawal batch failed: ' . $error->getMessage());
    $invalidSelection = $error instanceof RuntimeException && str_contains($error->getMessage(), 'Every selected withdrawal');
    $responseStatus = $invalidSelection ? 400 : 503;
    $responseBody = $invalidSelection ? $error->getMessage() : 'The withdrawal batch could not be completed. Please try again.';
} finally {
    if (is_dir($temporaryDirectory)) withdrawal_pdf_remove_tree($temporaryDirectory);
}
http_response_code($responseStatus);
header('Content-Type: ' . $responseType);
foreach ($responseHeaders as $header) header($header);
echo $responseBody;
exit;
