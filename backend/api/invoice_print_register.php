<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$respond = static function (int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_THROW_ON_ERROR);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    $respond(405, ['error' => 'Use POST to register an invoice print.']);
}
if (empty($_SESSION['user'])) {
    $respond(401, ['error' => 'Sign in to register invoice printing.']);
}

$csrf = (string) ($_POST['_csrf'] ?? '');
if ($csrf === '' || !hash_equals($_SESSION['_csrf'] ?? '', $csrf)) {
    $respond(419, ['error' => 'Your session token expired. Refresh the invoice and try again.']);
}

$invoiceId = filter_var($_POST['invoice_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$printToken = (string) ($_POST['print_token'] ?? '');
if (!$invoiceId || !preg_match('/^[a-f0-9]{64}$/D', $printToken)) {
    $respond(400, ['error' => 'Invalid invoice print request.']);
}

$tokenData = $_SESSION['_invoice_print_tokens'][$printToken] ?? null;
if (!is_array($tokenData)
    || (int) ($tokenData['invoice_id'] ?? 0) !== (int) $invoiceId
    || (int) ($tokenData['expires'] ?? 0) < time()) {
    $respond(403, ['error' => 'This print request is invalid or expired. Reopen the invoice and try again.']);
}

try {
    $pdo = db();
    $registeredAt = date('Y-m-d H:i:s');
    $update = $pdo->prepare(
        "UPDATE invoices SET printed_at=COALESCE(printed_at, ?)
         WHERE id=? AND invoice_number IS NOT NULL AND invoice_number <> ''
           AND EXISTS (SELECT 1 FROM invoice_items WHERE invoice_id=invoices.id)"
    );
    $update->execute([$registeredAt, (int) $invoiceId]);
    if ($update->rowCount() === 0) {
        $check = $pdo->prepare(
            "SELECT printed_at FROM invoices
             WHERE id=? AND invoice_number IS NOT NULL AND invoice_number <> ''
               AND EXISTS (SELECT 1 FROM invoice_items WHERE invoice_id=invoices.id)"
        );
        $check->execute([(int) $invoiceId]);
        if ($check->fetchColumn() === false) {
            $respond(404, ['error' => 'This invoice is not available for printing.']);
        }
    }
    $respond(200, ['ok' => true, 'invoice_id' => (int) $invoiceId]);
} catch (Throwable $error) {
    error_log('Invoice print registration failed: ' . $error->getMessage());
    $respond(500, ['error' => 'Unable to save the invoice print status.']);
}
