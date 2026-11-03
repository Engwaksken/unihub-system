<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/email-function.php';

$pdo = getDB();
if (!$pdo instanceof PDO) die('Database connection unavailable.');

if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('ensureEmailSettingsTable')) {
    function ensureEmailSettingsTable(PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS email_settings (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            smtp_host VARCHAR(255) NOT NULL,
            smtp_port INT NOT NULL DEFAULT 587,
            smtp_user VARCHAR(255) DEFAULT NULL,
            smtp_password VARCHAR(255) DEFAULT NULL,
            smtp_secure VARCHAR(20) DEFAULT 'tls',
            from_email VARCHAR(255) NOT NULL,
            from_name VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }
}

if (!function_exists('getLatestEmailSettings')) {
    function getLatestEmailSettings(PDO $pdo): ?array {
        $row = $pdo->query("SELECT * FROM email_settings ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('saveEmailSettings')) {
    function saveEmailSettings(PDO $pdo, array $data, ?int $id = null): int {
        if ($id && $id > 0) {
            $pdo->prepare("UPDATE email_settings SET smtp_host=:smtp_host, smtp_port=:smtp_port,
                smtp_user=:smtp_user, smtp_password=:smtp_password, smtp_secure=:smtp_secure,
                from_email=:from_email, from_name=:from_name WHERE id=:id LIMIT 1")
                ->execute([...$data, ':id' => $id]);
            return $id;
        }
        $pdo->prepare("INSERT INTO email_settings (smtp_host,smtp_port,smtp_user,smtp_password,smtp_secure,from_email,from_name)
            VALUES (:smtp_host,:smtp_port,:smtp_user,:smtp_password,:smtp_secure,:from_email,:from_name)")
            ->execute($data);
        return (int)$pdo->lastInsertId();
    }
}

ensureEmailSettingsTable($pdo);

$success = '';
$error   = '';

$current = getLatestEmailSettings($pdo) ?: [
    'id' => 0, 'smtp_host' => '', 'smtp_port' => 587,
    'smtp_user' => '', 'smtp_password' => '',
    'smtp_secure' => 'tls', 'from_email' => '', 'from_name' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? 'save');
    try {
        if ($action === 'save') {
            $smtpHost     = trim($_POST['smtp_host']     ?? '');
            $smtpPort     = (int)($_POST['smtp_port']    ?? 587);
            $smtpUser     = trim($_POST['smtp_user']     ?? '');
            $smtpPassword = $_POST['smtp_password']      ?? '';
            $smtpSecure   = strtolower(trim($_POST['smtp_secure'] ?? 'tls'));
            $fromEmail    = trim($_POST['from_email']    ?? '');
            $fromName     = trim($_POST['from_name']     ?? '');

            if (!$smtpHost) throw new RuntimeException('SMTP host is required.');
            if ($smtpPort <= 0) throw new RuntimeException('SMTP port must be valid.');
            if (!in_array($smtpSecure, ['tls','ssl','none'], true))
                throw new RuntimeException('SMTP security must be tls, ssl, or none.');
            if (!$fromEmail || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL))
                throw new RuntimeException('Please enter a valid from email address.');

            $id = (int)($current['id'] ?? 0);
            saveEmailSettings($pdo, [
                ':smtp_host' => $smtpHost, ':smtp_port' => $smtpPort,
                ':smtp_user' => $smtpUser, ':smtp_password' => $smtpPassword,
                ':smtp_secure' => $smtpSecure, ':from_email' => $fromEmail, ':from_name' => $fromName,
            ], $id > 0 ? $id : null);

            $success = 'Email settings saved successfully.';
            $current = getLatestEmailSettings($pdo) ?: $current;
        }

        if ($action === 'test_email') {
            $testEmail = trim($_POST['test_email'] ?? '');
            $testName  = trim($_POST['test_name']  ?? '');

            if (!$testEmail || !filter_var($testEmail, FILTER_VALIDATE_EMAIL))
                throw new RuntimeException('Enter a valid test email address.');

            $result = ef_test_email_settings($testEmail, $testName);
            if (!$result['success'])
                throw new RuntimeException($result['error'] ?: $result['message'] ?: 'Test email failed.');

            $success = 'Test email sent successfully to ' . $testEmail . '.';
            $current = getLatestEmailSettings($pdo) ?: $current;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $current = array_merge($current, [
            'smtp_host'   => $_POST['smtp_host']   ?? $current['smtp_host'],
            'smtp_port'   => $_POST['smtp_port']   ?? $current['smtp_port'],
            'smtp_user'   => $_POST['smtp_user']   ?? $current['smtp_user'],
            'smtp_secure' => $_POST['smtp_secure'] ?? $current['smtp_secure'],
            'from_email'  => $_POST['from_email']  ?? $current['from_email'],
            'from_name'   => $_POST['from_name']   ?? $current['from_name'],
        ]);
    }
}

$pageTitle = 'Email Settings';
include __DIR__ . '/includes/header.php';
?>

<div class="section-header">
    <span class="section-title">Email / SMTP Settings</span>
    <a class="btn btn-ghost btn-sm" href="settings.php">Back to Settings</a>
</div>

<?php if ($success !== ''): ?>
    <div class="settings-alert settings-alert-success"><?= h($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="settings-alert settings-alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<!-- SMTP Configuration -->
<div class="settings-card" style="margin-bottom:20px">
    <div class="settings-card-header">
        <span class="settings-card-title">SMTP Configuration</span>
    </div>
    <div class="settings-card-body">
        <form method="post">
            <input type="hidden" name="action" value="save">
            <div class="form-grid">
                <div class="form-group">
                    <label>SMTP Host</label>
                    <input type="text" name="smtp_host" required
                           value="<?= h($current['smtp_host'] ?? '') ?>" placeholder="smtp.gmail.com">
                </div>
                <div class="form-group">
                    <label>SMTP Port</label>
                    <input type="number" name="smtp_port" required
                           value="<?= h((string)($current['smtp_port'] ?? 587)) ?>" placeholder="587">
                </div>
                <div class="form-group">
                    <label>SMTP Username</label>
                    <input type="text" name="smtp_user"
                           value="<?= h($current['smtp_user'] ?? '') ?>" placeholder="your_email@gmail.com">
                </div>
                <div class="form-group">
                    <label>SMTP Password / App Password</label>
                    <input type="password" name="smtp_password"
                           value="<?= h($current['smtp_password'] ?? '') ?>" placeholder="Enter SMTP password">
                </div>
                <div class="form-group">
                    <label>SMTP Security</label>
                    <select name="smtp_secure">
                        <option value="tls"  <?= ($current['smtp_secure'] ?? '') === 'tls'  ? 'selected' : '' ?>>TLS</option>
                        <option value="ssl"  <?= ($current['smtp_secure'] ?? '') === 'ssl'  ? 'selected' : '' ?>>SSL</option>
                        <option value="none" <?= ($current['smtp_secure'] ?? '') === 'none' ? 'selected' : '' ?>>None</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>From Email</label>
                    <input type="email" name="from_email" required
                           value="<?= h($current['from_email'] ?? '') ?>" placeholder="billing@yourdomain.com">
                </div>
                <div class="form-group">
                    <label>From Name</label>
                    <input type="text" name="from_name"
                           value="<?= h($current['from_name'] ?? '') ?>" placeholder="Acme Ltd">
                </div>
                <div class="form-group email-info-box">
                    For Gmail, use:
                    <code class="email-code-hint">Host: smtp.gmail.com &nbsp;|&nbsp; Port: 587 &nbsp;|&nbsp; Security: TLS</code>
                    Use a Google App Password, not your normal Gmail password.
                </div>
            </div>
            <div class="settings-actions-bar" style="margin-top:18px">
                <button type="submit" class="btn btn-primary">Save Email Settings</button>
            </div>
        </form>
    </div>
</div>

<!-- Test Email -->
<div class="settings-card">
    <div class="settings-card-header">
        <span class="settings-card-title">Send Test Email</span>
    </div>
    <div class="settings-card-body">
        <form method="post">
            <input type="hidden" name="action" value="test_email">
            <div class="form-grid">
                <div class="form-group">
                    <label>Test Recipient Email</label>
                    <input type="email" name="test_email" required placeholder="client@example.com">
                </div>
                <div class="form-group">
                    <label>Recipient Name</label>
                    <input type="text" name="test_name" placeholder="Client Name">
                </div>
            </div>
            <div class="settings-actions-bar" style="margin-top:18px">
                <button type="submit" class="btn btn-primary">Send Test Email</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>