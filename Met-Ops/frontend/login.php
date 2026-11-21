<?php
require_once __DIR__ . '/../backend/config/app.php';
if (!empty($_SESSION['user'])) {
    header('Location: ' . APP_BASE_URL . '/frontend/dashboard.php');
    exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $s = db()->prepare('SELECT * FROM employees WHERE username=? AND active=1 LIMIT 1');
    $s->execute([$username]);
    $u = $s->fetch();
    if ($u && password_verify($password, $u['password_hash'])) {
        login_user($u);
        $s = db()->prepare('UPDATE employees SET updated_at=updated_at WHERE id=?');
        $s->execute([$u['id']]);
        log_activity(db(), 'LOGIN', 'employee', (int)$u['id'], 'Successful login');
        header('Location: ' . APP_BASE_URL . '/frontend/dashboard.php');
        exit;
    }
    $error = 'Invalid username or password.';
}
?>

<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <link rel="icon" href="assets/images/MFC-Icon.png" type="image/png">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Sign in | <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/ui-system.css?v=<?= (int) filemtime(__DIR__ . '/assets/css/ui-system.css') ?>" rel="stylesheet">
</head>

<body class="auth-shell">
    <main class="auth-card" aria-labelledby="signInTitle">
        <div class="auth-brand">
            <span class="brand-mark" aria-hidden="true">MFC</span>
            <span class="auth-brand-copy"><strong>MET-OPS</strong><small>Operations workspace</small></span>
        </div>
        <h1 class="auth-heading" id="signInTitle">Sign in</h1>
        <p class="auth-description">Use your employee account to continue.</p>
        <?php if ($error): ?>
            <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" class="auth-form">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <label class="form-label" for="username">Username</label>
            <input class="form-control" id="username" name="username" autocomplete="username" required autofocus>
            <label class="form-label" for="password">Password</label>
            <input class="form-control" id="password" type="password" name="password" autocomplete="current-password" required>
            <button class="btn btn-primary w-100" type="submit">Sign in</button>
        </form>
    </main>
</body>

</html>
