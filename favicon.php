<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';

$fallback = __DIR__ . '/assets/img/favicon.png';
$target = $fallback;
try {
    $settings = getSettings();
    $configured = trim((string)($settings['company_favicon'] ?? ''));
    if ($configured !== '') {
        $assetRoot = realpath(__DIR__ . '/assets/img');
        $candidate = realpath(__DIR__ . '/' . ltrim($configured, '/\\'));
        $extension = strtolower(pathinfo($configured, PATHINFO_EXTENSION));
        if ($assetRoot && $candidate && in_array($extension, ['png', 'ico', 'svg'], true)
            && str_starts_with($candidate, $assetRoot . DIRECTORY_SEPARATOR) && is_file($candidate)) {
            $target = $candidate;
        }
    }
} catch (Throwable $e) {
    // Keep the bundled favicon available even if settings storage is temporarily unavailable.
}

$mime = match (strtolower(pathinfo($target, PATHINFO_EXTENSION))) {
    'ico' => 'image/x-icon',
    'svg' => 'image/svg+xml',
    default => 'image/png',
};
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
header('Cache-Control: public, max-age=3600');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', (int)filemtime($target)) . ' GMT');
readfile($target);
