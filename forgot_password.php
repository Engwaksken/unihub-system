<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/email-function.php';

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $message = 'If an active account uses that email, password reset instructions will be sent.';
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        try {
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
            $stmt = $pdo->prepare("SELECT id,name,email FROM users WHERE email=? AND status='active' LIMIT 1");
            $stmt->execute([$email]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);
            $baseUrl = rtrim((string)appEnv('APP_URL', ''), '/');
            if ($account && $baseUrl !== '') {
                $token = bin2hex(random_bytes(32));
                $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id=?')->execute([(int)$account['id']]);
                $pdo->prepare('INSERT INTO password_reset_tokens (user_id,token_hash,expires_at) VALUES (?,?,DATE_ADD(NOW(), INTERVAL 30 MINUTE))')
                    ->execute([(int)$account['id'], hash('sha256', $token)]);
                $resetUrl = $baseUrl . '/reset_password.php?token=' . rawurlencode($token);
                $mailer = configureMailer();
                $mailer->addAddress((string)$account['email'], (string)$account['name']);
                $mailer->Subject = 'Reset your password';
                $mailer->isHTML(true);
                $mailer->Body = '<p>Use this secure link to reset your password. It expires in 30 minutes.</p><p><a href="' . htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') . '">Reset password</a></p>';
                $mailer->AltBody = 'Reset your password: ' . $resetUrl;
                $mailer->send();
            }
        } catch (Throwable $e) {
            error_log('Password reset request failed: ' . $e->getMessage());
        }
    }
}
function fp_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Forgot password - Unihub Solutions</title><link rel="icon" href="favicon.php?v=<?= (int)@filemtime(__DIR__ . '/favicon.php') ?>" type="image/png">
<link rel="stylesheet" href="assets/css/style.css?v=<?= (int)@filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head><body class="login-page"><main class="login-card auth-card">
<div class="login-card-brand auth-brand"><img class="auth-logo" src="assets/img/logo.png" alt="Unihub Solutions"><div><span class="brand-name">Reset your password</span><span class="brand-sub">We’ll email a secure reset link.</span></div></div>
<?php if ($message !== ''): ?><div class="login-alert login-alert-info" role="status"><?= fp_h($message) ?></div><?php endif; ?>
<form method="post" class="login-form"><div class="form-group"><label for="email">Account email</label><input id="email" name="email" type="email" required autocomplete="email" placeholder="you@example.com"></div><button class="btn btn-primary login-submit" type="submit">Send reset link</button></form>
<a class="auth-secondary-link" href="login.php">Back to sign in</a>
</main></body></html>
