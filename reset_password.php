<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
$pdo = getDB();
$pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_password_reset_user (user_id),
    INDEX idx_password_reset_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$error = '';
$success = false;
$validToken = preg_match('/^[a-f0-9]{64}$/', $token) === 1;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
    $password = (string)($_POST['password'] ?? '');
    if (strlen($password) < 12) {
        $error = 'Use a password with at least 12 characters.';
    } else {
        $stmt = $pdo->prepare('SELECT user_id FROM password_reset_tokens WHERE token_hash=? AND expires_at>NOW() LIMIT 1');
        $stmt->execute([hash('sha256', $token)]);
        $reset = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($reset) {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE users SET password=? WHERE id=? LIMIT 1')->execute([password_hash($password, PASSWORD_DEFAULT), (int)$reset['user_id']]);
            $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id=?')->execute([(int)$reset['user_id']]);
            $pdo->commit();
            $success = true;
        } else {
            $error = 'This reset link is invalid or expired. Request a new one.';
        }
    }
} elseif (!$validToken) {
    $error = 'This reset link is invalid or expired. Request a new one.';
}
function rp_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Choose a new password</title><link rel="icon" href="favicon.php?v=<?= (int)@filemtime(__DIR__ . '/favicon.php') ?>" type="image/png"><link rel="stylesheet" href="assets/css/style.css?v=<?= (int)@filemtime(__DIR__ . '/assets/css/style.css') ?>"></head>
<body class="login-page"><main class="login-card auth-card"><div class="login-card-brand auth-brand"><img class="auth-logo" src="assets/img/logo.png" alt="Unihub Solutions"><div><span class="brand-name">Choose a new password</span><span class="brand-sub">Secure your account with a unique password.</span></div></div>
<?php if ($error !== ''): ?><div class="login-alert login-alert-danger" role="alert"><?= rp_h($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="login-alert login-alert-info" role="status">Password updated. <a href="login.php">Sign in</a>.</div><?php elseif ($validToken): ?><form method="post" class="login-form"><input type="hidden" name="token" value="<?= rp_h($token) ?>"><div class="form-group"><label for="password">New password</label><input id="password" name="password" type="password" minlength="12" autocomplete="new-password" required></div><button class="btn btn-primary login-submit" type="submit">Update password</button></form><?php endif; ?>
<a class="auth-secondary-link" href="login.php">Back to sign in</a></main></body></html>
