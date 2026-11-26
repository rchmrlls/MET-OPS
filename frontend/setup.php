<?php
require_once __DIR__ . '/../backend/config/app.php';
$message = '';
try {
    $count = (int)db()->query('SELECT COUNT(*) FROM employees')->fetchColumn();
    if ($count > 0) {
        $message = 'Setup is already complete. Please delete or rename setup.php for security.';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $name = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        if ($name === '' || $username === '' || strlen($password) < 10) throw new RuntimeException('Enter a name, username, and password with at least 10 characters.');
        $s = db()->prepare("INSERT INTO employees(full_name,username,password_hash,role,active) VALUES(?,?,?,'Admin',1)");
        $s->execute([$name, $username, password_hash($password, PASSWORD_DEFAULT)]);
        $message = 'Administrator created. Delete setup.php now, then sign in.';
    }
} catch (Throwable $e) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') $message = $e->getMessage();
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Initial Setup</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/ui-system.css?v=<?= (int) filemtime(__DIR__ . '/assets/css/ui-system.css') ?>" rel="stylesheet">
</head>

<body class="auth-shell">
    <main class="auth-card setup-card" aria-labelledby="setupTitle">
        <div class="auth-brand">
            <span class="brand-mark" aria-hidden="true">MFC</span>
            <span class="auth-brand-copy"><strong>MET-OPS</strong><small>Initial configuration</small></span>
        </div>
        <h1 class="auth-heading" id="setupTitle">Initial administrator setup</h1>
        <p class="auth-description">Create the administrator account for this operations workspace.</p>
        <?php if ($message): ?><div class="alert alert-info" role="status"><?= e($message) ?></div><?php endif; ?>
        <?php if (!$message || str_contains($message, 'Enter a name')): ?>
            <form method="post" class="auth-form">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <label class="form-label" for="fullName">Full name</label>
                <input class="form-control" id="fullName" name="full_name" autocomplete="name" required>
                <label class="form-label" for="setupUsername">Username</label>
                <input class="form-control" id="setupUsername" name="username" autocomplete="username" required>
                <label class="form-label" for="setupPassword">Password (10+ characters)</label>
                <input class="form-control" id="setupPassword" type="password" name="password" minlength="10" autocomplete="new-password" required>
                <button class="btn btn-primary" type="submit">Create administrator</button>
            </form>
        <?php endif; ?>
    </main>
</body>

</html>
