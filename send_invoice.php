<?php
declare(strict_types=1);

require_once 'config/db.php';
require_once 'email-function.php';

header('Content-Type: application/json; charset=utf-8');

if (!function_exists('jsonResponse')) {
    function jsonResponse(array $payload, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($payload);
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
    function moneyFmt($amount, string $symbol = 'UGX '): string
    {
        return $symbol . number_format((float)$amount, 2);
    }
}

if (!function_exists('assetDataUri')) {
    function assetDataUri(string $absolutePath): string
    {
        if (!is_file($absolutePath)) {
            return '';
        }

        $ext = strtolower((string)pathinfo($absolutePath, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'webp'        => 'image/webp',
            'gif'         => 'image/gif',
            'svg'         => 'image/svg+xml',
            default       => 'image/png',
        };

        $data = file_get_contents($absolutePath);
        if ($data === false) {
            return '';
        }

        return 'data:' . $mime . ';base64,' . base64_encode($data);
    }
}

if (!function_exists('loadSharedCssForPdf')) {
    function loadSharedCssForPdf(): string
    {
        /*
         * Reuse the same system stylesheet used by invoice_view.php / receipt_view.php.
         * Dompdf is more reliable when CSS is embedded, so the file is loaded here
         * instead of linked with <link>.
         */
        $paths = [
            __DIR__ . '/assets/css/style.css',
            __DIR__ . '/style.css',
        ];

        foreach ($paths as $path) {
            if (is_file($path)) {
                $css = file_get_contents($path);
                if ($css !== false) {
                    return $css;
                }
            }
        }

        return '';
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse([
            'success' => false,
            'error'   => 'Method not allowed'
        ], 405);
    }

    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        $data = $_POST ?: [];
    }

    $invoiceId = (int)($data['invoice_id'] ?? 0);
    if ($invoiceId <= 0) {
        throw new Exception('Invalid invoice ID.');
    }

    $pdo = getDB();
    $cfg = getSettings();

    $stmt = $pdo->prepare("
        SELECT 
            i.*,
            o.order_number,
            o.notes AS order_notes,
            o.client_id,
            c.name AS client_name,
            c.email AS client_email,
            c.phone AS client_phone,
            c.address AS client_address,
            c.company AS client_company,
            c.tin AS client_tin
        FROM invoices i
        INNER JOIN orders o ON o.id = i.order_id
        INNER JOIN clients c ON c.id = o.client_id
        WHERE i.id = ?
        LIMIT 1
    ");
    $stmt->execute([$invoiceId]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$inv) {
        throw new Exception('Invoice not found.');
    }

    if (empty($inv['client_email'])) {
        throw new Exception('Client email is missing.');
    }

    $itemsStmt = $pdo->prepare("
        SELECT 
            oi.*,
            s.name AS service_name
        FROM order_items oi
        LEFT JOIN services s ON s.id = oi.service_id
        WHERE oi.order_id = ?
        ORDER BY oi.id ASC
    ");
    $itemsStmt->execute([(int)$inv['order_id']]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $currencySymbol = (string)($cfg['currency_symbol'] ?? 'UGX ');
    $companyName    = (string)($cfg['company_name'] ?? 'Unihub Solutions Uganda');
    $companyTagline = trim((string)($cfg['company_tagline'] ?? ''));
    $brandPrimaryColor = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($cfg['brand_primary_color'] ?? '')) ? $cfg['brand_primary_color'] : '#1a2a72';
    $brandAccentColor = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($cfg['brand_accent_color'] ?? '')) ? $cfg['brand_accent_color'] : '#fc7f10';
    $companyAddress = (string)($cfg['company_address'] ?? '');
    $companyPhone   = (string)($cfg['company_phone'] ?? '');
    $companyEmail   = (string)($cfg['company_email'] ?? '');
    $companyTin     = (string)($cfg['company_tin'] ?? '');
    $companyWebsite = (string)($cfg['company_website'] ?? 'www.unihubug.com');

    $configuredLogo = trim((string)($cfg['company_logo'] ?? ''));
    $logoFile = $configuredLogo !== '' ? realpath(__DIR__ . '/' . ltrim($configuredLogo, '/\\')) : false;
    $assetsRoot = realpath(__DIR__ . '/assets/img');
    if (!$logoFile || !$assetsRoot || !str_starts_with($logoFile, $assetsRoot . DIRECTORY_SEPARATOR) || !is_file($logoFile)) $logoFile = __DIR__ . '/assets/img/logo.png';
    $logoDataUri = assetDataUri($logoFile);

    $status = strtolower((string)($inv['status'] ?? 'pending'));
    $statusLabel = strtoupper((string)($inv['status'] ?? 'PENDING'));

    $statusClass = match ($status) {
        'paid'      => 'uh-status-paid',
        'void'      => 'uh-status-void',
        'cancelled' => 'uh-status-void',
        'partial'   => 'uh-status-partial',
        default     => 'uh-status-unpaid',
    };

    $sharedCss = loadSharedCssForPdf();

    /*
     * PDF-only CSS keeps Dompdf stable and maps the same .uh-* class names used by
     * invoice_view.php / receipt_view.php. Keep these selectors in sync with style.css.
     */
    $pdfCss = <<<'CSS'
@page { margin: 24px; }

body {
    background: #ffffff;
    color: #1e293b;
    font-family: DejaVu Sans, Arial, sans-serif;
    font-size: 12px;
    margin: 0;
    padding: 0;
}

.uh-pdf-wrap {
    position: relative;
    background: #ffffff;
    padding: 0;
}

.uh-pdf-watermark {
    position: fixed;
    top: 22%;
    left: 14%;
    width: 460px;
    opacity: .045;
    z-index: 0;
}

.uh-pdf-content {
    position: relative;
    z-index: 1;
}

.uh-paper {
    width: 100%;
    max-width: none;
    margin: 0;
    background: #ffffff;
    box-shadow: none;
    overflow: hidden;
    font-family: DejaVu Sans, Arial, sans-serif;
    font-size: 12px;
    color: #1e293b;
}

.uh-header {
    background: #1a2a72;
    padding: 20px 30px;
}

.uh-header-table,
.uh-brand-table,
.uh-meta-table,
.uh-parties-table,
.uh-table,
.uh-totals-table,
.uh-payment-table,
.uh-footer-table {
    width: 100%;
    border-collapse: collapse;
}

.uh-brand-cell {
    width: 70%;
    vertical-align: middle;
}

.uh-doc-cell {
    width: 30%;
    text-align: right;
    vertical-align: middle;
}

.uh-logo-cell {
    width: 62px;
    vertical-align: middle;
}

.uh-logo-img {
    width: auto;
    max-width: 300px;
    height: 64px;
    object-fit: contain;
}

.uh-logo-fallback {
    width: 56px;
    height: 56px;
    background: #fc7f10;
    color: #ffffff;
    text-align: center;
    font-weight: bold;
    font-size: 24px;
    line-height: 56px;
    border-radius: 8px;
}

.uh-wordmark {
    padding-left: 12px;
    vertical-align: middle;
}

.uh-wordmark-main {
    font-family: DejaVu Sans, Arial, sans-serif;
    font-size: 21px;
    font-weight: 700;
    line-height: 1;
    color: #ffffff;
}

.uh-wordmark-main .or {
    color: #fc7f10;
}

.uh-wordmark-main .wh {
    color: #ffffff;
}

.uh-wordmark-sub {
    color: rgba(255,255,255,.68);
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 2px;
    text-transform: uppercase;
    padding-top: 3px;
}
.uh-company-tagline { color:rgba(255,255,255,.78);font-size:10px;font-style:italic;padding-top:4px; }
.uh-brand-detail { color:#64748b;font-size:10px;line-height:1.6; }

.uh-doc-badge,
.uh-doc-badge-invoice {
    display: inline-block;
    background: #fc7f10;
    border-radius: 5px;
    color: #ffffff;
    font-size: 16px;
    font-weight: 900;
    letter-spacing: 2px;
    padding: 8px 18px;
    text-transform: uppercase;
}

.uh-meta-bar {
    background: #e8ebf7;
    padding: 10px 30px;
}

.uh-meta-table td {
    width: 25%;
    padding-right: 10px;
    vertical-align: top;
}

.uh-meta-item label,
.uh-pdf-label {
    display: block;
    color: #64748b;
    font-size: 8px;
    font-weight: 700;
    letter-spacing: 1.2px;
    text-transform: uppercase;
    margin-bottom: 3px;
}

.uh-meta-item .val,
.uh-pdf-value {
    color: #1a2a72;
    font-size: 11px;
    font-weight: 700;
}

.uh-status-badge {
    display: inline-block;
    border-radius: 20px;
    font-size: 9px;
    font-weight: 700;
    padding: 3px 10px;
    border: 1px solid #fc7f10;
    color: #b45309;
    background: #fff7e6;
}

.uh-status-paid {
    background: #e8f5e9;
    border-color: #2e7d32;
    color: #2e7d32;
}

.uh-status-unpaid {
    background: #fff7e6;
    border-color: #fc7f10;
    color: #b45309;
}

.uh-status-partial {
    background: #fff7e6;
    border-color: #b45309;
    color: #b45309;
}

.uh-status-void {
    background: #f1f5f9;
    border-color: #64748b;
    color: #64748b;
}

.uh-parties {
    padding: 20px 30px;
    border-bottom: 1px solid #e2e8f0;
}

.uh-parties-table td {
    width: 50%;
    vertical-align: top;
}

.uh-party-left {
    padding-right: 22px;
    border-right: 1px solid #e2e8f0;
}

.uh-party-right {
    padding-left: 22px;
}

.uh-party-label,
.uh-section-label {
    color: #fc7f10;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 1.8px;
    text-transform: uppercase;
    margin-bottom: 6px;
}

.uh-party-name {
    color: #1a2a72;
    font-size: 12px;
    font-weight: 700;
    margin-bottom: 3px;
}

.uh-party-detail {
    color: #64748b;
    font-size: 10.5px;
    line-height: 1.6;
}

.uh-table-wrap {
    padding: 0 30px;
}

.uh-table {
    margin: 0;
}

.uh-table th {
    background: #1a2a72;
    color: #ffffff;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: .8px;
    padding: 9px 10px;
    text-align: left;
    text-transform: uppercase;
}

.uh-table th.r,
.uh-table td.r {
    text-align: right;
}

.uh-table td {
    border-bottom: 1px solid #e2e8f0;
    color: #475569;
    font-size: 10.5px;
    padding: 10px;
    vertical-align: middle;
}

.uh-table tr:nth-child(even) td {
    background: #f8fafc;
}

.uh-empty-row {
    text-align: center;
    color: #94a3b8;
    padding: 22px;
}

.uh-totals-section {
    padding: 18px 30px;
    border-top: 1.5px solid #1a2a72;
}

.uh-totals-table {
    width: 320px;
    margin-left: auto;
}

.uh-totals-table td {
    border-bottom: 1px solid #e2e8f0;
    color: #64748b;
    font-size: 11px;
    padding: 6px 0;
}

.uh-totals-table .value {
    text-align: right;
    font-weight: 700;
}

.uh-totals-table .grand td {
    border-top: 1.5px solid #1a2a72;
    border-bottom: none;
    color: #1a2a72;
    font-size: 13px;
    font-weight: 900;
    padding-top: 10px;
}

.uh-totals-table .grand .value {
    color: #fc7f10;
}

.uh-payment {
    padding: 0 30px 18px;
}

.uh-payment-box {
    border-top: 1px solid #e2e8f0;
    padding-top: 14px;
}

.uh-payment-detail {
    color: #64748b;
    font-size: 10.5px;
    line-height: 1.7;
}

.uh-notes {
    margin: 0 30px 18px;
    border: 1px dashed #cbd5e1;
    border-radius: 6px;
    padding: 12px 14px;
    background: #fafbfd;
}

.uh-notes p {
    color: #475569;
    font-size: 10.5px;
    line-height: 1.6;
    margin: 0;
}

.uh-signature {
    padding: 20px 30px 28px;
    text-align: right;
}

.uh-sig-box {
    display: inline-block;
    width: 260px;
    text-align: center;
}

.uh-sig-space {
    height: 46px;
}

.uh-sig-line {
    border-top: 1px solid #1a2a72;
    color: #475569;
    font-size: 10.5px;
    padding-top: 7px;
}

.uh-sig-for {
    color: #1a2a72;
    font-size: 10.5px;
    font-weight: 700;
    margin-top: 2px;
}

.uh-footer {
    background: #1a2a72;
    border-top: 3px solid #fc7f10;
    padding: 12px 30px;
}

.uh-footer-table td {
    font-size: 9.5px;
    vertical-align: middle;
}

.uh-footer-left {
    color: rgba(255,255,255,.64);
}

.uh-footer-right {
    color: #fc7f10;
    text-align: right;
}
CSS;

    ob_start();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice <?= h($inv['invoice_number'] ?? '') ?></title>
    <style>
        <?= $sharedCss ?>

        <?= $pdfCss ?>
        .uh-header{background:#fff!important;border-bottom:2px solid <?= h($brandPrimaryColor) ?>}
        .uh-wordmark-main{color:<?= h($brandPrimaryColor) ?>!important}
        .uh-company-tagline{color:<?= h($brandAccentColor) ?>!important}
    </style>
</head>
<body>
<div class="uh-pdf-wrap">
    <?php if ($logoDataUri !== ''): ?>
        <img src="<?= h($logoDataUri) ?>" class="uh-pdf-watermark" alt="Watermark">
    <?php endif; ?>

    <div class="uh-pdf-content">
        <div class="uh-paper">

            <div class="uh-header">
                <table class="uh-header-table">
                    <tr>
                        <td class="uh-brand-cell">
                            <table class="uh-brand-table">
                                <tr>
                                    <td style="vertical-align:middle;padding-right:14px">
                                        <?php if ($logoDataUri !== ''): ?>
                                            <img src="<?= h($logoDataUri) ?>" class="uh-logo-img" alt="Logo">
                                        <?php else: ?>
                                            <div class="uh-logo-fallback">U</div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="vertical-align:middle">
                                        <div class="uh-brand-detail">
                                            <?php if ($companyAddress !== ''): ?><?= h($companyAddress) ?><br><?php endif; ?>
                                            <?php if ($companyPhone !== ''): ?><?= h($companyPhone) ?><?php endif; ?><?php if ($companyPhone !== '' && $companyEmail !== ''): ?> | <?php endif; ?><?php if ($companyEmail !== ''): ?><?= h($companyEmail) ?><?php endif; ?><br>
                                            <?php if ($companyTin !== ''): ?>TIN: <?= h($companyTin) ?><?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                        <td class="uh-doc-cell">
                            <div class="uh-doc-badge uh-doc-badge-invoice">Invoice</div>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="uh-meta-bar">
                <table class="uh-meta-table">
                    <tr>
                        <td>
                            <div class="uh-meta-item">
                                <label>Invoice No.</label>
                                <div class="val"><?= h($inv['invoice_number'] ?? '') ?></div>
                            </div>
                        </td>
                        <td>
                            <div class="uh-meta-item">
                                <label>Invoice Date</label>
                                <div class="val">
                                    <?= !empty($inv['issued_date']) ? h(date('d M, Y', strtotime((string)$inv['issued_date']))) : '-' ?>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="uh-meta-item">
                                <label>Due Date</label>
                                <div class="val">
                                    <?= !empty($inv['due_date']) ? h(date('d M, Y', strtotime((string)$inv['due_date']))) : '-' ?>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="uh-meta-item">
                                <label>Status</label>
                                <div class="val">
                                    <span class="uh-status-badge <?= h($statusClass) ?>"><?= h($statusLabel) ?></span>
                                </div>
                            </div>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="uh-parties">
                <table class="uh-parties-table">
                    <tr>
                        <td class="uh-party-left">
                            <div class="uh-party-label">From</div>
                            <div class="uh-party-name"><?= h($companyName) ?></div>
                            <div class="uh-party-detail">
                                <?php if ($companyAddress !== ''): ?>
                                    <?= nl2br(h($companyAddress)) ?><br>
                                <?php endif; ?>

                                <?php if ($companyTin !== ''): ?>
                                    TIN: <?= h($companyTin) ?><br>
                                <?php endif; ?>

                                <?php if ($companyPhone !== ''): ?>
                                    <?= h($companyPhone) ?><br>
                                <?php endif; ?>

                                <?php if ($companyEmail !== ''): ?>
                                    <?= h($companyEmail) ?>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="uh-party-right">
                            <div class="uh-party-label">Bill To</div>
                            <div class="uh-party-name"><?= h($inv['client_name'] ?? '-') ?></div>
                            <div class="uh-party-detail">
                                <?php if (!empty($inv['client_company'])): ?>
                                    <?= h($inv['client_company']) ?><br>
                                <?php endif; ?>

                                <?php if (!empty($inv['client_phone'])): ?>
                                    <?= h($inv['client_phone']) ?><br>
                                <?php endif; ?>

                                <?php if (!empty($inv['client_email'])): ?>
                                    <?= h($inv['client_email']) ?><br>
                                <?php endif; ?>

                                <?php if (!empty($inv['client_address'])): ?>
                                    <?= nl2br(h($inv['client_address'])) ?><br>
                                <?php endif; ?>

                                <?php if (!empty($inv['client_tin'])): ?>
                                    TIN: <?= h($inv['client_tin']) ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="uh-table-wrap">
                <table class="uh-table">
                    <thead>
                        <tr>
                            <th class="uh-col-num">#</th>
                            <th>Description</th>
                            <th class="r uh-col-qty">Qty</th>
                            <th class="r uh-col-money">Unit Price</th>
                            <th class="r uh-col-tax">VAT %</th>
                            <th class="r uh-col-money">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($items)): ?>
                        <?php foreach ($items as $k => $it): ?>
                            <?php
                                $qty  = (float)($it['quantity'] ?? 0);
                                $unit = (float)($it['unit_price'] ?? 0);
                                $taxR = (float)($it['tax_rate'] ?? 0);
                                $line = $qty * $unit;
                                $tax  = ($line * $taxR) / 100;
                                $amt  = $line + $tax;

                                $desc = trim((string)($it['description'] ?? ''));
                                if ($desc === '') {
                                    $desc = trim((string)($it['service_name'] ?? 'Item'));
                                }
                            ?>
                            <tr>
                                <td><?= (int)$k + 1 ?></td>
                                <td><?= h($desc) ?></td>
                                <td class="r"><?= number_format($qty, 2) ?></td>
                                <td class="r"><?= h(moneyFmt($unit, $currencySymbol)) ?></td>
                                <td class="r"><?= number_format($taxR, 2) ?>%</td>
                                <td class="r"><?= h(moneyFmt($amt, $currencySymbol)) ?></td>
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

            <div class="uh-totals-section">
                <table class="uh-totals-table">
                    <tr>
                        <td class="label">Subtotal</td>
                        <td class="value"><?= h(moneyFmt($inv['subtotal'] ?? 0, $currencySymbol)) ?></td>
                    </tr>
                    <tr>
                        <td class="label">VAT / Tax</td>
                        <td class="value"><?= h(moneyFmt($inv['tax_total'] ?? 0, $currencySymbol)) ?></td>
                    </tr>
                    <?php if ((float)($inv['discount_amount'] ?? 0) > 0): ?>
                        <tr>
                            <td class="label">Discount</td>
                            <td class="value">- <?= h(moneyFmt($inv['discount_amount'], $currencySymbol)) ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr class="grand">
                        <td class="label">Total Due</td>
                        <td class="value"><?= h(moneyFmt($inv['total'] ?? 0, $currencySymbol)) ?></td>
                    </tr>
                </table>
            </div>

            <div class="uh-payment">
                <div class="uh-payment-box">
                    <div class="uh-section-label">Payment Instructions</div>
                    <div class="uh-payment-detail">
                        <?php if (!empty($cfg['bank_name'])): ?>
                            <strong>Bank:</strong> <?= h($cfg['bank_name']) ?>
                            <?php if (!empty($cfg['bank_account_name'])): ?>
                                . <strong>Account Name:</strong> <?= h($cfg['bank_account_name']) ?>
                            <?php endif; ?>
                            <br>
                        <?php endif; ?>

                        <?php if (!empty($cfg['bank_account_number'])): ?>
                            <strong>Account No:</strong> <?= h($cfg['bank_account_number']) ?><br>
                        <?php endif; ?>

                        <?php if (!empty($cfg['payment_notes'])): ?>
                            <?= nl2br(h($cfg['payment_notes'])) ?>
                        <?php else: ?>
                            Please reference invoice number <strong><?= h($inv['invoice_number'] ?? '') ?></strong> with all payments.
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if (!empty($inv['notes']) || !empty($inv['order_notes'])): ?>
                <div class="uh-notes">
                    <div class="uh-section-label">Notes</div>
                    <p><?= nl2br(h($inv['notes'] ?: $inv['order_notes'])) ?></p>
                </div>
            <?php endif; ?>

            <div class="uh-signature">
                <div class="uh-sig-box">
                    <?php if (!empty($inv['signature_data']) && str_starts_with((string)$inv['signature_data'], 'data:image/')): ?>
                        <img src="<?= h($inv['signature_data']) ?>" alt="Authorized signature" style="max-width:180px;max-height:60px;object-fit:contain">
                    <?php else: ?><div class="uh-sig-space"></div><?php endif; ?>
                    <div class="uh-sig-line"><?= h($inv['authorized_name'] ?: 'Authorized Signature') ?></div>
                    <div class="uh-sig-for">For: <?= h($companyName) ?></div>
                </div>
            </div>

            <div class="uh-footer">
                <table class="uh-footer-table">
                    <tr>
                        <td class="uh-footer-left"><?= h($companyName) ?></td>
                        <td class="uh-footer-right">
                            <?= h($companyWebsite) ?>
                            <?php if ($companyPhone !== ''): ?>
                                . <?= h($companyPhone) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>

        </div>
    </div>
</div>
</body>
</html>
    <?php
    $html = ob_get_clean();

    if (!class_exists(\Dompdf\Dompdf::class)) {
        throw new Exception('Dompdf is not installed. Run: composer require dompdf/dompdf');
    }

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $pdfOutput = $dompdf->output();

    $pdfDir = __DIR__ . '/uploads/invoices';
    if (!is_dir($pdfDir) && !mkdir($pdfDir, 0775, true) && !is_dir($pdfDir)) {
        throw new Exception('Failed to create invoices upload directory.');
    }

    $safeInvoiceNo = preg_replace('/[^A-Za-z0-9\-_]/', '_', (string)$inv['invoice_number']);
    $pdfPath = $pdfDir . '/invoice_' . $safeInvoiceNo . '.pdf';

    if (file_put_contents($pdfPath, $pdfOutput) === false) {
        throw new Exception('Failed to save generated invoice PDF.');
    }

    $subject    = 'Invoice ' . (string)$inv['invoice_number'] . ' - ' . $companyName;
    $clientName = trim((string)($inv['client_name'] ?? 'Customer'));
    $totalDue   = moneyFmt($inv['total'] ?? 0, $currencySymbol);

    $body = '
        <p>Dear ' . h($clientName !== '' ? $clientName : 'Customer') . ',</p>
        <p>Please find attached your invoice.</p>
        <p>
            <strong>Invoice No:</strong> ' . h($inv['invoice_number'] ?? '') . '<br>
            <strong>Order No:</strong> ' . h($inv['order_number'] ?? '') . '<br>
            <strong>Total Due:</strong> ' . h($totalDue) . '<br>
            <strong>Issued Date:</strong> ' . (!empty($inv['issued_date']) ? h(date('d F Y', strtotime((string)$inv['issued_date']))) : '-') . '<br>' .
            (!empty($inv['due_date']) ? '<strong>Due Date:</strong> ' . h(date('d F Y', strtotime((string)$inv['due_date']))) . '<br>' : '') . '
        </p>';

    if (!empty($cfg['payment_notes'])) {
        $body .= '
        <p><strong>Payment Instructions:</strong><br>' . nl2br(h($cfg['payment_notes'])) . '</p>';
    }

    $body .= '
        <p>Thank you for your business.</p>
        <p>Regards,<br>' . h($companyName) . '</p>
    ';

    if (!function_exists('sendEmailWithAttachment')) {
        throw new Exception('sendEmailWithAttachment() is missing in email-function.php');
    }

    $sent = sendEmailWithAttachment(
        (string)$inv['client_email'],
        $subject,
        $body,
        $pdfPath,
        basename($pdfPath),
        $clientName,
        'invoice',
        (int)$inv['id']
    );

    if (!$sent) {
        throw new Exception('Email sending failed.');
    }

    jsonResponse([
        'success' => true,
        'message' => 'Invoice email sent successfully with PDF attached.'
    ]);
} catch (Throwable $e) {
    jsonResponse([
        'success' => false,
        'error'   => $e->getMessage()
    ], 400);
}
