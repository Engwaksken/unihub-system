<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';

/** Load simple KEY=VALUE environment configuration from the project .env file. */
function loadAppEnv(string $file): void
{
    if (!is_file($file) || !is_readable($file)) {
        return;
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key) || getenv($key) !== false) {
            continue;
        }
        if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
            $value = substr($value, 1, -1);
        }
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
    }
}

loadAppEnv(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

function appEnv(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

function getDB(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = appEnv('DB_HOST', '127.0.0.1');
    $name = appEnv('DB_NAME');
    $user = appEnv('DB_USER');
    $pass = appEnv('DB_PASS', '');
    $charset = appEnv('DB_CHARSET', 'utf8mb4');
    if (!$name || !$user) {
        error_log('Database configuration is incomplete; set DB_NAME and DB_USER in .env.');
        http_response_code(500);
        die('Application configuration error. Please contact the system administrator.');
    }

    try {
        $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        error_log('Database connection failed: ' . $e->getMessage());
        http_response_code(500);
        die('Database connection unavailable. Please contact the system administrator.');
    }
}

function getSettings(): array
{
    $rows = getDB()->query('SELECT `key`, `value` FROM settings')->fetchAll();
    $cfg = [];
    foreach ($rows as $row) {
        $cfg[$row['key']] = $row['value'];
    }
    return $cfg;
}

function ensureUserSignatureProfile(PDO $pdo): void
{
    foreach ([
        'authorized_name' => 'VARCHAR(150) NULL',
        'profile_signature_data' => 'LONGTEXT NULL',
    ] as $column => $definition) {
        $check = $pdo->query('SHOW COLUMNS FROM users LIKE ' . $pdo->quote($column));
        if (!$check->fetch()) {
            $pdo->exec("ALTER TABLE users ADD COLUMN `{$column}` {$definition}");
        }
    }
}

function ensureEmailLogsSchema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        email_type VARCHAR(32) NOT NULL DEFAULT 'other',
        reference_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        recipient_name VARCHAR(255) DEFAULT NULL,
        recipient_email VARCHAR(255) NOT NULL DEFAULT '',
        subject VARCHAR(500) NOT NULL DEFAULT '',
        status VARCHAR(20) NOT NULL DEFAULT 'failed',
        attachment_path VARCHAR(1024) DEFAULT NULL,
        sent_at DATETIME DEFAULT NULL,
        error_message TEXT DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_email_logs_created (created_at),
        INDEX idx_email_logs_status (status),
        INDEX idx_email_logs_type (email_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach ([
        'email_type' => "VARCHAR(32) NOT NULL DEFAULT 'other'",
        'reference_id' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
        'recipient_name' => 'VARCHAR(255) DEFAULT NULL',
        'recipient_email' => "VARCHAR(255) NOT NULL DEFAULT ''",
        'subject' => "VARCHAR(500) NOT NULL DEFAULT ''",
        'status' => "VARCHAR(20) NOT NULL DEFAULT 'failed'",
        'attachment_path' => 'VARCHAR(1024) DEFAULT NULL',
        'sent_at' => 'DATETIME DEFAULT NULL',
        'error_message' => 'TEXT DEFAULT NULL',
        'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ] as $column => $definition) {
        $check = $pdo->query('SHOW COLUMNS FROM email_logs LIKE ' . $pdo->quote($column));
        if (!$check->fetch()) $pdo->exec("ALTER TABLE email_logs ADD COLUMN `{$column}` {$definition}");
    }
}

function nextNumber(string $table, string $col, string $prefix, int $digits = 5): string
{
    // Identifiers cannot be parameter-bound; only permit known SQL identifier syntax.
    foreach ([$table, $col] as $identifier) {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new InvalidArgumentException('Invalid SQL identifier.');
        }
    }
    $offset = strlen($prefix) + 2;
    $stmt = getDB()->query("SELECT MAX(CAST(SUBSTRING(`{$col}`, {$offset}) AS UNSIGNED)) AS mx FROM `{$table}`");
    $mx = (int)($stmt->fetch()['mx'] ?? 0);
    return $prefix . '-' . str_pad((string)($mx + 1), $digits, '0', STR_PAD_LEFT);
}

function jsonResponse(mixed $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function formatMoney(float $amount, string $symbol = 'UGX'): string
{
    return $symbol . ' ' . number_format($amount, 0, '.', ',');
}
