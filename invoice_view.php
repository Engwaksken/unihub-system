<?php
require_once 'config/db.php';

$cfg = getSettings();
$pdo = getDB();

if (!function_exists('jsonResponse')) {
    function jsonResponse(array $data, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json');
        }
        echo json_encode($data);
        exit;
    }
}

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('moneyFmt')) {
    function moneyFmt($amount, $symbol = 'UGX '): string
    {
        return $symbol . number_format((float)$amount, 2);
    }
}

function fetchInvoice(PDO $pdo, int $invoiceId): ?array
{
    $stmt = $pdo->prepare("
        SELECT i.*, o.id AS order_id, o.order_number, o.discount_type, o.discount_value,
               o.notes AS order_notes, o.client_id,
               c.name AS client_name, c.email AS client_email, c.phone AS client_phone,
               c.address AS client_address, c.company AS client_company, c.tin AS client_tin
        FROM invoices i
        JOIN orders o ON o.id = i.order_id
        JOIN clients c ON c.id = o.client_id
        WHERE i.id = ? LIMIT 1
    ");
    $stmt->execute([$invoiceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function fetchInvoiceItems(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare("
        SELECT oi.*, s.name AS service_name
        FROM order_items oi
        LEFT JOIN services s ON s.id = oi.service_id
        WHERE oi.order_id = ? ORDER BY oi.id ASC
    ");
    $stmt->execute([$orderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchInvoiceReceipts(PDO $pdo, int $invoiceId): array
{
    $stmt = $pdo->prepare("SELECT * FROM receipts WHERE invoice_id = ? ORDER BY issued_at DESC, id DESC");
    $stmt->execute([$invoiceId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function invoiceHasReceipts(PDO $pdo, int $invoiceId): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM receipts WHERE invoice_id = ?");
    $stmt->execute([$invoiceId]);
    return (int)$stmt->fetchColumn() > 0;
}

function recalcOrderStatusAfterInvoiceRemoval(PDO $pdo, int $orderId): void
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE order_id = ?");
    $stmt->execute([$orderId]);
    $remaining = (int)$stmt->fetchColumn();
    $newStatus = $remaining > 0 ? 'invoiced' : 'pending';
    $up = $pdo->prepare("UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?");
    $up->execute([$newStatus, $orderId]);
}

/* POST actions */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $data['action'] ?? '';
    $id     = (int)($data['id'] ?? 0);

    if ($action === 'bulk_delete_invoices') {
        $ids = array_values(array_unique(array_filter(array_map('intval', $data['ids'] ?? []), static fn($value) => $value > 0)));
        if (!$ids) jsonResponse(['success'=>false,'error'=>'Select at least one invoice.'],400);
        $invoices = [];
        foreach ($ids as $invoiceId) {
            $row = fetchInvoice($pdo, $invoiceId);
            if (!$row) jsonResponse(['success'=>false,'error'=>'One or more selected invoices no longer exist.'],404);
            if (invoiceHasReceipts($pdo, $invoiceId) || strtolower((string)$row['status']) === 'paid') jsonResponse(['success'=>false,'error'=>'Paid invoices or invoices with linked receipts cannot be deleted. Remove them from the selection.'],409);
            $invoices[] = $row;
        }
        try {
            $pdo->beginTransaction();
            $delete = $pdo->prepare('DELETE FROM invoices WHERE id=?');
            foreach ($invoices as $row) $delete->execute([(int)$row['id']]);
            foreach (array_unique(array_map(static fn($row) => (int)$row['order_id'], $invoices)) as $orderId) recalcOrderStatusAfterInvoiceRemoval($pdo, $orderId);
            $pdo->commit();
            jsonResponse(['success'=>true,'message'=>count($ids).' invoice(s) deleted.']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['success'=>false,'error'=>'Unable to delete selected invoices.'],500);
        }
    }

    if ($id <= 0) jsonResponse(['success' => false, 'error' => 'Invalid invoice id'], 400);

    $invoice = fetchInvoice($pdo, $id);
    if (!$invoice) jsonResponse(['success' => false, 'error' => 'Invoice not found'], 404);

    if ($action === 'void_invoice') {
        if (strtolower((string)$invoice['status']) === 'paid') {
            jsonResponse(['success' => false, 'error' => 'Paid invoices cannot be voided. Reverse payment first.'], 409);
        }
        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE invoices SET status='void', updated_at=NOW() WHERE id=?")->execute([$id]);
            recalcOrderStatusAfterInvoiceRemoval($pdo, (int)$invoice['order_id']);
            $pdo->commit();
            jsonResponse(['success' => true, 'message' => 'Invoice voided successfully']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    if ($action === 'delete_invoice') {
        if (invoiceHasReceipts($pdo, $id)) {
            jsonResponse(['success' => false, 'error' => 'This invoice cannot be deleted because payment receipt(s) are linked to it.'], 409);
        }
        if (strtolower((string)$invoice['status']) === 'paid') {
            jsonResponse(['success' => false, 'error' => 'Paid invoices cannot be deleted. Reverse payment first.'], 409);
        }
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM invoices WHERE id=?")->execute([$id]);
            recalcOrderStatusAfterInvoiceRemoval($pdo, (int)$invoice['order_id']);
            $pdo->commit();
            jsonResponse(['success' => true, 'message' => 'Invoice deleted successfully', 'redirect' => 'invoices.php']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    jsonResponse(['success' => false, 'error' => 'Invalid action'], 400);
}

/* Read invoice */
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: invoices.php'); exit; }

$inv = fetchInvoice($pdo, $id);
if (!$inv) { header('Location: invoices.php'); exit; }

$items    = fetchInvoiceItems($pdo, (int)$inv['order_id']);
$receipts = fetchInvoiceReceipts($pdo, $id);

$pageTitle = 'Invoice ' . $inv['invoice_number'];
include 'includes/header.php';

$currency    = $cfg['currency_symbol'] ?? 'UGX ';
$companyName = $cfg['company_name']    ?? 'Unihub Solutions Uganda';
$logoPath    = trim((string)($cfg['company_logo'] ?? ''));
if ($logoPath === '' || !is_file(__DIR__ . '/' . ltrim($logoPath, '/\\'))) $logoPath = 'assets/img/logo.png';
$status      = strtolower((string)$inv['status']);

$statusLabel = strtoupper($inv['status']);
$statusClass = match ($status) {
    'paid'    => 'uh-status-paid',
    'void'    => 'uh-status-void',
    'partial' => 'uh-status-partial',
    default   => 'uh-status-default',
};

$canDelete = !$receipts && $status !== 'paid';
$canVoid   = $status !== 'paid' && $status !== 'void';
?>

<!-- ───────────────────────────────────────────
     UNIHUB  .  INVOICE VIEW  .  invoice_view.php
     Brand palette: navy #1a2a72 . orange #fc7f10
──────────────────────────────────────────────── -->

<!-- Action bar -->
<div class="uh-actions no-print">
    <a href="invoices.php" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Back</a>
    <a href="orders.php?get=<?= (int)$inv['order_id'] ?>" class="btn btn-outline">View Order</a>
    <?php if (!empty($receipts)): ?>
        <a href="receipts.php" class="btn btn-outline">View Receipts</a>
    <?php endif; ?>
    <button class="btn btn-outline" onclick="window.print()"><i class="fas fa-print"></i> Print / PDF</button>
    <?php if ($canVoid): ?>
        <button class="btn btn-warning" onclick="voidInvoice(<?= (int)$inv['id'] ?>)">Void Invoice</button>
    <?php endif; ?>
    <?php if ($canDelete): ?>
        <button class="btn btn-danger" onclick="deleteInvoice(<?= (int)$inv['id'] ?>)">Delete Invoice</button>
    <?php endif; ?>
</div>

<!-- Invoice paper -->
<div class="uh-paper">
    <div class="uh-watermark" aria-hidden="true"><img src="<?= h($logoPath) ?>" alt=""></div>

    <!-- ── HEADER BAND ── -->
    <div class="uh-header">
        <div class="uh-brand">
            <img src="<?= h($logoPath) ?>" alt="<?= h($companyName) ?> Logo" class="uh-logo-landscape">
            <div class="uh-brand-detail">
                <?php if (!empty($cfg['company_address'])): ?><?= h($cfg['company_address']) ?><br><?php endif; ?>
                <?php if (!empty($cfg['company_phone'])): ?><?= h($cfg['company_phone']) ?><?php endif; ?><?php if (!empty($cfg['company_phone']) && !empty($cfg['company_email'])): ?> | <?php endif; ?><?php if (!empty($cfg['company_email'])): ?><?= h($cfg['company_email']) ?><?php endif; ?><br>
                <?php if (!empty($cfg['company_tin'])): ?>TIN: <?= h($cfg['company_tin']) ?><?php endif; ?>
            </div>
        </div>
        <div class="uh-doc-badge">INVOICE</div>
    </div>

    <!-- ── META BAR ── -->
    <div class="uh-meta-bar">
        <div class="uh-meta-item">
            <label>Invoice No.</label>
            <div class="val"><?= h($inv['invoice_number']) ?></div>
        </div>
        <div class="uh-meta-item">
            <label>Invoice Date</label>
            <div class="val"><?= !empty($inv['issued_date']) ? date('d M, Y', strtotime($inv['issued_date'])) : '-' ?></div>
        </div>
        <div class="uh-meta-item">
            <label>Due Date</label>
            <div class="val"><?= !empty($inv['due_date']) ? date('d M, Y', strtotime($inv['due_date'])) : '-' ?></div>
        </div>
        <div class="uh-meta-item">
            <label>Status</label>
            <div class="val">
                <span class="uh-status-badge <?= h($statusClass) ?>">
                    <?= h($statusLabel) ?>
                </span>
            </div>
        </div>
    </div>

    <!-- ── FROM / BILL TO ── -->
    <div class="uh-parties">
        <div class="uh-party">
            <div class="uh-party-label">From</div>
            <div class="uh-party-name"><?= h($companyName) ?></div>
        </div>
        <div class="uh-party-divider"></div>
        <div class="uh-party">
            <div class="uh-party-label">Bill To</div>
            <div class="uh-party-name"><?= h($inv['client_name']) ?></div>
            <div class="uh-party-detail">
                <?php if (!empty($inv['client_company'])): ?><?= h($inv['client_company']) ?><br><?php endif; ?>
                <?php if (!empty($inv['client_phone'])): ?><?= h($inv['client_phone']) ?><br><?php endif; ?>
                <?php if (!empty($inv['client_email'])): ?><?= h($inv['client_email']) ?><br><?php endif; ?>
                <?php if (!empty($inv['client_address'])): ?><?= nl2br(h($inv['client_address'])) ?><br><?php endif; ?>
                <?php if (!empty($inv['client_tin'])): ?>TIN: <?= h($inv['client_tin']) ?><?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── ITEMS TABLE ── -->
    <div class="uh-table-wrap">
        <table class="uh-table">
            <thead>
                <tr>
                    <th class="uh-col-num">#</th>
                    <th>Description</th>
                    <th class="r uh-col-qty">Qty</th>
                    <th class="r uh-col-money">Unit Price (<?= h(rtrim($currency)) ?>)</th>
                    <th class="r uh-col-vat">VAT %</th>
                    <th class="r uh-col-money">Total (<?= h(rtrim($currency)) ?>)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($items)): ?>
                    <?php foreach ($items as $k => $it): ?>
                        <?php
                            $qty  = (float)$it['quantity'];
                            $rate = (float)$it['unit_price'];
                            $vat  = (float)$it['tax_rate'];
                            $line = $qty * $rate;
                            $tax  = $line * ($vat / 100);
                            $desc = $it['description'] ?: ($it['service_name'] ?? 'Item');
                        ?>
                        <tr>
                            <td><?= $k + 1 ?></td>
                            <td><?= h($desc) ?></td>
                            <td class="r"><?= number_format($qty, 2) ?></td>
                            <td class="r"><?= number_format($rate, 2) ?></td>
                            <td class="r"><?= number_format($vat, 2) ?>%</td>
                            <td class="r"><?= number_format($line + $tax, 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="uh-empty-row">No invoice items found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- ── TOTALS ── -->
    <div class="uh-totals-section">
        <div class="uh-totals">
            <div class="uh-totals-row">
                <span class="lbl">Subtotal</span>
                <span class="amt"><?= moneyFmt($inv['subtotal'], $currency) ?></span>
            </div>
            <div class="uh-totals-row">
                <span class="lbl">Tax</span>
                <span class="amt"><?= moneyFmt($inv['tax_total'], $currency) ?></span>
            </div>
            <?php if ((float)$inv['discount_amount'] > 0): ?>
                <div class="uh-totals-row">
                    <span class="lbl">Discount</span>
                    <span class="amt">− <?= moneyFmt($inv['discount_amount'], $currency) ?></span>
                </div>
            <?php endif; ?>
            <div class="uh-totals-row grand">
                <span class="lbl">Total Due</span>
                <span class="amt"><?= moneyFmt($inv['total'], $currency) ?></span>
            </div>
        </div>
    </div>

    <!-- ── PAYMENT INSTRUCTIONS ── -->
    <div class="uh-payment">
        <div class="uh-section-label">Payment Instructions</div>
        <div class="uh-payment-detail">
            <?php if (!empty($cfg['bank_name'])): ?>
                <strong>Bank:</strong> <?= h($cfg['bank_name']) ?>
                <?php if (!empty($cfg['bank_account_name'])): ?> . Account Name: <?= h($cfg['bank_account_name']) ?><?php endif; ?><br>
            <?php endif; ?>
            <?php if (!empty($cfg['bank_account_number'])): ?>
                <strong>Account No:</strong> <?= h($cfg['bank_account_number']) ?><br>
            <?php endif; ?>
            <?php if (!empty($cfg['payment_notes'])): ?>
                <?= nl2br(h($cfg['payment_notes'])) ?>
            <?php else: ?>
                Please reference invoice number <strong><?= h($inv['invoice_number']) ?></strong> with all payments.
            <?php endif; ?>
        </div>
    </div>

    <!-- ── NOTES ── -->
    <?php if (!empty($inv['order_notes'])): ?>
        <div class="uh-notes">
            <div class="uh-section-label">Notes</div>
            <p><?= nl2br(h($inv['order_notes'])) ?></p>
        </div>
    <?php endif; ?>

    <!-- ── RECEIPT HISTORY ── -->
    <?php if (!empty($receipts)): ?>
        <div class="uh-receipts">
            <div class="uh-receipts-title">Payment Receipt History</div>
            <table>
                <thead>
                    <tr>
                        <th>Receipt No</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Reference</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($receipts as $rc): ?>
                        <tr>
                            <td><?= h($rc['receipt_number'] ?? ('RCPT-' . $rc['id'])) ?></td>
                            <td><?= moneyFmt($rc['amount'] ?? 0, $currency) ?></td>
                            <td><?= h($rc['payment_method'] ?? $rc['payment_type'] ?? '-') ?></td>
                            <td><?= h($rc['reference'] ?? '-') ?></td>
                            <td><?= !empty($rc['issued_at']) ? date('d M Y H:i', strtotime($rc['issued_at'])) : (!empty($rc['created_at']) ? date('d M Y H:i', strtotime($rc['created_at'])) : '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <!-- ── SIGNATURE ── -->
    <div class="uh-signature">
        <div class="uh-sig-box">
            <?php if (!empty($inv['signature_data']) && str_starts_with((string)$inv['signature_data'], 'data:image/')): ?>
                <img src="<?= h($inv['signature_data']) ?>" alt="Authorized signature" style="max-width:180px;max-height:60px;object-fit:contain">
            <?php elseif (!empty($inv['signature_path']) && is_file(__DIR__ . '/' . ltrim((string)$inv['signature_path'], '/'))): ?>
                <img src="<?= h($inv['signature_path']) ?>" alt="Authorized signature" style="max-width:180px;max-height:60px;object-fit:contain">
            <?php else: ?><div class="uh-sig-spacer"></div><?php endif; ?>
            <div class="uh-sig-line"><?= h($inv['authorized_name'] ?: 'Authorized Signature') ?></div>
            <div class="uh-sig-for">For: <?= h($companyName) ?></div>
        </div>
    </div>

    <!-- ── FOOTER BAND ── -->
    <div class="uh-footer">
        <span class="left"><?= h($companyName) ?></span>
        <span class="right"><?= h($cfg['company_website'] ?? 'www.unihubug.com') ?> . <?= h($cfg['company_phone'] ?? '+256 700 000 000') ?></span>
    </div>

</div><!-- /uh-paper -->

<script>
async function voidInvoice(id) {
    if (!await confirmModal('Void this invoice? The related order will return to pending if no other invoice remains.', {title:'Void invoice'})) return;
    const res = await api('invoice_view.php?id=' + id, { method:'POST', body: JSON.stringify({ action:'void_invoice', id }) });
    if (res.success) { showToast(res.message || 'Invoice voided'); setTimeout(() => location.reload(), 700); }
    else showToast(res.error || 'Failed to void invoice', 'error');
}

async function deleteInvoice(id) {
    if (!await confirmModal('Delete this invoice? This action cannot be undone.', {title:'Delete invoice'})) return;
    const res = await api('invoice_view.php?id=' + id, { method:'POST', body: JSON.stringify({ action:'delete_invoice', id }) });
    if (res.success) { showToast(res.message || 'Invoice deleted'); setTimeout(() => { location.href = res.redirect || 'invoices.php'; }, 700); }
    else showToast(res.error || 'Failed to delete invoice', 'error');
}
</script>

<?php include 'includes/footer.php'; ?>
