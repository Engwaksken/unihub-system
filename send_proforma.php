<?php
declare(strict_types=1);

require_once 'config/db.php';
require_once 'email-function.php';
require_once __DIR__ . '/includes/proforma.php';

header('Content-Type: application/json; charset=utf-8');

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
        return $data === false ? '' : 'data:' . $mime . ';base64,' . base64_encode($data);
    }
}

try {
    if (empty($_SESSION['user_id'])) {
        jsonResponse(['success' => false, 'error' => 'Please log in again.'], 401);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
    }

    $data = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($data)) {
        $data = $_POST ?: [];
    }

    $orderId = (int)($data['order_id'] ?? 0);
    if ($orderId <= 0) {
        throw new Exception('Invalid order ID.');
    }

    $pdo = getDB();
    $cfg = getSettings();

    $pf = loadProforma($pdo, $cfg, $orderId);
    if (!$pf) {
        throw new Exception('Order not found.');
    }
    $order = $pf['order'];
    $items = $pf['items'];

    if (($order['status'] ?? '') === 'cancelled') {
        throw new Exception('Cancelled orders cannot be sent as a proforma.');
    }
    if (!$items) {
        throw new Exception('This order has no items.');
    }
    if (empty($order['client_email'])) {
        throw new Exception('Client email is missing.');
    }

    $signatory = loadCurrentSignatory($pdo);

    $currencySymbol    = (string)($cfg['currency_symbol'] ?? 'UGX ');
    $companyName       = (string)($cfg['company_name'] ?? 'Unihub Solutions Uganda');
    $brandPrimaryColor = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($cfg['brand_primary_color'] ?? '')) ? $cfg['brand_primary_color'] : '#1a2a72';
    $brandAccentColor  = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($cfg['brand_accent_color'] ?? '')) ? $cfg['brand_accent_color'] : '#fc7f10';
    $companyAddress    = (string)($cfg['company_address'] ?? '');
    $companyPhone      = (string)($cfg['company_phone'] ?? '');
    $companyEmail      = (string)($cfg['company_email'] ?? '');
    $companyTin        = (string)($cfg['company_tin'] ?? '');
    $companyWebsite    = (string)($cfg['company_website'] ?? 'www.unihubug.com');
    $bankAccName       = $cfg['account_name'] ?? ($cfg['bank_account_name'] ?? '');
    $bankAccNo         = $cfg['account_number'] ?? ($cfg['bank_account_number'] ?? '');

    $configuredLogo = trim((string)($cfg['company_logo'] ?? ''));
    $logoFile = $configuredLogo !== '' ? realpath(__DIR__ . '/' . ltrim($configuredLogo, '/\\')) : false;
    $assetsRoot = realpath(__DIR__ . '/assets/img');
    if (!$logoFile || !$assetsRoot || !str_starts_with($logoFile, $assetsRoot . DIRECTORY_SEPARATOR) || !is_file($logoFile)) $logoFile = __DIR__ . '/assets/img/logo.png';
    $logoDataUri = assetDataUri($logoFile);

    $pdfCss = require __DIR__ . '/includes/invoice_pdf_css.php';
    $fmtDate = static fn(string $d): string => date('d M, Y', strtotime($d));

    ob_start();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Proforma Invoice <?= h($pf['number']) ?></title>
    <style>
        <?= $pdfCss ?>
        .uh-header{background:#fff!important;border-bottom:2px solid <?= h($brandPrimaryColor) ?>}
        .uh-doc-badge{background:<?= h($brandAccentColor) ?>;font-size:14px}
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
                            <div class="uh-doc-badge">Proforma Invoice</div>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="uh-meta-bar">
                <table class="uh-meta-table">
                    <tr>
                        <td><div class="uh-meta-item"><label>Proforma No.</label><div class="val"><?= h($pf['number']) ?></div></div></td>
                        <td><div class="uh-meta-item"><label>Date</label><div class="val"><?= h($fmtDate($pf['date'])) ?></div></div></td>
                        <td><div class="uh-meta-item"><label>Valid Until</label><div class="val"><?= h($fmtDate($pf['valid_until'])) ?></div></div></td>
                        <td><div class="uh-meta-item"><label>Order Ref.</label><div class="val"><?= h($order['order_number']) ?></div></div></td>
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
                                <?php if (!empty($cfg['bank_name'])): ?><strong>Bank:</strong> <?= h($cfg['bank_name']) ?><br><?php endif; ?>
                                <?php if (!empty($cfg['bank_branch'])): ?><strong>Branch:</strong> <?= h($cfg['bank_branch']) ?><br><?php endif; ?>
                                <?php if ($bankAccName !== ''): ?><strong>Account Name:</strong> <?= h($bankAccName) ?><br><?php endif; ?>
                                <?php if ($bankAccNo !== ''): ?><strong>Account No:</strong> <?= h($bankAccNo) ?><?php endif; ?>
                            </div>
                        </td>
                        <td class="uh-party-right">
                            <div class="uh-party-label">Bill To</div>
                            <div class="uh-party-name"><?= h($order['client_name'] ?? '-') ?></div>
                            <div class="uh-party-detail">
                                <?php if (!empty($order['client_company'])): ?><?= h($order['client_company']) ?><br><?php endif; ?>
                                <?php if (!empty($order['client_phone'])): ?><?= h($order['client_phone']) ?><br><?php endif; ?>
                                <?php if (!empty($order['client_email'])): ?><?= h($order['client_email']) ?><br><?php endif; ?>
                                <?php if (!empty($order['client_address'])): ?><?= nl2br(h($order['client_address'])) ?><br><?php endif; ?>
                                <?php if (!empty($order['client_tin'])): ?>TIN: <?= h($order['client_tin']) ?><?php endif; ?>
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
                    <?php foreach ($items as $k => $it): ?>
                        <?php
                            $qty  = (float)($it['quantity'] ?? 0);
                            $unit = (float)($it['unit_price'] ?? 0);
                            $taxR = (float)($it['tax_rate'] ?? 0);
                            $line = $qty * $unit;
                            $desc = trim((string)($it['description'] ?? '')) ?: trim((string)($it['service_name'] ?? 'Item'));
                        ?>
                        <tr>
                            <td><?= (int)$k + 1 ?></td>
                            <td><?= h($desc) ?></td>
                            <td class="r"><?= number_format($qty, 2) ?></td>
                            <td class="r"><?= h(moneyFmt($unit, $currencySymbol)) ?></td>
                            <td class="r"><?= number_format($taxR, 2) ?>%</td>
                            <td class="r"><?= h(moneyFmt($line + $line * $taxR / 100, $currencySymbol)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="uh-totals-section">
                <table class="uh-totals-table">
                    <tr><td class="label">Subtotal</td><td class="value"><?= h(moneyFmt($pf['subtotal'], $currencySymbol)) ?></td></tr>
                    <tr><td class="label">VAT / Tax</td><td class="value"><?= h(moneyFmt($pf['tax_total'], $currencySymbol)) ?></td></tr>
                    <?php if ($pf['discount'] > 0): ?>
                        <tr><td class="label">Discount</td><td class="value">- <?= h(moneyFmt($pf['discount'], $currencySymbol)) ?></td></tr>
                    <?php endif; ?>
                    <tr class="grand"><td class="label">Total</td><td class="value"><?= h(moneyFmt($pf['total'], $currencySymbol)) ?></td></tr>
                </table>
            </div>

            <div class="uh-payment">
                <div class="uh-payment-box">
                    <div class="uh-section-label">Terms</div>
                    <div class="uh-payment-detail">
                        This is a proforma invoice issued for quotation and advance-payment purposes. It is not a tax invoice.
                        A final invoice will be issued once the order is confirmed.
                        Prices are valid until <strong><?= h($fmtDate($pf['valid_until'])) ?></strong>.
                        Please quote <strong><?= h($pf['number']) ?></strong> with any payment.
                    </div>
                </div>
            </div>

            <?php if (!empty($order['notes'])): ?>
                <div class="uh-notes">
                    <div class="uh-section-label">Notes</div>
                    <p><?= nl2br(h($order['notes'])) ?></p>
                </div>
            <?php endif; ?>

            <div class="uh-signature">
                <div class="uh-sig-box">
                    <?php if (!empty($signatory['profile_signature_data']) && str_starts_with((string)$signatory['profile_signature_data'], 'data:image/')): ?>
                        <img src="<?= h($signatory['profile_signature_data']) ?>" alt="Authorized signature" style="max-width:180px;max-height:60px;object-fit:contain">
                    <?php else: ?><div class="uh-sig-space"></div><?php endif; ?>
                    <div class="uh-sig-line"><?= h(($signatory['authorized_name'] ?? '') ?: 'Authorized Signature') ?></div>
                    <div class="uh-sig-for">For: <?= h($companyName) ?></div>
                </div>
            </div>

            <div class="uh-footer">
                <table class="uh-footer-table">
                    <tr>
                        <td class="uh-footer-left"><?= h($companyName) ?></td>
                        <td class="uh-footer-right">
                            <?= h($companyWebsite) ?><?php if ($companyPhone !== ''): ?> . <?= h($companyPhone) ?><?php endif; ?>
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

    $pdfDir = __DIR__ . '/uploads/proformas';
    if (!is_dir($pdfDir) && !mkdir($pdfDir, 0775, true) && !is_dir($pdfDir)) {
        throw new Exception('Failed to create proformas upload directory.');
    }

    $safeNo  = preg_replace('/[^A-Za-z0-9\-_]/', '_', $pf['number']);
    $pdfPath = $pdfDir . '/proforma_' . $safeNo . '.pdf';
    if (file_put_contents($pdfPath, $dompdf->output()) === false) {
        throw new Exception('Failed to save generated proforma PDF.');
    }

    $clientName = trim((string)($order['client_name'] ?? '')) ?: 'Customer';
    $subject    = 'Proforma Invoice ' . $pf['number'] . ' - ' . $companyName;

    $body = '
        <p>Dear ' . h($clientName) . ',</p>
        <p>Please find attached a proforma invoice for your order.</p>
        <p>
            <strong>Proforma No:</strong> ' . h($pf['number']) . '<br>
            <strong>Order No:</strong> ' . h($order['order_number']) . '<br>
            <strong>Total:</strong> ' . h(moneyFmt($pf['total'], $currencySymbol)) . '<br>
            <strong>Valid Until:</strong> ' . h(date('d F Y', strtotime($pf['valid_until']))) . '
        </p>
        <p>This is not a tax invoice. A final invoice will be issued once the order is confirmed.</p>
        <p>Regards,<br>' . h($companyName) . '</p>
    ';

    $sent = sendEmailWithAttachment(
        (string)$order['client_email'],
        $subject,
        $body,
        $pdfPath,
        basename($pdfPath),
        $clientName,
        'proforma',
        $orderId
    );

    if (!$sent) {
        throw new Exception('Email sending failed.');
    }

    jsonResponse([
        'success' => true,
        'message' => 'Proforma sent to ' . $order['client_email'] . ' with PDF attached.',
    ]);
} catch (Throwable $e) {
    jsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
}
