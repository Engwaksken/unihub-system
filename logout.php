<?php
declare(strict_types=1);

require_once __DIR__ . '/config/security.php';

/* Clear all session values */
$_SESSION = [];

/* Remove the session cookie if sessions use cookies */
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'] ?? '/',
        $params['domain'] ?? '',
        (bool)($params['secure'] ?? false),
        (bool)($params['httponly'] ?? true)
    );
}

/* Destroy the session */
session_destroy();

/* Redirect back to login */
header('Location: login.php');
exit;
