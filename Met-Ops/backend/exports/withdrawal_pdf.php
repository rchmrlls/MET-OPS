<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_login();

$withdrawalId = filter_var(
    $_GET['withdrawal_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($withdrawalId === false || $withdrawalId === null) {
    http_response_code(400);
    exit('A valid withdrawal identifier is required.');
}

try {
    $_GET = ['withdrawal_id' => (string) $withdrawalId];
    ob_start();
    require __DIR__ . '/../../frontend/withdrawal_print.php';
    $html = ob_get_clean();

    if (!is_string($html) || !isset($withdrawal) || (int) ($withdrawal['id'] ?? 0) !== (int) $withdrawalId) {
        throw new RuntimeException('The selected withdrawal could not be prepared for export.');
    }

    require_once __DIR__ . '/../core/withdrawal_pdf.php';
    $pdf = create_withdrawal_pdf($withdrawal, $html);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $pdf['filename'] . '"');
    header('Content-Length: ' . strlen($pdf['content']));
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    echo $pdf['content'];
    exit;
} catch (Throwable $error) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    error_log('Withdrawal PDF export failed: ' . $error->getMessage());
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Unable to generate this withdrawal PDF right now. Please try again later.');
}
