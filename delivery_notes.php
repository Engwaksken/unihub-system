<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
$pdo = getDB();
$cfg = getSettings();
$pageTitle = 'Delivery Notes';
$orderId = (int)($_GET['view'] ?? 0);
if (!function_exists('h')) { function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); } }
$orders = $pdo->query("SELECT o.id,o.order_number,o.order_date,o.status,c.name AS client_name,c.company,c.phone,c.address FROM orders o JOIN clients c ON c.id=o.client_id ORDER BY o.created_at DESC,o.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$order = null;
$items = [];
if ($orderId > 0) {
    $stmt = $pdo->prepare("SELECT o.*,c.name AS client_name,c.company,c.phone,c.email,c.address FROM orders o JOIN clients c ON c.id=o.client_id WHERE o.id=? LIMIT 1");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($order) {
        $stmt = $pdo->prepare('SELECT description,quantity FROM order_items WHERE order_id=? ORDER BY id');
        $stmt->execute([$orderId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
$profileStmt = $pdo->prepare('SELECT authorized_name,profile_signature_data FROM users WHERE id=? LIMIT 1');
$profileStmt->execute([(int)($_SESSION['user_id'] ?? 0)]);
$signatory = $profileStmt->fetch(PDO::FETCH_ASSOC) ?: [];
include __DIR__ . '/includes/header.php';
?>
<section class="delivery-notes-page">
    <?php if ($order): ?>
        <div class="section-header no-print"><span class="section-title">Delivery Note</span><div><a class="btn btn-ghost" href="delivery_notes.php">Back</a> <button class="btn btn-primary" onclick="window.print()">Print</button></div></div>
        <article class="card delivery-note-print">
            <header class="delivery-note-header"><div><h2><?= h($cfg['company_name'] ?? 'Company') ?></h2><div><?= nl2br(h($cfg['company_address'] ?? '')) ?></div><div><?= h($cfg['company_phone'] ?? '') ?></div></div><div><h1>DELIVERY NOTE</h1><strong><?= h($order['order_number']) ?></strong><p>Date: <?= h(date('d M Y', strtotime((string)$order['order_date']))) ?></p></div></header>
            <section class="delivery-note-client"><strong>Deliver to</strong><br><?= h($order['client_name']) ?><?= !empty($order['company']) ? '<br>'.h($order['company']) : '' ?><?= !empty($order['phone']) ? '<br>'.h($order['phone']) : '' ?><?= !empty($order['address']) ? '<br>'.nl2br(h($order['address'])) : '' ?></section>
            <table class="data-table"><thead><tr><th>#</th><th>Description</th><th>Quantity</th><th>Received</th></tr></thead><tbody><?php foreach ($items as $i => $item): ?><tr><td><?= $i + 1 ?></td><td><?= h($item['description']) ?></td><td><?= number_format((float)$item['quantity'], 2) ?></td><td style="min-width:160px"></td></tr><?php endforeach; ?><?php if (!$items): ?><tr><td colspan="4">No items on this order.</td></tr><?php endif; ?></tbody></table>
            <div class="delivery-note-signatures"><div><?php if (!empty($signatory['profile_signature_data'])): ?><img src="<?= h($signatory['profile_signature_data']) ?>" alt="Authorized signature"><?php endif; ?><hr><strong><?= h($signatory['authorized_name'] ?? '') ?></strong><div>Authorized by</div></div><div><hr><strong>Recipient name & signature</strong><div>Date received: __________________</div></div></div>
        </article>
    <?php else: ?>
        <div class="section-header"><span class="section-title">Delivery Notes</span></div>
        <div class="card"><div class="table-wrap"><table class="data-table" data-client-pagination data-date-column="2"><thead><tr><th>Order #</th><th>Client</th><th>Order date</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach ($orders as $row): ?><tr><td><?= h($row['order_number']) ?></td><td><?= h($row['client_name']) ?></td><td><?= h(date('d M Y', strtotime((string)$row['order_date']))) ?></td><td><?= h(ucfirst((string)$row['status'])) ?></td><td><a class="btn btn-ghost btn-sm" href="delivery_notes.php?view=<?= (int)$row['id'] ?>"><i class="fas fa-eye"></i> View / Print</a></td></tr><?php endforeach; ?><?php if (!$orders): ?><tr><td colspan="5">No orders available to create delivery notes.</td></tr><?php endif; ?></tbody></table></div></div>
    <?php endif; ?>
</section>
<style>
.delivery-note-header{display:flex;justify-content:space-between;gap:24px;border-bottom:2px solid var(--border);padding-bottom:20px;margin-bottom:24px}.delivery-note-header h1{margin:0}.delivery-note-client{margin:20px 0;padding:16px;background:var(--surface-alt);border-radius:8px}.delivery-note-signatures{display:grid;grid-template-columns:1fr 1fr;gap:60px;margin-top:90px}.delivery-note-signatures img{display:block;max-width:180px;max-height:65px;object-fit:contain}.delivery-note-signatures hr{margin-top:30px}@media print{.delivery-note-print{box-shadow:none;border:0}.delivery-note-header{color:#111}.delivery-note-client{background:none;border:1px solid #ccc}}
</style>
<?php include __DIR__ . '/includes/footer.php'; ?>
