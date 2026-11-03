<?php
require_once 'config/db.php';

$cfg = getSettings();
$pdo = getDB();

if (!function_exists('formatMoneySafe')) {
    function formatMoneySafe($amount, $symbol = 'UGX '): string
    {
        return $symbol . number_format((float)$amount, 2);
    }
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: receipts.php'); exit; }

$stmt = $pdo->prepare("
    SELECT r.*, i.invoice_number, i.total AS invoice_total,
           o.order_number, o.id AS order_id,
           c.name AS client_name, c.email AS client_email, c.phone AS client_phone,
           c.address AS client_address, c.company AS client_company, c.tin AS client_tin
    FROM receipts r
    INNER JOIN invoices i ON i.id = r.invoice_id
    INNER JOIN orders o   ON o.id = i.order_id
    INNER JOIN clients c  ON c.id = o.client_id
    WHERE r.id = ? LIMIT 1
");
$stmt->execute([$id]);
$rcp = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$rcp) { header('Location: receipts.php'); exit; }

$itemStmt = $pdo->prepare("
    SELECT oi.*, s.name AS service_name
    FROM order_items oi
    LEFT JOIN services s ON s.id = oi.service_id
    WHERE oi.order_id = ? ORDER BY oi.id ASC
");
$itemStmt->execute([(int)$rcp['order_id']]);
$items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Receipt ' . ($rcp['receipt_number'] ?? '');
include 'includes/header.php';

$currencySymbol = $cfg['currency_symbol'] ?? 'UGX ';
$companyName    = $cfg['company_name']    ?? 'Unihub Solutions Uganda';
$companyAddress = $cfg['company_address'] ?? '';
$companyPhone   = $cfg['company_phone']   ?? '+256 704 145 972 ';
$companyEmail   = $cfg['company_email']   ?? '';
$companyTin     = $cfg['company_tin']     ?? '';
$companyWebsite = $cfg['company_website'] ?? 'www.unihubug.com';
$logoPath       = 'assets/img/favicon.png';

$invoiceTotal = (float)($rcp['invoice_total'] ?? 0);
$amountPaid   = (float)($rcp['amount_paid']   ?? 0);

$computedSubtotal = 0.0;
$computedTax      = 0.0;
$computedGrand    = 0.0;

foreach ($items as $it) {
    $qty   = (float)($it['quantity']  ?? 0);
    $price = (float)($it['unit_price'] ?? 0);
    $rate  = (float)($it['tax_rate']   ?? 0);
    $line  = $qty * $price;
    $tax   = $line * $rate / 100;
    $computedSubtotal += $line;
    $computedTax      += $tax;
    $computedGrand    += ($line + $tax);
}

if ($computedGrand <= 0 && $invoiceTotal > 0) $computedGrand = $invoiceTotal;
$displayTotal = $invoiceTotal > 0 ? $invoiceTotal : $computedGrand;

$signaturePath  = trim((string)($rcp['signature_path']  ?? ''));
$authorizedName = trim((string)($rcp['authorized_name'] ?? ''));
$signedAt       = trim((string)($rcp['signed_at']       ?? ''));

$signatureExists = false;
$signatureUrl    = '';
if ($signaturePath !== '') {
    $localFile = __DIR__ . '/' . ltrim($signaturePath, '/');
    if (is_file($localFile)) {
        $signatureExists = true;
        $signatureUrl    = $signaturePath;
    }
}

$paymentDate = !empty($rcp['payment_date']) ? date('d F Y', strtotime($rcp['payment_date'])) : '-';
$paymentMethod = ucwords(str_replace('_', ' ', (string)($rcp['payment_method'] ?? '')));
?>


<!-- Action bar -->
<div class="uh-actions no-print">
    <a href="receipts.php" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Back</a>
    <button class="btn btn-outline" onclick="window.print()"><i class="fas fa-print"></i> Print Receipt</button>
</div>

<!-- Receipt paper -->
<div class="uh-paper">

    <!-- -- HEADER -- -->
    <div class="uh-header">
        <div class="uh-brand">
            <div class="uh-icon-box">
                <img src="<?= htmlspecialchars($logoPath) ?>" alt="<?= htmlspecialchars($companyName) ?> Logo" class="uh-logo-img">
            </div>
            <div class="uh-wordmark">
                <div class="uh-wordmark-main">
                    <span class="or">UNI</span><span class="wh">HUB</span>
                </div>
                <div class="uh-wordmark-sub">Solutions Uganda</div>
               
            </div>
        </div>
        <div class="uh-doc-badge-rcpt">RECEIPT</div>
    </div>

    <!-- -- PAID BAR -- -->
    <div class="uh-paid-bar">
      Payment Received - Thank you!
    </div>

    <!-- -- META BAR -- -->
    <div class="uh-meta-bar">
        <div class="uh-meta-item">
            <label>Receipt No.</label>
            <div class="val"><?= htmlspecialchars($rcp['receipt_number'] ?? '') ?></div>
        </div>
        <div class="uh-meta-item">
            <label>Invoice Ref.</label>
            <div class="val"><?= htmlspecialchars($rcp['invoice_number'] ?? '-') ?></div>
        </div>
        <div class="uh-meta-item">
            <label>Date Received</label>
            <div class="val"><?= htmlspecialchars($paymentDate) ?></div>
        </div>
        <div class="uh-meta-item">
            <label>Payment Method</label>
            <div class="val"><?= htmlspecialchars($paymentMethod) ?></div>
        </div>
    </div>

   
    <div class="uh-parties">
        <div class="uh-party">
            <div class="uh-party-label">Received By</div>
            <div class="uh-party-name"><?= htmlspecialchars($companyName) ?></div>
            <div class="uh-party-detail">
                <?php if ($companyAddress): ?><?= htmlspecialchars($companyAddress) ?><br><?php endif; ?>
                <?php if ($companyTin): ?>TIN: <?= htmlspecialchars($companyTin) ?><br><?php endif; ?>
                <?php if ($companyPhone): ?><?= htmlspecialchars($companyPhone) ?><?php endif; ?>
            </div>
        </div>
        <div class="uh-party-divider"></div>
        <div class="uh-party">
            <div class="uh-party-label">Received From</div>
            <div class="uh-party-name"><?= htmlspecialchars($rcp['client_name'] ?? '-') ?></div>
            <div class="uh-party-detail">
                <?php if (!empty($rcp['client_company'])): ?><?= htmlspecialchars($rcp['client_company']) ?><br><?php endif; ?>
                <?php if (!empty($rcp['client_phone'])): ?><?= htmlspecialchars($rcp['client_phone']) ?><br><?php endif; ?>
                <?php if (!empty($rcp['client_email'])): ?><?= htmlspecialchars($rcp['client_email']) ?><br><?php endif; ?>
                <?php if (!empty($rcp['client_address'])): ?><?= nl2br(htmlspecialchars($rcp['client_address'])) ?><br><?php endif; ?>
                <?php if (!empty($rcp['client_tin'])): ?>TIN: <?= htmlspecialchars($rcp['client_tin']) ?><?php endif; ?>
            </div>
        </div>
    </div>


    <div class="uh-table-wrap">
        <table class="uh-table">
            <thead>
                <tr>
                    <th class="uh-col-num">#</th>
                    <th>Description</th>
                    <th class="r uh-col-qty">Qty</th>
                    <th class="r uh-col-price">Price (<?= htmlspecialchars(rtrim($currencySymbol)) ?>)</th>
                    <th class="r uh-col-vat">VAT</th>
                    <th class="r uh-col-total">Total (<?= htmlspecialchars(rtrim($currencySymbol)) ?>)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($items)): ?>
                    <?php foreach ($items as $k => $it): ?>
                        <?php
                            $qty       = (float)($it['quantity']  ?? 0);
                            $unitPrice = (float)($it['unit_price'] ?? 0);
                            $taxRate   = (float)($it['tax_rate']   ?? 0);
                            $line      = $qty * $unitPrice;
                            $taxAmt    = $line * $taxRate / 100;
                            $lineTotal = $line + $taxAmt;
                            $desc      = trim((string)($it['description'] ?? ''));
                            if ($desc === '') $desc = trim((string)($it['service_name'] ?? 'Item'));
                        ?>
                        <tr>
                            <td><?= (int)$k + 1 ?></td>
                            <td><?= htmlspecialchars($desc) ?></td>
                            <td class="r"><?= rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.') ?></td>
                            <td class="r"><?= number_format($unitPrice, 2) ?></td>
                            <td class="r"><?= number_format($taxRate, 2) ?>%</td>
                            <td class="r"><?= number_format($lineTotal, 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td>1</td>
                        <td>Payment for Invoice <?= htmlspecialchars($rcp['invoice_number'] ?? '-') ?></td>
                        <td class="r">1</td>
                        <td class="r"><?= number_format($displayTotal, 2) ?></td>
                        <td class="r">0.00%</td>
                        <td class="r"><?= number_format($displayTotal, 2) ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- -- TOTALS -- -->
    <div class="uh-totals-section">
        <div class="uh-totals">
            <div class="uh-totals-row">
                <span class="lbl">Subtotal</span>
                <span class="amt"><?= formatMoneySafe($computedSubtotal > 0 ? $computedSubtotal : $displayTotal, $currencySymbol) ?></span>
            </div>
            <div class="uh-totals-row">
                <span class="lbl">Tax</span>
                <span class="amt"><?= formatMoneySafe($computedTax, $currencySymbol) ?></span>
            </div>
            <div class="uh-totals-row grand">
                <span class="lbl">Total</span>
                <span class="amt"><?= formatMoneySafe($displayTotal, $currencySymbol) ?></span>
            </div>
            <div class="uh-totals-row paid-row">
                <span class="lbl">Amount Paid</span>
                <span class="amt"><?= formatMoneySafe($amountPaid, $currencySymbol) ?></span>
            </div>
        </div>
    </div>

    <!-- -- SIGNATURE + STAMP -- -->
    <div class="uh-sig-stamp">

        <!-- Authorised Signature -->
        <div>
            <div class="uh-sig-area-label">Authorised Signature</div>
            <div class="uh-sig-image-area">
                <?php if ($signatureExists): ?>
                    <img src="<?= htmlspecialchars($signatureUrl) ?>" alt="Authorised Signature">
                <?php else: ?>
                    <span class="uh-sig-placeholder">No authorised signature on file</span>
                <?php endif; ?>
            </div>
            <div class="uh-sig-line-box">
                <div class="uh-sig-underline">Authorised Signatory</div>
                <div class="uh-sig-name"><?= $authorizedName !== '' ? htmlspecialchars($authorizedName) : htmlspecialchars($companyName) ?></div>
                <?php if ($signedAt !== ''): ?>
                    <div class="uh-sig-date">Signed: <?= htmlspecialchars(date('d M Y, h:i A', strtotime($signedAt))) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Official Stamp - inline SVG from unihub_stamp.svg (round seal variant, scaled) -->
        <div class="uh-stamp-area">
            <div class="uh-stamp-label">Official Stamp</div>
            <div class="uh-stamp-svg-wrap">
                <!--
                    Inline round seal extracted from unihub_stamp.svg
                    cx=297.64 cy=168 r=120  ?  scaled to 150×150 via viewBox
                -->
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="147 48 302 242" width="150" height="150">
                    <defs>
                        <path id="sTopArc" d="M 147.64,60 A 107.64,107.64 0 0,1 362.92,60"/>
                        <path id="sBotArc" d="M 158,185 A 107.64,107.64 0 0,0 352.56,185"/>
                    </defs>
                    <!-- Outer navy ring -->
                    <circle cx="297.64" cy="168" r="120" fill="#1a2a72"/>
                    <!-- White gap -->
                    <circle cx="297.64" cy="168" r="112" fill="#ffffff"/>
                    <!-- Navy inner ring -->
                    <circle cx="297.64" cy="168" r="108" fill="#1a2a72"/>
                    <!-- White working area -->
                    <circle cx="297.64" cy="168" r="96" fill="#ffffff"/>
                    <!-- Dashed inner guide -->
                    <circle cx="297.64" cy="168" r="84" fill="none" stroke="#1a2a72" stroke-width="1" stroke-dasharray="4,3" opacity="0.3"/>
                    <!-- Top arc text -->
                    <text font-family="Arial Narrow,Arial,sans-serif" font-weight="700" font-size="11.5" fill="#1a2a72" letter-spacing="4">
                        <textPath href="#sTopArc" startOffset="6%">UNIHUB SOLUTIONS UGANDA</textPath>
                    </text>
                    <!-- Bottom arc text -->
                    <text font-family="Arial,sans-serif" font-weight="700" font-size="10" fill="#fc7f10" letter-spacing="5.5">
                        <textPath href="#sBotArc" startOffset="8%">DIGITAL ACCESS FOR ALL</textPath>
                    </text>
                    <!-- Orange icon block -->
                    <rect x="271" y="134" width="52" height="52" rx="7" fill="#fc7f10"/>
                    <!-- U icon -->
                    <path d="M281 143 L281 168 Q281 180 297 180 Q313 180 313 168 L313 143 L305 143 L305 168 Q305 173 297 173 Q289 173 289 168 L289 143 Z" fill="#ffffff"/>
                    <!-- UNIHUB text -->
                    <text x="297.64" y="207" font-family="Arial Narrow,Arial,sans-serif" font-weight="bold" font-size="22" fill="#1a2a72" text-anchor="middle" letter-spacing="1">UNIHUB</text>
                    <text x="297.64" y="220" font-family="Arial,sans-serif" font-weight="700" font-size="8" fill="#64748b" text-anchor="middle" letter-spacing="3">SOLUTIONS</text>
                    <!-- Stars -->
                    <text x="175" y="172" font-size="10" fill="#fc7f10" text-anchor="middle">?</text>
                    <text x="420" y="172" font-size="10" fill="#fc7f10" text-anchor="middle">?</text>
                </svg>
            </div>
        </div>

    </div><!-- /sig-stamp -->

    <!-- -- FOOTER BAND -- -->
    <div class="uh-footer">
        <span class="left">Official Receipt - <?= htmlspecialchars($companyName) ?> . Keep for records</span>
        <span class="right"><?= htmlspecialchars($companyWebsite) ?> . <?= htmlspecialchars($companyPhone) ?></span>
    </div>

</div><!-- /uh-paper -->

<?php include 'includes/footer.php'; ?>