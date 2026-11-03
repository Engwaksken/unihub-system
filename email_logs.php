<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';

$pdo = getDB();
if (!$pdo instanceof PDO) die('Database connection unavailable.');

// This module owns the log schema so the page works before the first email is sent.
$pdo->exec("CREATE TABLE IF NOT EXISTS email_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email_type VARCHAR(32) NOT NULL DEFAULT 'other',
    reference_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    recipient_name VARCHAR(255) DEFAULT NULL,
    recipient_email VARCHAR(255) NOT NULL,
    subject VARCHAR(500) NOT NULL,
    status VARCHAR(20) NOT NULL,
    attachment_path VARCHAR(1024) DEFAULT NULL,
    sent_at DATETIME DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_logs_created (created_at),
    INDEX idx_email_logs_status (status),
    INDEX idx_email_logs_type (email_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

$statusFilter = trim($_GET['status'] ?? '');
$typeFilter   = trim($_GET['type']   ?? '');
$search       = trim($_GET['q']      ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 20;
$offset       = ($page - 1) * $perPage;

$where = []; $params = [];
if ($statusFilter !== '') { $where[] = 'status = :status';         $params[':status']     = $statusFilter; }
if ($typeFilter   !== '') { $where[] = 'email_type = :email_type'; $params[':email_type'] = $typeFilter; }
if ($search       !== '') {
    $where[] = '(recipient_email LIKE :s_email OR recipient_name LIKE :s_name OR subject LIKE :s_subject OR attachment_path LIKE :s_attachment OR error_message LIKE :s_error)';
    foreach (['email', 'name', 'subject', 'attachment', 'error'] as $field) {
        $params[':s_' . $field] = '%' . $search . '%';
    }
}
$wsql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$cnt = $pdo->prepare("SELECT COUNT(*) FROM email_logs $wsql");
$cnt->execute($params);
$totalPages = max(1, (int)ceil($cnt->fetchColumn() / $perPage));

$lst = $pdo->prepare("SELECT * FROM email_logs $wsql ORDER BY created_at DESC, id DESC LIMIT :lim OFFSET :off");
foreach ($params as $k => $v) $lst->bindValue($k, $v);
$lst->bindValue(':lim', $perPage, PDO::PARAM_INT);
$lst->bindValue(':off', $offset,  PDO::PARAM_INT);
$lst->execute();
$rows = $lst->fetchAll(PDO::FETCH_ASSOC);

$stats = $pdo->query("SELECT COUNT(*) AS total_logs,
    SUM(status='sent') AS sent_logs, SUM(status='failed') AS failed_logs,
    SUM(status='pending') AS pending_logs,
    SUM(email_type='invoice') AS invoice_logs, SUM(email_type='receipt') AS receipt_logs
    FROM email_logs")->fetch(PDO::FETCH_ASSOC) ?: [];

function buildQuery(array $overrides = []): string {
    $q = array_filter(array_merge($_GET, $overrides), fn($v) => $v !== '' && $v !== null);
    return '?' . http_build_query($q);
}

$pageTitle = 'Email Logs';
include __DIR__ . '/includes/header.php';
?>

<div class="section-header">
    <span class="section-title">Email Logs</span>
    <a class="btn btn-ghost btn-sm" href="index.php">Back</a>
</div>

<nav class="logs-tabs" aria-label="Filter email logs by status">
    <?php foreach ([
        '' => ['All', (int)($stats['total_logs'] ?? 0)],
        'sent' => ['Sent', (int)($stats['sent_logs'] ?? 0)],
        'failed' => ['Failed', (int)($stats['failed_logs'] ?? 0)],
        'pending' => ['Pending', (int)($stats['pending_logs'] ?? 0)],
    ] as $value => [$label, $count]): ?>
        <a class="logs-tab <?= $statusFilter === $value ? 'active' : '' ?>" href="<?= h('email_logs.php' . buildQuery(['status' => $value, 'page' => 1])) ?>"><?= h($label) ?><span><?= $count ?></span></a>
    <?php endforeach; ?>
</nav>

<!-- Filters -->
<div class="card" style="margin-bottom:20px">
    <form class="filter-bar logs-filter-bar" method="get">
        <input class="search-input" type="text" name="q"
               value="<?= h($search) ?>" placeholder="Email, name, subject, error…">
        <select class="search-input logs-filter-select" name="status">
            <option value="">All Statuses</option>
            <option value="sent"    <?= $statusFilter==='sent'   ?'selected':'' ?>>Sent</option>
            <option value="failed"  <?= $statusFilter==='failed' ?'selected':'' ?>>Failed</option>
            <option value="pending" <?= $statusFilter==='pending'?'selected':'' ?>>Pending</option>
        </select>
        <select class="search-input logs-filter-select" name="type">
            <option value="">All Types</option>
            <option value="invoice" <?= $typeFilter==='invoice'?'selected':'' ?>>Invoice</option>
            <option value="receipt" <?= $typeFilter==='receipt'?'selected':'' ?>>Receipt</option>
            <option value="other"   <?= $typeFilter==='other'  ?'selected':'' ?>>Other</option>
        </select>
        <button class="btn btn-primary btn-sm" type="submit">Filter</button>
        <a href="email_logs.php" class="btn btn-ghost btn-sm">Reset</a>
    </form>
</div>

<!-- Table -->
<div class="card">
    <div class="table-wrap">
        <?php if (!$rows): ?>
            <div class="empty-state">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                </svg>
                <p>No email logs found.</p>
            </div>
        <?php else: ?>
        <table id="logs-table">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Recipient</th>
                    <th>Subject / Details</th>
                    <th>Status / Sent</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
            <tr>
                <td>
                    <span class="badge <?= $row['email_type']==='invoice' ? 'badge-invoiced' : 'badge-paid' ?>">
                        <?= h($row['email_type']) ?>
                    </span>
                </td>
                <td>
                    <strong><?= h($row['recipient_name'] ?: '—') ?></strong><br>
                    <span class="users-last-login"><?= h($row['recipient_email']) ?></span>
                </td>
                <td>
                    <strong><?= h($row['subject']) ?></strong><br>
                    <span class="users-last-login"><?= h($row['created_at']) ?></span>
                    <?php if (!empty($row['attachment_path']) || !empty($row['error_message'])): ?>
                    <details class="log-details"><summary>More details</summary>
                        <span>Reference: <?= h($row['email_type']) ?> #<?= (int)$row['reference_id'] ?></span>
                        <?php if (!empty($row['attachment_path'])): ?><span>File: <?= h(basename((string)$row['attachment_path'])) ?></span><?php endif; ?>
                        <?php if (!empty($row['error_message'])): ?><pre class="logs-error"><?= h($row['error_message']) ?></pre><?php endif; ?>
                    </details>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="badge <?= match($row['status']) {
                        'sent'    => 'badge-paid',
                        'failed'  => 'badge-overdue',
                        'pending' => 'badge-unpaid',
                        default   => 'badge-draft'
                    } ?>">
                        <?= h($row['status']) ?>
                    </span>
                    <small class="log-sent-at"><?= !empty($row['sent_at']) ? h($row['sent_at']) : 'Not sent' ?></small>
                </td>
                <td>
                    <div class="td-actions">
                        <?php if ($row['email_type'] === 'invoice'): ?>
                            <a class="btn btn-ghost btn-sm" title="View invoice" aria-label="View invoice" href="invoice_view.php?id=<?= (int)$row['reference_id'] ?>"><i class="fas fa-eye"></i></a>
                            <button class="btn btn-primary btn-sm" title="Resend email" aria-label="Resend email" onclick="resendLog('invoice', <?= (int)$row['reference_id'] ?>)"><i class="fas fa-paper-plane"></i></button>
                        <?php elseif ($row['email_type'] === 'receipt'): ?>
                            <a class="btn btn-ghost btn-sm" title="View receipt" aria-label="View receipt" href="receipt_view.php?id=<?= (int)$row['reference_id'] ?>"><i class="fas fa-eye"></i></a>
                            <button class="btn btn-primary btn-sm" title="Resend email" aria-label="Resend email" onclick="resendLog('receipt', <?= (int)$row['reference_id'] ?>)"><i class="fas fa-paper-plane"></i></button>
                        <?php else: ?>
                            <span class="users-never">—</span>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <div class="users-pagination">
            <?php if ($page > 1): ?>
                <a class="btn btn-ghost btn-sm" href="email_logs.php<?= buildQuery(['page' => $page - 1]) ?>">Previous</a>
            <?php endif; ?>
            <span class="users-page-info">Page <?= $page ?> of <?= $totalPages ?></span>
            <?php if ($page < $totalPages): ?>
                <a class="btn btn-ghost btn-sm" href="email_logs.php<?= buildQuery(['page' => $page + 1]) ?>">Next</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
async function resendLog(type, logId) {
    const endpoint = type === 'invoice' ? 'send_invoice.php' : 'send_receipt.php';
    try {
        const res  = await fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(type === 'invoice' ? { invoice_id: logId } : { receipt_id: logId })
        });
        const data = await res.json();
        if (typeof showToast === 'function') {
            showToast(data.success ? (data.message || 'Resent successfully') : (data.error || 'Resend failed'), data.success ? 'success' : 'error');
        } else {
            alert(data.success ? data.message : (data.error || 'Resend failed'));
        }
        if (data.success) setTimeout(() => location.reload(), 800);
    } catch (e) {
        alert('Network error while resending.');
    }
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
