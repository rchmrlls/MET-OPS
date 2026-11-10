<?php

declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
define('APP_NAME', 'Withdrawal & Invoicing System');
define('APP_BASE_URL', rtrim(getenv('WQ_BASE_URL') ?: '/Met-Ops', '/'));
define('APP_VERSION', 'Phase 2 · MySQL Backend');
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/auth.php';
