<?php

declare(strict_types=1);

function withdrawal_pdf_browser(): ?string
{
    $configured = trim((string) (getenv('WQ_PDF_BROWSER') ?: ''));
    $candidates = array_filter([
        $configured,
        'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
        'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
    ]);

    foreach ($candidates as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function withdrawal_pdf_remove_tree(string $directory): void
{
    if (!is_dir($directory) || is_link($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            @rmdir($entry->getPathname());
        } else {
            @unlink($entry->getPathname());
        }
    }

    @rmdir($directory);
}

function withdrawal_pdf_file_url(string $path): string
{
    $normalized = str_replace('\\', '/', $path);
    $encoded = str_replace(
        ['%2F', '%3A'],
        ['/', ':'],
        rawurlencode($normalized)
    );

    return 'file:///' . ltrim($encoded, '/');
}

function create_withdrawal_pdf(array $withdrawal, string $html): array
{
    $browser = withdrawal_pdf_browser();
    if ($browser === null) {
        throw new RuntimeException('PDF browser is not configured on the server.');
    }
    if (!function_exists('proc_open')) {
        throw new RuntimeException('PDF process execution is not available.');
    }

    $logoPath = realpath(__DIR__ . '/../../frontend/assets/images/company-logo.png');
    if ($logoPath === false || !is_readable($logoPath)) {
        throw new RuntimeException('Withdrawal print logo is unavailable.');
    }
    $logoBytes = file_get_contents($logoPath);
    if ($logoBytes === false) {
        throw new RuntimeException('Withdrawal print logo could not be read.');
    }
    $logoTag = 'src="' . e(APP_BASE_URL . '/frontend/assets/images/company-logo.png') . '"';
    $html = str_replace(
        $logoTag,
        'src="data:image/png;base64,' . base64_encode($logoBytes) . '"',
        $html
    );

    $temporaryRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
    $temporaryDirectory = $temporaryRoot . DIRECTORY_SEPARATOR . 'metops-withdrawal-pdf-' . bin2hex(random_bytes(12));
    if (!mkdir($temporaryDirectory, 0700) && !is_dir($temporaryDirectory)) {
        throw new RuntimeException('PDF temporary storage could not be created.');
    }

    $htmlPath = $temporaryDirectory . DIRECTORY_SEPARATOR . 'withdrawal.html';
    $pdfPath = $temporaryDirectory . DIRECTORY_SEPARATOR . 'withdrawal.pdf';
    $profilePath = $temporaryDirectory . DIRECTORY_SEPARATOR . 'browser-profile';
    $browserLogPath = $temporaryDirectory . DIRECTORY_SEPARATOR . 'browser-stderr.log';
    $pdfBytes = false;

    try {
        if (file_put_contents($htmlPath, $html, LOCK_EX) === false) {
            throw new RuntimeException('PDF document could not be prepared.');
        }

        $command = [
            $browser,
            '--headless=new',
            '--disable-gpu',
            '--disable-extensions',
            '--disable-default-apps',
            '--disable-sync',
            '--disable-component-update',
            '--disable-breakpad',
            '--disable-background-networking',
            '--no-first-run',
            '--no-default-browser-check',
            '--no-pdf-header-footer',
            '--user-data-dir=' . $profilePath,
            '--print-to-pdf=' . $pdfPath,
            withdrawal_pdf_file_url($htmlPath),
        ];
        $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $descriptors = [
            0 => ['file', $nullDevice, 'r'],
            1 => ['file', $nullDevice, 'w'],
            2 => ['file', $browserLogPath, 'a'],
        ];
        $process = proc_open($command, $descriptors, $pipes, $temporaryDirectory, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('PDF browser could not be started.');
        }

        $deadline = microtime(true) + 30;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);

        if (($status['running'] ?? false) === true) {
            proc_terminate($process);
            proc_close($process);
            throw new RuntimeException('PDF generation timed out.');
        }

        proc_close($process);
        if (!is_file($pdfPath) || filesize($pdfPath) < 8) {
            $browserLog = is_file($browserLogPath) ? file_get_contents($browserLogPath) : false;
            if (is_string($browserLog) && $browserLog !== '') {
                error_log('Withdrawal PDF browser output: ' . substr($browserLog, -2000));
            }
            throw new RuntimeException('PDF browser did not produce a document.');
        }

        $pdfBytes = file_get_contents($pdfPath);
        if (!is_string($pdfBytes) || !str_starts_with($pdfBytes, '%PDF-')) {
            throw new RuntimeException('Generated file is not a valid PDF.');
        }
    } finally {
        withdrawal_pdf_remove_tree($temporaryDirectory);
    }

    $customerName = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($withdrawal['client_name'] ?? ''));
    $customerName = trim((string) $customerName, '_-');
    if ($customerName === '') {
        $customerName = 'Customer_ID' . (int) ($withdrawal['client_id'] ?? 0);
    }
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string) ($withdrawal['form_date'] ?? ''))
        ? (string) $withdrawal['form_date']
        : 'undated';
    $filename = $customerName . '_' . $date . '.pdf';

    return [
        'filename' => $filename,
        'content' => $pdfBytes,
    ];
}
