<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
$pdo = getDB();
$cfg = getSettings();
$companyLogo = trim((string)($cfg['company_logo'] ?? ''));
if ($companyLogo === '' || !is_file(__DIR__ . '/' . ltrim($companyLogo, '/'))) $companyLogo = 'assets/img/logo.png';
$pageTitle = 'Delivery Notes';
$orderId = (int)($_GET['view'] ?? 0);
if (!function_exists('h')) { function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); } }
$pdo->exec("CREATE TABLE IF NOT EXISTS delivery_notes (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, order_id INT UNSIGNED NOT NULL UNIQUE, deleted_at DATETIME DEFAULT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_delivery_notes_visible (deleted_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec('INSERT IGNORE INTO delivery_notes (order_id) SELECT id FROM orders');
$success = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_delete_delivery_notes') {
    $ids = array_values(array_unique(array_filter(array_map('intval', $_POST['delivery_note_ids'] ?? []), static fn($id) => $id > 0)));
    if (!$ids) $error = 'Select at least one delivery note.';
    else {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("UPDATE delivery_notes SET deleted_at=NOW() WHERE id IN ($marks) AND deleted_at IS NULL");
        $stmt->execute($ids);
        header('Location: delivery_notes.php?deleted=' . $stmt->rowCount()); exit;
    }
}
$orders = $pdo->query("SELECT dn.id AS note_id,o.id,o.order_number,o.order_date,o.status,c.name AS client_name,c.company,c.phone,c.address FROM delivery_notes dn JOIN orders o ON o.id=dn.order_id JOIN clients c ON c.id=o.client_id WHERE dn.deleted_at IS NULL ORDER BY o.created_at DESC,o.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$order = null;
$items = [];
if ($orderId > 0) {
    $stmt = $pdo->prepare("SELECT o.*,c.name AS client_name,c.company,c.phone,c.email,c.address FROM delivery_notes dn JOIN orders o ON o.id=dn.order_id JOIN clients c ON c.id=o.client_id WHERE o.id=? AND dn.deleted_at IS NULL LIMIT 1");
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
            <img class="delivery-note-watermark" src="<?= h($companyLogo) ?>" alt="" aria-hidden="true">
            <header class="delivery-note-header"><div class="delivery-note-brand"><img src="<?= h($companyLogo) ?>" alt="<?= h($cfg['company_name'] ?? 'Company') ?> logo"><div><h2><?= h($cfg['company_name'] ?? 'Company') ?></h2><?php if (!empty($cfg['company_tagline'])): ?><div class="delivery-note-tagline"><?= h($cfg['company_tagline']) ?></div><?php endif; ?><?php if (!empty($cfg['company_address'])): ?><div><?= nl2br(h($cfg['company_address'])) ?></div><?php endif; ?><?php if (!empty($cfg['company_phone'])): ?><div><?= h($cfg['company_phone']) ?></div><?php endif; ?><?php if (!empty($cfg['company_email'])): ?><div><?= h($cfg['company_email']) ?></div><?php endif; ?><?php if (!empty($cfg['company_tin'])): ?><div>TIN: <?= h($cfg['company_tin']) ?></div><?php endif; ?></div></div><div class="delivery-note-title"><h1>DELIVERY NOTE</h1><strong><?= h($order['order_number']) ?></strong><p>Date: <?= h(date('d M Y', strtotime((string)$order['order_date']))) ?></p></div></header>
            <section class="delivery-note-client"><strong>Deliver to</strong><br><?= h($order['client_name']) ?><?= !empty($order['company']) ? '<br>'.h($order['company']) : '' ?><?= !empty($order['phone']) ? '<br>'.h($order['phone']) : '' ?><?= !empty($order['address']) ? '<br>'.nl2br(h($order['address'])) : '' ?></section>
            <table class="data-table"><thead><tr><th>#</th><th>Description</th><th>Quantity</th><th>Received</th></tr></thead><tbody><?php foreach ($items as $i => $item): ?><tr><td><?= $i + 1 ?></td><td><?= h($item['description']) ?></td><td><?= number_format((float)$item['quantity'], 2) ?></td><td style="min-width:160px"></td></tr><?php endforeach; ?><?php if (!$items): ?><tr><td colspan="4">No items on this order.</td></tr><?php endif; ?></tbody></table>
            <div class="delivery-note-signatures"><div><?php if (!empty($signatory['profile_signature_data'])): ?><img src="<?= h($signatory['profile_signature_data']) ?>" alt="Authorized signature"><?php endif; ?><hr><strong><?= h($signatory['authorized_name'] ?? '') ?></strong><div>Authorized by</div></div><div><hr><strong>Recipient name & signature</strong><div>Date received: __________________</div></div></div>
        </article>
    <?php else: ?>
        <div class="section-header"><span class="section-title">Delivery Notes</span></div>
        <?php if (isset($_GET['deleted'])): ?><div class="settings-alert settings-alert-success">Delivery note(s) removed. The related order and invoice data were kept.</div><?php endif; ?>
        <?php if ($error): ?><div class="settings-alert settings-alert-danger"><?= h($error) ?></div><?php endif; ?>
        <div class="card"><div class="bulk-actions" style="padding:12px 16px"><button class="btn btn-danger btn-sm" type="submit" form="bulkDeleteDeliveryNotes"><i class="fas fa-trash"></i> Delete selected</button></div><form method="post" id="bulkDeleteDeliveryNotes"><input type="hidden" name="action" value="bulk_delete_delivery_notes"></form><div class="table-wrap"><table class="data-table" data-client-pagination data-date-column="3"><thead><tr><th><input type="checkbox" id="select-all-delivery-notes" aria-label="Select all delivery notes"></th><th>Order #</th><th>Client</th><th>Order date</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach ($orders as $row): ?><tr><td><input type="checkbox" form="bulkDeleteDeliveryNotes" name="delivery_note_ids[]" class="delivery-note-select" value="<?= (int)$row['note_id'] ?>" aria-label="Select delivery note for order <?= h($row['order_number']) ?>"></td><td><?= h($row['order_number']) ?></td><td><?= h($row['client_name']) ?></td><td><?= h(date('d M Y', strtotime((string)$row['order_date']))) ?></td><td><?= h(ucfirst((string)$row['status'])) ?></td><td><a class="btn btn-ghost btn-sm" href="delivery_notes.php?view=<?= (int)$row['id'] ?>"><i class="fas fa-eye"></i> View / Print</a></td></tr><?php endforeach; ?><?php if (!$orders): ?><tr><td colspan="6">No delivery notes available.</td></tr><?php endif; ?></tbody></table></div></div>
    <?php endif; ?>
</section>
<style>
.delivery-note-print{position:relative;overflow:hidden}.delivery-note-watermark{position:absolute;z-index:0;top:50%;left:50%;width:min(62%,420px);height:auto;max-height:55%;object-fit:contain;transform:translate(-50%,-50%);opacity:.055;pointer-events:none}.delivery-note-print>:not(.delivery-note-watermark){position:relative;z-index:1}.delivery-note-header{display:flex;justify-content:space-between;align-items:flex-start;gap:24px;border-bottom:3px solid var(--navy);padding-bottom:20px;margin-bottom:24px}.delivery-note-header h1{margin:0;color:var(--navy);letter-spacing:.06em}.delivery-note-header h2{margin:0 0 6px;color:var(--navy)}.delivery-note-brand{display:flex;align-items:flex-start;gap:16px}.delivery-note-brand img{width:92px;height:76px;object-fit:contain}.delivery-note-tagline{margin:-3px 0 5px;color:var(--text-muted);font-size:.86rem;font-style:italic}.delivery-note-title{text-align:right}.delivery-note-title strong{display:inline-block;margin-top:8px;padding:6px 12px;background:var(--navy);color:white;border-radius:4px}.delivery-note-client{margin:20px 0;padding:16px;background:var(--surface-alt);border-left:4px solid var(--gold);border-radius:4px}.delivery-note-signatures{display:grid;grid-template-columns:1fr 1fr;gap:60px;margin-top:90px}.delivery-note-signatures img{display:block;max-width:180px;max-height:65px;object-fit:contain}.delivery-note-signatures hr{margin-top:30px}@media(max-width:640px){.delivery-note-header{flex-direction:column}.delivery-note-title{text-align:left}}@media print{.delivery-note-print{box-shadow:none;border:0}.delivery-note-header{color:#111}.delivery-note-client{background:none;border:1px solid #ccc}}
</style>
<script>document.getElementById('select-all-delivery-notes')?.addEventListener('change',e=>document.querySelectorAll('.delivery-note-select').forEach(box=>box.checked=e.target.checked));document.getElementById('bulkDeleteDeliveryNotes')?.addEventListener('submit',async e=>{e.preventDefault();if(!document.querySelector('.delivery-note-select:checked'))return showToast('Select at least one delivery note.','error');if(!await confirmModal('Remove the selected delivery notes? Related orders and invoices will be kept.',{title:'Delete delivery notes'}))return;e.currentTarget.submit();});</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
