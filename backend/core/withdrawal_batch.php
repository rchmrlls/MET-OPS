<?php

declare(strict_types=1);

require_once __DIR__ . '/withdrawal_pdf.php';

function withdrawal_batch_zip(array $files, string $destination): void
{
    $zip = new PharData($destination, 0, null, Phar::ZIP);
    foreach ($files as $file) {
        $name = basename((string) $file['filename']);
        if ($name === '' || str_contains($name, '..')) throw new RuntimeException('An export filename is invalid.');
        $zip->addFromString($name, (string) $file['content']);
    }
    unset($zip);
    if (!is_file($destination) || filesize($destination) < 22) throw new RuntimeException('The ZIP archive could not be created.');
}

function withdrawal_batch_validate_date(string $value): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) return false;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    return $date !== false && $date->format('Y-m-d') === $value
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
}

function withdrawal_batch_render(int $withdrawalId): array
{
    $previousGet = $_GET;
    $level = ob_get_level();
    $_GET = ['withdrawal_id' => (string) $withdrawalId];
    try {
        ob_start();
        require __DIR__ . '/../../frontend/withdrawal_print.php';
        $html = ob_get_clean();
        if (!is_string($html) || !isset($withdrawal) || (int) ($withdrawal['id'] ?? 0) !== $withdrawalId) {
            throw new RuntimeException('A selected withdrawal could not be prepared.');
        }
        return [$withdrawal, $html];
    } finally {
        while (ob_get_level() > $level) ob_end_clean();
        $_GET = $previousGet;
    }
}

function withdrawal_batch_generate_pdfs(array $documents): array
{
    $pdfs = [];
    foreach ($documents as $document) {
        $pdf = create_withdrawal_pdf($document['withdrawal'], $document['html']);
        $pdfs[] = [
            'filename' => (string) $document['filename'],
            'content' => (string) $pdf['content'],
        ];
    }
    return $pdfs;
}

function withdrawal_batch_combined_html(array $rendered, string $startDate, string $endDate): string
{
    if (!$rendered) throw new RuntimeException('No withdrawal documents were selected.');
    if (!preg_match('~<style\b[^>]*>(.*?)</style>~si', $rendered[0]['html'], $styleMatch)) {
        throw new RuntimeException('The withdrawal print layout could not be loaded.');
    }
    $pages = [];
    foreach ($rendered as $entry) {
        if (!preg_match('~<body\b[^>]*>(.*?)</body>~si', $entry['html'], $bodyMatch)) {
            throw new RuntimeException('A selected withdrawal has an invalid print layout.');
        }
        $body = preg_replace('~<div class="print-actions">.*?</div>\s*~si', '', $bodyMatch[1], 1);
        if (!is_string($body) || !str_contains($body, '<main class="sheet">')) throw new RuntimeException('A selected withdrawal has no printable page.');
        $pages[] = $body;
    }
    if (!withdrawal_batch_validate_date($startDate) || !withdrawal_batch_validate_date($endDate) || $startDate > $endDate) {
        throw new RuntimeException('The withdrawal batch date range is invalid.');
    }
    $startLabel = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate)->format('F d, Y');
    $endLabel = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate)->format('F d, Y');
    $dateLabel = $startDate === $endDate ? $startLabel : $startLabel . ' - ' . $endLabel;
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Withdrawals ' . e($dateLabel) . '</title><style>'
        . $styleMatch[1]
        . "\n@media print { .batch-toolbar { display:none!important; } .sheet + .sheet { break-before:page; page-break-before:always; } }\n"
        . '</style></head><body><div class="print-actions batch-toolbar"><strong>' . count($pages) . ' withdrawal(s) · ' . e($dateLabel) . '</strong><button type="button" onclick="window.print()">Print</button><button type="button" onclick="window.close()">Close</button></div>'
        . implode("\n", $pages)
        . '</body></html>';
}
