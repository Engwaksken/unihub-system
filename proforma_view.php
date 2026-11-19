<?php
require_once 'config/db.php';

$cfg = getSettings();
$pdo = getDB();

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

/* Load the order this proforma is drawn from */
$orderId = (int)($_GET['order_id'] ?? 0);
if ($orderId <= 0) { header('Location: orders.php'); exit; }

$stmt = $pdo->prepare("
    SELECT o.*,
           c.name AS client_name, c.email AS client_email, c.phone AS client_phone,
           c.address AS client_address, c.company AS client_company, c.tin AS client_tin,
           (SELECT i.id FROM invoices i WHERE i.order_id = o.id ORDER BY i.id DESC LIMIT 1) AS invoice_id
    FROM orders o
    JOIN clients c ON c.id = o.client_id
    WHERE o.id = ? LIMIT 1
");
$stmt->execute([$orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$order) { header('Location: orders.php'); exit; }

$itemStmt = $pdo->prepare("
    SELECT oi.*, s.name AS service_name
    FROM order_items oi
    LEFT JOIN services s ON s.id = oi.service_id
    WHERE oi.order_id = ? ORDER BY oi.id ASC
");
$itemStmt->execute([$orderId]);
$items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

/* Totals: same formula as Order::generateInvoice() so the final invoice matches */
$subtotal = 0.0;
$taxTotal = 0.0;
foreach ($items as $it) {
    $line = (float)$it['quantity'] * (float)$it['unit_price'];
    $subtotal += $line;
    $taxTotal += $line * ((float)$it['tax_rate'] / 100);
}
$discount = ($order['discount_type'] ?? 'fixed') === 'percent'
    ? $subtotal * ((float)$order['discount_value'] / 100)
    : (float)$order['discount_value'];
$total = $subtotal + $taxTotal - $discount;

$proformaPrefix = trim((string)($cfg['proforma_prefix'] ?? '')) ?: 'PF';
$proformaNumber = $proformaPrefix . '-' . $order['order_number'];
$proformaDate   = $order['order_date'] ?: date('Y-m-d');
$terms          = (int)($cfg['payment_terms'] ?? 0);
$validUntil     = $order['due_date'] ?: date('Y-m-d', strtotime($proformaDate . ' +' . max($terms, 30) . ' days'));

/* Signatory: the logged-in user's saved profile signature, if any */
ensureUserSignatureProfile($pdo);
$signatory = ['authorized_name' => '', 'profile_signature_data' => ''];
if (!empty($_SESSION['user_id'])) {
    $sigStmt = $pdo->prepare('SELECT authorized_name, profile_signature_data FROM users WHERE id = ? LIMIT 1');
    $sigStmt->execute([(int)$_SESSION['user_id']]);
    $signatory = $sigStmt->fetch(PDO::FETCH_ASSOC) ?: $signatory;
}

$canConvert = empty($order['invoice_id']) && in_array($order['status'], ['pending', 'draft'], true);

$pageTitle = 'Proforma ' . $proformaNumber;
include 'includes/header.php';

$currency    = $cfg['currency_symbol'] ?? 'UGX ';
$companyName = $cfg['company_name']    ?? 'Unihub Solutions Uganda';
$logoPath    = trim((string)($cfg['company_logo'] ?? ''));
if ($logoPath === '' || !is_file(__DIR__ . '/' . ltrim($logoPath, '/\\'))) $logoPath = 'assets/img/logo.png';
$bankAccName = $cfg['account_name'] ?? ($cfg['bank_account_name'] ?? '');
$bankAccNo   = $cfg['account_number'] ?? ($cfg['bank_account_number'] ?? '');
?>

<!-- Action bar -->
<div class="uh-actions no-print">
    <a href="orders.php" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Back to Orders</a>
    <button class="btn btn-outline" onclick="window.print()"><i class="fas fa-print"></i> Print / PDF</button>
    <?php if ($canConvert): ?>
        <button class="btn btn-primary" onclick="convertToInvoice(<?= (int)$orderId ?>)">
            <i class="fas fa-file-circle-plus"></i> Convert to Invoice
        </button>
    <?php elseif (!empty($order['invoice_id'])): ?>
        <a href="invoice_view.php?id=<?= (int)$order['invoice_id'] ?>" class="btn btn-outline">
            <i class="fas fa-file-invoice"></i> View Invoice
        </a>
    <?php endif; ?>
</div>

<!-- Proforma paper -->
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
        <div class="uh-doc-badge">PROFORMA INVOICE</div>
    </div>

    <!-- ── META BAR ── -->
    <div class="uh-meta-bar">
        <div class="uh-meta-item">
            <label>Proforma No.</label>
            <div class="val"><?= h($proformaNumber) ?></div>
        </div>
        <div class="uh-meta-item">
            <label>Date</label>
            <div class="val"><?= date('d M, Y', strtotime($proformaDate)) ?></div>
        </div>
        <div class="uh-meta-item">
            <label>Valid Until</label>
            <div class="val"><?= date('d M, Y', strtotime($validUntil)) ?></div>
        </div>
        <div class="uh-meta-item">
            <label>Order Ref.</label>
            <div class="val"><?= h($order['order_number']) ?></div>
        </div>
    </div>

    <!-- ── FROM / BILL TO ── -->
    <div class="uh-parties">
        <div class="uh-party">
            <div class="uh-party-label">From</div>
            <div class="uh-party-name"><?= h($companyName) ?></div>
            <div class="uh-party-detail">
                <?php if (!empty($cfg['bank_name'])): ?><strong>Bank:</strong> <?= h($cfg['bank_name']) ?><br><?php endif; ?>
                <?php if (!empty($cfg['bank_branch'])): ?><strong>Branch:</strong> <?= h($cfg['bank_branch']) ?><br><?php endif; ?>
                <?php if ($bankAccName !== ''): ?><strong>Account Name:</strong> <?= h($bankAccName) ?><br><?php endif; ?>
                <?php if ($bankAccNo !== ''): ?><strong>Account No:</strong> <?= h($bankAccNo) ?><?php endif; ?>
            </div>
        </div>
        <div class="uh-party-divider"></div>
        <div class="uh-party">
            <div class="uh-party-label">Bill To</div>
            <div class="uh-party-name"><?= h($order['client_name']) ?></div>
            <div class="uh-party-detail">
                <?php if (!empty($order['client_company'])): ?><?= h($order['client_company']) ?><br><?php endif; ?>
                <?php if (!empty($order['client_phone'])): ?><?= h($order['client_phone']) ?><br><?php endif; ?>
                <?php if (!empty($order['client_email'])): ?><?= h($order['client_email']) ?><br><?php endif; ?>
                <?php if (!empty($order['client_address'])): ?><?= nl2br(h($order['client_address'])) ?><br><?php endif; ?>
                <?php if (!empty($order['client_tin'])): ?>TIN: <?= h($order['client_tin']) ?><?php endif; ?>
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
                        <td colspan="6" class="uh-empty-row">This order has no items yet.</td>
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
                <span class="amt"><?= moneyFmt($subtotal, $currency) ?></span>
            </div>
            <div class="uh-totals-row">
                <span class="lbl">Tax</span>
                <span class="amt"><?= moneyFmt($taxTotal, $currency) ?></span>
            </div>
            <?php if ($discount > 0): ?>
                <div class="uh-totals-row">
                    <span class="lbl">Discount</span>
                    <span class="amt">− <?= moneyFmt($discount, $currency) ?></span>
                </div>
            <?php endif; ?>
            <div class="uh-totals-row grand">
                <span class="lbl">Total</span>
                <span class="amt"><?= moneyFmt($total, $currency) ?></span>
            </div>
        </div>
    </div>

    <!-- ── TERMS ── -->
    <div class="uh-payment">
        <div class="uh-section-label">Terms</div>
        <div class="uh-payment-detail">
            This is a proforma invoice issued for quotation and advance-payment purposes. It is not a tax invoice.
            A final invoice will be issued once the order is confirmed.
            Prices are valid until <strong><?= date('d M, Y', strtotime($validUntil)) ?></strong>.
            Please quote <strong><?= h($proformaNumber) ?></strong> with any payment.
        </div>
    </div>

    <!-- ── NOTES ── -->
    <?php if (!empty($order['notes'])): ?>
        <div class="uh-notes">
            <div class="uh-section-label">Notes</div>
            <p><?= nl2br(h($order['notes'])) ?></p>
        </div>
    <?php endif; ?>

    <!-- ── SIGNATURE ── -->
    <div class="uh-signature">
        <div class="uh-sig-box">
            <?php if (!empty($signatory['profile_signature_data']) && str_starts_with((string)$signatory['profile_signature_data'], 'data:image/')): ?>
                <img src="<?= h($signatory['profile_signature_data']) ?>" alt="Authorized signature" style="max-width:180px;max-height:60px;object-fit:contain">
            <?php else: ?><div class="uh-sig-spacer"></div><?php endif; ?>
            <div class="uh-sig-line"><?= h(($signatory['authorized_name'] ?? '') ?: 'Authorized Signature') ?></div>
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
async function convertToInvoice(orderId) {
    if (!await confirmModal('Convert this proforma into a final invoice? The order will be marked as invoiced.', {title:'Convert to invoice',confirmText:'Convert',danger:false})) return;
    const res = await api('orders.php', { method:'POST', body: JSON.stringify({ action:'generate_invoice', order_id: orderId }) });
    if (res.success) { showToast('Invoice generated!'); setTimeout(() => location.href = 'invoice_view.php?id=' + res.invoice_id, 800); }
    else showToast(res.error || 'Error generating invoice', 'error');
}
</script>

<?php include 'includes/footer.php'; ?>
