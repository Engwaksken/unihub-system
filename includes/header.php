<?php
// ==============================
// SESSION + AUTH GUARD
// ==============================
require_once __DIR__ . '/../config/security.php';

// Redirect if not logged in
$currentScript = basename($_SERVER['PHP_SELF']);
if (empty($_SESSION['user_id']) && $currentScript !== 'login.php') {
    header('Location: login.php');
    exit;
}

// ==============================
// SETTINGS + PAGE META
// ==============================
$cfg         = getSettings();
$pageTitle   = $pageTitle ?? 'Dashboard';
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

// Safe helper
if (!function_exists('h')) {
    function h($v) {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

// ==============================
// COMPANY INFO
// ==============================
$companyName = h($cfg['company_name'] ?? 'Unihub Solutions');
$companyLogo = $cfg['company_logo'] ?? '';

$logoPath = __DIR__ . '/../' . ltrim($companyLogo, '/');
$logoIsFile = $companyLogo && file_exists($logoPath);

// fallback monogram
$monogram = mb_strtoupper(mb_substr($cfg['company_name'] ?? 'U', 0, 1));

// ==============================
// USER INFO
// ==============================
$sessionUserName = h($_SESSION['user_name'] ?? '');
$sessionUserRole = h($_SESSION['user_role'] ?? '');
$userInitial     = mb_strtoupper(mb_substr($_SESSION['user_name'] ?? 'U', 0, 1));

// Lightweight cross-module overview, shown consistently across authenticated pages.
$overviewStats = [];
try {
    $statsDb = getDB();
    foreach ([
        'clients' => 'Clients',
        'orders' => 'Orders',
        'invoices' => 'Invoices',
        'receipts' => 'Receipts',
    ] as $table => $label) {
        $overviewStats[] = ['label' => $label, 'count' => (int)$statsDb->query("SELECT COUNT(*) FROM `$table`")->fetchColumn()];
    }
} catch (Throwable $e) {
    $overviewStats = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle) ?> - <?= $companyName ?></title>

    <!-- Favicon -->
    <?php $faviconPath = $cfg['company_favicon'] ?? 'assets/img/favicon.png'; $faviconFile = __DIR__ . '/../' . ltrim($faviconPath, '/'); if (!is_file($faviconFile)) $faviconPath = 'assets/img/favicon.png'; ?>
    <link rel="icon" href="<?= h($faviconPath) ?>?v=<?= (int)@filemtime($faviconFile) ?>" type="<?= str_ends_with(strtolower($faviconPath), '.ico') ? 'image/x-icon' : (str_ends_with(strtolower($faviconPath), '.svg') ? 'image/svg+xml' : 'image/png') ?>">

    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <!-- Main CSS -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/style.css') ?>">
</head>
<body>

<!-- ==============================
     SIDEBAR
================================ -->
<nav class="sidebar" id="sidebar">

    <div class="sidebar-brand">
        <div class="brand-logo">
            <?php if ($logoIsFile): ?>
                <img src="<?= h($companyLogo) ?>" alt="<?= $companyName ?>" class="brand-logo-img">
            <?php else: ?>
                <?= $monogram ?>
            <?php endif; ?>
        </div>

        <div class="brand-text">
            <span class="brand-name"><?= $companyName ?></span>
            <span class="brand-sub"><?= h($cfg['company_address'] ?? '') ?></span>
        </div>
    </div>

    <ul class="nav-list">

        <li class="nav-item <?= $currentPage === 'index' ? 'active' : '' ?>">
            <a href="index.php">
                <i class="fas fa-chart-pie"></i> Dashboard
            </a>
        </li>

        <li class="nav-item <?= $currentPage === 'clients' ? 'active' : '' ?>">
            <a href="clients.php">
                <i class="fas fa-users"></i> Clients
            </a>
        </li>

        <li class="nav-item <?= $currentPage === 'quotations' ? 'active' : '' ?>">
            <a href="quotations.php">
                <i class="fas fa-file-signature"></i> Quotations
            </a>
        </li>

        <li class="nav-item <?= str_starts_with($currentPage, 'delivery_note') ? 'active' : '' ?>">
            <a href="delivery_notes.php"><i class="fas fa-truck"></i> Delivery Notes</a>
        </li>

        <li class="nav-item <?= $currentPage === 'services' ? 'active' : '' ?>">
            <a href="services.php">
                <i class="fas fa-box-open"></i> Services
            </a>
        </li>

        <li class="nav-item <?= $currentPage === 'orders' ? 'active' : '' ?>">
            <a href="orders.php">
                <i class="fas fa-cart-shopping"></i> Orders
            </a>
        </li>

        <li class="nav-item <?= $currentPage === 'invoices' ? 'active' : '' ?>">
            <a href="invoices.php">
                <i class="fas fa-file-invoice-dollar"></i> Invoices
            </a>
        </li>

        <li class="nav-item <?= $currentPage === 'receipts' ? 'active' : '' ?>">
            <a href="receipts.php">
                <i class="fas fa-receipt"></i> Receipts
            </a>
        </li>

        <li class="nav-item <?= $currentPage === 'email_logs' ? 'active' : '' ?>">
            <a href="email_logs.php">
                <i class="fas fa-envelope-open-text"></i> Email Logs
            </a>
        </li>

        <li class="nav-item <?= $currentPage === 'email_settings' ? 'active' : '' ?>">
            <a href="email_settings.php">
                <i class="fas fa-envelope"></i> Email Settings
            </a>
        </li>

        <li class="nav-item <?= $currentPage === 'users' ? 'active' : '' ?>">
            <a href="users.php">
                <i class="fas fa-user-cog"></i> Users
            </a>
        </li>

        <li class="nav-item <?= $currentPage === 'profile' ? 'active' : '' ?>">
            <a href="profile.php"><i class="fas fa-id-badge"></i> My Profile</a>
        </li>

        <li class="nav-item <?= $currentPage === 'settings' ? 'active' : '' ?>">
            <a href="settings.php">
                <i class="fas fa-sliders-h"></i> Settings
            </a>
        </li>

    </ul>

    <div class="sidebar-footer">
        <div class="company-info">
            <?php if (!empty($cfg['company_phone'])): ?>
                <span><i class="fas fa-phone"></i> <?= h($cfg['company_phone']) ?></span>
            <?php endif; ?>

            <?php if (!empty($cfg['company_email'])): ?>
                <span><i class="fas fa-envelope"></i> <?= h($cfg['company_email']) ?></span>
            <?php endif; ?>
        </div>
    </div>

</nav>
<button class="sidebar-backdrop" type="button" aria-label="Close navigation" onclick="toggleSidebar(false)"></button>

<!-- ==============================
     MAIN WRAPPER
================================ -->
<div class="main-wrapper">

<header class="topbar">
    <div class="topbar-left">
        <button class="menu-toggle" type="button" aria-label="Toggle navigation" aria-controls="sidebar" aria-expanded="false" onclick="toggleSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <h1 class="page-title"><?= h($pageTitle) ?></h1>
    </div>

    <div class="topbar-right">

        <span class="topbar-date">
            <i class="fas fa-calendar-days"></i>
            <?= date('l, d M Y') ?>
        </span>

        <?php if ($sessionUserName): ?>
        <div class="topbar-user">
            <div class="topbar-avatar"><?= $userInitial ?></div>

            <div class="topbar-user-info">
                <span class="topbar-user-name"><?= $sessionUserName ?></span>
                <span class="topbar-user-role"><?= $sessionUserRole ?></span>
            </div>

            <a href="logout.php" class="topbar-logout" title="Logout">
                <i class="fas fa-right-from-bracket"></i>
            </a>
        </div>
        <?php endif; ?>

    </div>
</header>

<main class="main-content">
<?php if ($overviewStats): ?>
<section class="page-overview-stats" aria-label="System overview">
    <?php foreach ($overviewStats as $i => $stat): ?>
        <article class="overview-stat-card">
            <span class="overview-stat-icon"><i class="fas <?= ['fa-users', 'fa-cart-shopping', 'fa-file-invoice-dollar', 'fa-receipt'][$i] ?>" aria-hidden="true"></i></span>
            <div><span class="overview-stat-label"><?= h($stat['label']) ?></span><strong><?= number_format($stat['count']) ?></strong></div>
            <span class="overview-stat-caption">All records</span>
        </article>
    <?php endforeach; ?>
</section>
<?php endif; ?>
