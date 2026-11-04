<?php
require_once 'config/db.php';

$pageTitle = 'Dashboard';
$cfg = getSettings();
$pdo = getDB();

$stats = $pdo->query("
    SELECT
        (SELECT COUNT(*) FROM clients) AS total_clients,
        (SELECT COUNT(*) FROM orders) AS total_orders,
        (SELECT COUNT(*) FROM invoices) AS total_invoices,
        (SELECT COUNT(*) FROM invoices WHERE status = 'unpaid') AS unpaid_count,
        (SELECT COALESCE(SUM(total), 0) FROM invoices WHERE status = 'paid') AS total_collected,
        (SELECT COALESCE(SUM(total), 0) FROM invoices WHERE status = 'unpaid') AS total_outstanding
")->fetch(PDO::FETCH_ASSOC);

$recent_orders = $pdo->query("
    SELECT o.*, c.name AS client_name
    FROM orders o
    JOIN clients c ON c.id = o.client_id
    ORDER BY o.created_at DESC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

$recent_invoices = $pdo->query("
    SELECT i.*, c.name AS client_name
    FROM invoices i
    JOIN orders o ON o.id = i.order_id
    JOIN clients c ON c.id = o.client_id
    ORDER BY i.created_at DESC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label">Total Clients</div>
        <div class="stat-value"><?= number_format((int)($stats['total_clients'] ?? 0)) ?></div>
        <div class="stat-sub">Registered clients</div>
        <div class="stat-icon"><i class="fas fa-users"></i></div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Total Orders</div>
        <div class="stat-value"><?= number_format((int)($stats['total_orders'] ?? 0)) ?></div>
        <div class="stat-sub">All time orders</div>
        <div class="stat-icon"><i class="fas fa-cart-shopping"></i></div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Revenue Collected</div>
        <div class="stat-value" style="font-size:1.3rem">
            <?= formatMoney((float)($stats['total_collected'] ?? 0), $cfg['currency_symbol'] ?? 'UGX ') ?>
        </div>
        <div class="stat-sub">From paid invoices</div>
        <div class="stat-icon"><i class="fas fa-coins"></i></div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Outstanding</div>
        <div class="stat-value" style="font-size:1.3rem;color:var(--amber)">
            <?= formatMoney((float)($stats['total_outstanding'] ?? 0), $cfg['currency_symbol'] ?? 'UGX ') ?>
        </div>
        <div class="stat-sub">
            <?= number_format((int)($stats['unpaid_count'] ?? 0)) ?>
            unpaid invoice<?= (int)($stats['unpaid_count'] ?? 0) !== 1 ? 's' : '' ?>
        </div>
        <div class="stat-icon"><i class="fas fa-circle-exclamation"></i></div>
    </div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:20px;margin-top:4px">

    <div class="card dashboard-recent-tabs" style="grid-column:1/-1">
        <div class="dashboard-tab-list" role="tablist" aria-label="Recent records">
            <button type="button" role="tab" id="recent-orders-tab" aria-controls="recent-orders-panel" aria-selected="true" class="dashboard-tab active">Recent Orders</button>
            <button type="button" role="tab" id="recent-invoices-tab" aria-controls="recent-invoices-panel" aria-selected="false" class="dashboard-tab">Recent Invoices</button>
        </div>
    </div>

    <div class="card dashboard-recent-panel" id="recent-orders-panel" role="tabpanel" aria-labelledby="recent-orders-tab">
        <div class="card-header">
            <span class="card-title">
                <i class="fas fa-cart-shopping"></i>
                Recent Orders
            </span>
            <a href="orders.php" class="btn btn-outline btn-sm">
                <i class="fas fa-arrow-right"></i>
                View All
            </a>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Order #</th>
                        <th>Client</th>
                        <th>Date</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recent_orders as $o): ?>
                    <tr>
                        <td>
                            <a href="orders.php?id=<?= (int)$o['id'] ?>" style="color:var(--gold);text-decoration:none">
                                <?= htmlspecialchars((string)$o['order_number']) ?>
                            </a>
                        </td>
                        <td><?= htmlspecialchars((string)$o['client_name']) ?></td>
                        <td><?= !empty($o['order_date']) ? date('d/m/Y', strtotime((string)$o['order_date'])) : '�' ?></td>
                        <td>
                            <span class="badge badge-<?= htmlspecialchars((string)$o['status']) ?>">
                                <?= ucfirst(htmlspecialchars((string)$o['status'])) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (empty($recent_orders)): ?>
                    <tr>
                        <td colspan="4" class="empty-state" style="padding:30px">
                            <i class="fas fa-inbox"></i>
                            No orders yet
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card dashboard-recent-panel" id="recent-invoices-panel" role="tabpanel" aria-labelledby="recent-invoices-tab" hidden>
        <div class="card-header">
            <span class="card-title">
                <i class="fas fa-file-invoice-dollar"></i>
                Recent Invoices
            </span>
            <a href="invoices.php" class="btn btn-outline btn-sm">
                <i class="fas fa-arrow-right"></i>
                View All
            </a>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Client</th>
                        <th>Total</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recent_invoices as $inv): ?>
                    <tr>
                        <td>
                            <a href="invoice_view.php?id=<?= (int)$inv['id'] ?>" style="color:var(--gold);text-decoration:none">
                                <?= htmlspecialchars((string)$inv['invoice_number']) ?>
                            </a>
                        </td>
                        <td><?= htmlspecialchars((string)$inv['client_name']) ?></td>
                        <td><?= formatMoney((float)$inv['total'], $cfg['currency_symbol'] ?? 'UGX ') ?></td>
                        <td>
                            <span class="badge badge-<?= htmlspecialchars((string)$inv['status']) ?>">
                                <?= ucfirst(htmlspecialchars((string)$inv['status'])) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (empty($recent_invoices)): ?>
                    <tr>
                        <td colspan="4" class="empty-state" style="padding:30px">
                            <i class="fas fa-inbox"></i>
                            No invoices yet
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<style>
.dashboard-recent-tabs,.dashboard-recent-panel{grid-column:1/-1;width:100%;box-sizing:border-box}.dashboard-recent-tabs{margin-bottom:-18px;padding:0 18px}.dashboard-tab-list{display:flex;width:100%;gap:8px;border-bottom:1px solid var(--border)}.dashboard-tab{flex:1 1 0;min-width:0;border:0;background:transparent;color:var(--text-muted);padding:14px 16px;font:inherit;font-weight:600;cursor:pointer;border-bottom:2px solid transparent;text-align:center}.dashboard-tab.active{color:var(--navy);border-color:var(--gold)}
</style>
<script>
document.querySelectorAll('.dashboard-tab').forEach(tab=>tab.addEventListener('click',()=>{document.querySelectorAll('.dashboard-tab').forEach(t=>{t.classList.toggle('active',t===tab);t.setAttribute('aria-selected',t===tab?'true':'false');});document.querySelectorAll('.dashboard-recent-panel').forEach(panel=>panel.hidden=panel.id!==tab.getAttribute('aria-controls'));}));
</script>

<div style="display:flex;gap:12px;margin-top:20px;flex-wrap:wrap">
    <a href="orders.php?new=1" class="btn btn-primary">
        <i class="fas fa-plus"></i>
        New Order
    </a>

    <a href="clients.php?new=1" class="btn btn-outline">
        <i class="fas fa-user-plus"></i>
        Add Client
    </a>

    <a href="quotations.php" class="btn btn-outline">
        <i class="fas fa-file-signature"></i>
        New Quotation
    </a>

    <a href="services.php" class="btn btn-ghost">
        <i class="fas fa-box-open"></i>
        Manage Services
    </a>
</div>

<?php include 'includes/footer.php'; ?>
