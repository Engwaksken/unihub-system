<?php
declare(strict_types=1);

require_once __DIR__ . '/config/security.php';

require_once __DIR__ . '/config/db.php';

$pdo = getDB();
if (!$pdo instanceof PDO) {
    die('Database connection unavailable.');
}

if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';
$info  = '';

if (isset($_GET['error']) && $_GET['error'] === 'inactive') {
    $error = 'Your account is inactive. Please contact the administrator.';
}

if (isset($_GET['logged_out'])) {
    $info = 'You have logged out successfully.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $identity = trim((string)($_POST['identity'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($identity === '') {
            throw new RuntimeException('Enter username or email.');
        }

        if ($password === '') {
            throw new RuntimeException('Enter password.');
        }

        $stmt = $pdo->prepare("
            SELECT id, name, email, phone, username, password, role, status
            FROM users
            WHERE username = :username OR email = :email
            LIMIT 1
        ");

        $stmt->execute([
            ':username' => $identity,
            ':email'    => $identity
        ]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            throw new RuntimeException('Invalid login credentials.');
        }

        if (strtolower((string)($user['status'] ?? '')) !== 'active') {
            throw new RuntimeException('Your account is inactive.');
        }

        $storedHash = (string)($user['password'] ?? '');

        if ($storedHash === '' || !password_verify($password, $storedHash)) {
            throw new RuntimeException('Invalid login credentials.');
        }

        if (password_needs_rehash($storedHash, PASSWORD_DEFAULT)) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);

            $pdo->prepare("
                UPDATE users 
                SET password = ? 
                WHERE id = ? 
                LIMIT 1
            ")->execute([
                $newHash,
                (int)$user['id']
            ]);
        }

        $pdo->prepare("
            UPDATE users 
            SET last_login = NOW() 
            WHERE id = ? 
            LIMIT 1
        ")->execute([
            (int)$user['id']
        ]);

        session_regenerate_id(true);

        $_SESSION['user_id']    = (int)$user['id'];
        $_SESSION['user_name']  = (string)($user['name'] ?? '');
        $_SESSION['user_email'] = (string)($user['email'] ?? '');
        $_SESSION['user_role']  = (string)($user['role'] ?? '');
        $_SESSION['username']   = (string)($user['username'] ?? '');

        header('Location: index.php');
        exit;

    } catch (Throwable $e) {
        if (!$e instanceof RuntimeException) {
            error_log('Login request failed: ' . $e->getMessage());
            $error = 'Login is temporarily unavailable. Please try again.';
        } else {
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login - Unihub Solutions</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="assets/img/favicon.png" type="image/png">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <link rel="stylesheet" href="assets/css/style.css?v=<?= (int)@filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <link rel="stylesheet" href="assets/css/login.css">
</head>
<body class="login-page">

<div class="login-card">

<div class="login-card-brand">
        <img class="auth-logo" src="assets/img/logo.png" alt="Unihub Solutions">
        <div>
            <span class="brand-name">Unihub Solutions</span>
            <span class="brand-sub">Sign in to your account</span>
        </div>
    </div>

    <?php if ($error !== ''): ?>
        <div class="login-alert login-alert-danger">
            <i class="fas fa-exclamation-circle"></i>
            <?= h($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($info !== ''): ?>
        <div class="login-alert login-alert-info">
            <i class="fas fa-info-circle"></i>
            <?= h($info) ?>
        </div>
    <?php endif; ?>

    <form method="post" autocomplete="off" class="login-form">
        <div class="form-group">
            <label>
                <i class="fas fa-user"></i>
                Username or Email
            </label>
            <input
                type="text"
                name="identity"
                required
                placeholder="Enter username or email"
                value="<?= h($_POST['identity'] ?? '') ?>"
            >
        </div>

        <div class="form-group">
            <label>
                <i class="fas fa-lock"></i>
                Password
            </label>
            <input
                type="password"
                name="password"
                required
                placeholder="Enter password"
            >
        </div>

        <button type="submit" class="btn btn-primary login-submit">
            <i class="fas fa-sign-in-alt"></i>
            Login
        </button>
    </form>
    <a class="auth-secondary-link" href="forgot_password.php">Forgot your password?</a>

</div>

</body>
</html>
