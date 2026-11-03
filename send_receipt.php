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


if (!function_exists('loadSharedStyleCss')) {
    function loadSharedStyleCss(): string
    {
        $paths = [
            __DIR__ . '/style.css',
            __DIR__ . '/assets/css/style.css',
            __DIR__ . '/css/style.css',
        ];

        foreach ($paths as $path) {
            if (is_file($path) && is_readable($path)) {
                $css = (string)file_get_contents($path);

            
                $css = preg_replace('/@media\s+print\s*\{(?:[^{}]|\{[^{}]*\})*\}/is', '', $css) ?? $css;

                return $css;
            }
        }

        return '';
    }
}


if (!function_exists('receiptPdfFallbackCss')) {
    function receiptPdfFallbackCss(): string
    {
        return <<<'CSS'
@page { margin: 24px; }

body {
    font-family: DejaVu Sans, Arial, sans-serif;
    font-size: 12px;
    color: #1e293b;
    margin: 0;
    padding: 0;
    background: #ffffff;
}

.uh-pdf-doc {
    position: relative;
    background: #ffffff;
    padding: 6px;
}

.uh-pdf-content {
    position: relative;
    z-index: 1;
}

.uh-paper {
    position: relative;
    max-width: 860px;
    margin: 0 auto;
    background: #ffffff;
    box-shadow: none;
    overflow: hidden;
    font-family: DejaVu Sans, Arial, sans-serif;
    font-size: 12px;
    color: #1e293b;
}

.uh-pdf-watermark {
    position: fixed;
    top: 22%;
    left: 14%;
    width: 460px;
    opacity: 0.05;
    z-index: 0;
}

.uh-header {
    background: #1a2a72;
    padding: 22px 36px 22px 28px;
}

.uh-header-table,
.uh-meta-table,
.uh-parties-table,
.uh-table,
.uh-totals,
.uh-signature-table {
    width: 100%;
    border-collapse: collapse;
}

.uh-header-table td,
.uh-parties-table td,
.uh-signature-table td {
    vertical-align: top;
}

.uh-brand-cell {
    width: 70%;
}

.uh-logo-img {
    width: 66px;
    height: 66px;
    object-fit: contain;
}

.uh-company-name {
    font-size: 18px;
    font-weight: bold;
    color: #ffffff;
    margin-bottom: 4px;
}

.uh-company-muted {
    color: rgba(255,255,255,.72);
    font-size: 11px;
    line-height: 1.55;
}

.uh-doc-badge-rcpt {
    background: #2e7d32;
    border-radius: 5px;
    padding: 8px 20px;
    font-weight: 900;
    font-size: 18px;
    color: #ffffff;
    letter-spacing: 2px;
    text-transform: uppercase;
    display: inline-block;
}

.uh-paid-bar {
    background: #e8f5e9;
    border-left: 4px solid #2e7d32;
    padding: 8px 36px;
    font-size: 13px;
    font-weight: 700;
    color: #1b5e20;
}

.uh-meta-bar {
    background: #e8ebf7;
    padding: 10px 36px;
}

.uh-meta-table td {
    width: 25%;
    padding-right: 8px;
}

.uh-meta-item label,
.uh-party-label,
.uh-sig-area-label {
    display: block;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    color: #64748b;
    margin-bottom: 3px;
}

.uh-meta-item .val {
    font-size: 12px;
    font-weight: 700;
    color: #1a2a72;
}

.uh-parties {
    padding: 20px 36px;
    border-bottom: 1px solid #e2e8f0;
}

.uh-parties-table td {
    width: 50%;
    padding-right: 20px;
}

.uh-party-label,
.uh-sig-area-label,
.uh-section-label {
    color: #fc7f10;
}

.uh-party-name {
    font-size: 13px;
    font-weight: 700;
    color: #1a2a72;
    margin-bottom: 3px;
}

.uh-party-detail {
    font-size: 11px;
    color: #64748b;
    line-height: 1.6;
}

.uh-table-wrap {
    padding: 0 36px;
}

.uh-table th,
.uh-table td {
    padding: 8px;
    font-size: 11px;
    border-bottom: 1px solid #e2e8f0;
}

.uh-table th {
    background: #1a2a72;
    color: #ffffff;
    text-transform: uppercase;
    letter-spacing: .7px;
    text-align: left;
}

.uh-table .r {
    text-align: right;
}

.uh-totals-section {
    padding: 18px 36px;
}

.uh-totals {
    width: 330px;
    margin-left: auto;
}

.uh-totals td {
    padding: 7px 0;
    font-size: 12px;
    border-bottom: 1px solid #e2e8f0;
}

.uh-totals .lbl {
    color: #64748b;
}

.uh-totals .amt {
    text-align: right;
    font-weight: 700;
    color: #1a2a72;
}

.uh-totals .grand td {
    border-top: 2px solid #1a2a72;
    padding-top: 10px;
    font-weight: 700;
}

.uh-totals .paid-row td {
    color: #2e7d32;
    font-weight: 900;
    font-size: 14px;
}

.uh-notes {
    margin: 0 36px 18px;
    border: 1px dashed #cbd5e1;
    border-radius: 6px;
    padding: 12px 16px;
    background: #fafbfd;
    font-size: 11px;
    color: #475569;
}

.uh-sig-stamp {
    padding: 20px 36px 28px;
    border-top: 1px solid #e2e8f0;
    margin-top: 8px;
}

.uh-signature-table td {
    width: 50%;
    text-align: center;
    vertical-align: bottom;
    padding-right: 18px;
}

.uh-sig-image-area img {
    max-width: 190px;
    max-height: 70px;
    object-fit: contain;
    margin-bottom: 8px;
}

.uh-sig-name {
    font-size: 11px;
    font-weight: 700;
    color: #1a2a72;
    margin-top: 3px;
}

.uh-sig-date {
    font-size: 10px;
    color: #64748b;
    margin-top: 2px;
}

.uh-sig-underline {
    border-top: 1px solid #1a2a72;
    padding-top: 7px;
    font-size: 11px;
    color: #475569;
}

.uh-footer {
    background: #1a2a72;
    border-top: 3px solid #fc7f10;
    padding: 12px 36px;
    font-size: 10px;
    color: rgba(255,255,255,.75);
    line-height: 1.6;
    text-align: center;
}

.uh-footer strong {
    color: #ffffff;
}

.uh-footer .right {
    color: #fc7f10;
}
CSS;
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

    $receiptId = (int)($data['receipt_id'] ?? 0);
    if ($receiptId <= 0) {
        throw new Exception('Invalid receipt ID.');
    }

    $pdo = getDB();
    $cfg = getSettings();

    $stmt = $pdo->prepare("
        SELECT 
            r.*,
            i.id AS invoice_id,
            i.order_id,
            i.invoice_number,
            i.total AS invoice_total,
            o.order_number,
            c.name AS client_name,
            c.email AS client_email,
            c.phone AS client_phone,
            c.address AS client_address,
            c.company AS client_company,
            c.tin AS client_tin
        FROM receipts r
        INNER JOIN invoices i ON i.id = r.invoice_id
        INNER JOIN orders o ON o.id = i.order_id
        INNER JOIN clients c ON c.id = o.client_id
        WHERE r.id = ?
        LIMIT 1
    ");
    $stmt->execute([$receiptId]);
    $rcp = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rcp) {
        throw new Exception('Receipt not found.');
    }

    if (empty($rcp['client_email'])) {
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
    $itemsStmt->execute([(int)$rcp['order_id']]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $currencySymbol = (string)($cfg['currency_symbol'] ?? 'UGX ');
    $companyName    = (string)($cfg['company_name'] ?? 'Unihub Solutions Uganda');
    $companyAddress = (string)($cfg['company_address'] ?? '');
    $companyPhone   = (string)($cfg['company_phone'] ?? '');
    $companyEmail   = (string)($cfg['company_email'] ?? '');
    $companyTin     = (string)($cfg['company_tin'] ?? '');
    $companyWebsite = (string)($cfg['company_website'] ?? 'www.unihubug.com');

    $computedSubtotal = 0.0;
    $computedTax      = 0.0;
    $computedGrand    = 0.0;

    foreach ($items as $it) {
        $qty   = (float)($it['quantity'] ?? 0);
        $unit  = (float)($it['unit_price'] ?? 0);
        $taxR  = (float)($it['tax_rate'] ?? 0);
        $line  = $qty * $unit;
        $tax   = ($line * $taxR) / 100;

        $computedSubtotal += $line;
        $computedTax      += $tax;
        $computedGrand    += ($line + $tax);
    }

    $invoiceTotal = (float)($rcp['invoice_total'] ?? 0);
    if ($invoiceTotal <= 0) {
        $invoiceTotal = $computedGrand;
    }

    if ($computedSubtotal <= 0 && $invoiceTotal > 0) {
        $computedSubtotal = $invoiceTotal;
    }

    $amountPaid = (float)($rcp['amount_paid'] ?? 0);

    $logoPath = __DIR__ . '/assets/img/logo.png';
    $logoDataUri = '';
    if (is_file($logoPath)) {
        $logoExt = strtolower((string)pathinfo($logoPath, PATHINFO_EXTENSION));
        $logoMime = match ($logoExt) {
            'jpg', 'jpeg' => 'image/jpeg',
            'webp'        => 'image/webp',
            default       => 'image/png',
        };
        $logoData = file_get_contents($logoPath);
        if ($logoData !== false) {
            $logoDataUri = 'data:' . $logoMime . ';base64,' . base64_encode($logoData);
        }
    }

    $signatureDataUri = '';
    $signaturePath = trim((string)($rcp['signature_path'] ?? ''));
    if ($signaturePath !== '') {
        $localSignatureFile = __DIR__ . '/' . ltrim($signaturePath, '/');
        if (is_file($localSignatureFile)) {
            $sigExt = strtolower((string)pathinfo($localSignatureFile, PATHINFO_EXTENSION));
            $sigMime = match ($sigExt) {
                'jpg', 'jpeg' => 'image/jpeg',
                'webp'        => 'image/webp',
                default       => 'image/png',
            };
            $sigData = file_get_contents($localSignatureFile);
            if ($sigData !== false) {
                $signatureDataUri = 'data:' . $sigMime . ';base64,' . base64_encode($sigData);
            }
        }
    }

    $authorizedName = trim((string)($rcp['authorized_name'] ?? ''));
    $signedAt       = trim((string)($rcp['signed_at'] ?? ''));

    $receiptNumber = (string)($rcp['receipt_number'] ?? '');
    $paymentDate   = !empty($rcp['payment_date']) ? date('d F Y', strtotime((string)$rcp['payment_date'])) : '-';
    $paymentMethod = ucwords(str_replace('_', ' ', (string)($rcp['payment_method'] ?? '')));

    $sharedCss = loadSharedStyleCss();
    $pdfCss    = receiptPdfFallbackCss();

    ob_start();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Receipt <?= h($receiptNumber) ?></title>
    <style>
        <?= $sharedCss ?>

        <?= $pdfCss ?>
    </style>
</head>
<body>
<div class="uh-pdf-doc">
    <?php if ($logoDataUri !== ''): ?>
        <img src="<?= h($logoDataUri) ?>" class="uh-pdf-watermark" alt="Watermark">
    <?php endif; ?>

    <div class="uh-pdf-content">
        <div class="uh-paper">

            <!-- Header -->
            <div class="uh-header">
                <table class="uh-header-table">
                    <tr>
                        <td class="uh-brand-cell">
                            <table class="uh-header-table">
                                <tr>
                                    <td style="width:76px;vertical-align:top">
                                        <?php if ($logoDataUri !== ''): ?>
                                            <img src="<?= h($logoDataUri) ?>" class="uh-logo-img" alt="Logo">
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="uh-company-name"><?= h($companyName) ?></div>
                                        <div class="uh-company-muted">
                                            <?php if ($companyAddress !== ''): ?>
                                                <?= h($companyAddress) ?><br>
                                            <?php endif; ?>
                                            <?= h($companyPhone) ?>
                                            <?php if ($companyPhone !== '' && $companyEmail !== ''): ?> | <?php endif; ?>
                                            <?= h($companyEmail) ?><br>
                                            <?php if ($companyTin !== ''): ?>
                                                TIN: <?= h($companyTin) ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                        <td style="text-align:right">
                            <div class="uh-doc-badge-rcpt">RECEIPT</div>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Paid bar -->
            <div class="uh-paid-bar">
                Payment Received - Thank you!
            </div>

            <!-- Meta bar -->
            <div class="uh-meta-bar">
                <table class="uh-meta-table">
                    <tr>
                        <td>
                            <div class="uh-meta-item">
                                <label>Receipt No.</label>
                                <div class="val"><?= h($receiptNumber) ?></div>
                            </div>
                        </td>
                        <td>
                            <div class="uh-meta-item">
                                <label>Invoice Ref.</label>
                                <div class="val"><?= h($rcp['invoice_number'] ?? '-') ?></div>
                            </div>
                        </td>
                        <td>
                            <div class="uh-meta-item">
                                <label>Date Received</label>
                                <div class="val"><?= h($paymentDate) ?></div>
                            </div>
                        </td>
                        <td>
                            <div class="uh-meta-item">
                                <label>Payment Method</label>
                                <div class="val"><?= h($paymentMethod) ?></div>
                            </div>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Received By / From -->
            <div class="uh-parties">
                <table class="uh-parties-table">
                    <tr>
                        <td>
                            <div class="uh-party-label">Received By</div>
                            <div class="uh-party-name"><?= h($companyName) ?></div>
                            <div class="uh-party-detail">
                                <?php if ($companyAddress !== ''): ?><?= h($companyAddress) ?><br><?php endif; ?>
                                <?php if ($companyTin !== ''): ?>TIN: <?= h($companyTin) ?><br><?php endif; ?>
                                <?php if ($companyPhone !== ''): ?><?= h($companyPhone) ?><?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div class="uh-party-label">Received From</div>
                            <div class="uh-party-name"><?= h($rcp['client_name'] ?? '-') ?></div>
                            <div class="uh-party-detail">
                                <?php if (!empty($rcp['client_company'])): ?><?= h($rcp['client_company']) ?><br><?php endif; ?>
                                <?php if (!empty($rcp['client_phone'])): ?><?= h($rcp['client_phone']) ?><br><?php endif; ?>
                                <?php if (!empty($rcp['client_email'])): ?><?= h($rcp['client_email']) ?><br><?php endif; ?>
                                <?php if (!empty($rcp['client_address'])): ?><?= nl2br(h($rcp['client_address'])) ?><br><?php endif; ?>
                                <?php if (!empty($rcp['client_tin'])): ?>TIN: <?= h($rcp['client_tin']) ?><?php endif; ?>
                            </div>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Items -->
            <div class="uh-table-wrap">
                <table class="uh-table">
                    <thead>
                        <tr>
                            <th style="width:35px">#</th>
                            <th>Description</th>
                            <th style="width:60px" class="r">Qty</th>
                            <th style="width:105px" class="r">Unit Price</th>
                            <th style="width:60px" class="r">VAT %</th>
                            <th style="width:110px" class="r">Amount</th>
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
                            <td>1</td>
                            <td>Payment for Invoice <?= h($rcp['invoice_number']) ?></td>
                            <td class="r">1.00</td>
                            <td class="r"><?= h(moneyFmt($invoiceTotal, $currencySymbol)) ?></td>
                            <td class="r">0.00%</td>
                            <td class="r"><?= h(moneyFmt($invoiceTotal, $currencySymbol)) ?></td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Totals -->
            <div class="uh-totals-section">
                <table class="uh-totals">
                    <tr>
                        <td class="lbl">Subtotal</td>
                        <td class="amt"><?= h(moneyFmt($computedSubtotal, $currencySymbol)) ?></td>
                    </tr>
                    <tr>
                        <td class="lbl">VAT / Tax</td>
                        <td class="amt"><?= h(moneyFmt($computedTax, $currencySymbol)) ?></td>
                    </tr>
                    <tr class="grand">
                        <td class="lbl">Invoice Total</td>
                        <td class="amt"><?= h(moneyFmt($invoiceTotal, $currencySymbol)) ?></td>
                    </tr>
                    <tr class="paid-row">
                        <td class="lbl">Amount Paid</td>
                        <td class="amt"><?= h(moneyFmt($amountPaid, $currencySymbol)) ?></td>
                    </tr>
                </table>
            </div>

            <?php if (!empty($rcp['notes'])): ?>
                <div class="uh-notes">
                    <strong>Notes:</strong><br>
                    <?= nl2br(h($rcp['notes'])) ?>
                </div>
            <?php endif; ?>

            <!-- Signature -->
            <div class="uh-sig-stamp">
                <table class="uh-signature-table">
                    <tr>
                        <td>
                            <div class="uh-sig-area-label">Authorised Signature</div>
                            <div class="uh-sig-image-area">
                                <?php if ($signatureDataUri !== ''): ?>
                                    <img src="<?= h($signatureDataUri) ?>" alt="Authorized Signature"><br>
                                <?php endif; ?>
                            </div>

                            <div class="uh-sig-underline">Authorized Signature</div>

                            <?php if ($authorizedName !== ''): ?>
                                <div class="uh-sig-name"><?= h($authorizedName) ?></div>
                            <?php else: ?>
                                <div class="uh-sig-name"><?= h($companyName) ?></div>
                            <?php endif; ?>

                            <?php if ($signedAt !== ''): ?>
                                <div class="uh-sig-date">Signed on <?= h(date('d M Y, h:i A', strtotime($signedAt))) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="uh-sig-area-label">Client Acknowledgement</div>
                            <div style="height:80px;"></div>
                            <div class="uh-sig-underline">Client Acknowledgement</div>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Footer -->
            <div class="uh-footer">
                <strong><?= h($companyName) ?></strong>
                <?php if ($companyWebsite !== ''): ?>
                    <span class="right"> | <?= h($companyWebsite) ?></span>
                <?php endif; ?>
                <br>
                This is an official receipt. Please retain for your records.
                <br>
                Generated on <?= h(date('d F Y \a\t H:i')) ?>
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

    $pdfDir = __DIR__ . '/uploads/receipts';
    if (!is_dir($pdfDir) && !mkdir($pdfDir, 0775, true) && !is_dir($pdfDir)) {
        throw new Exception('Failed to create receipts upload directory.');
    }

    $safeReceiptNo = preg_replace('/[^A-Za-z0-9\-_]/', '_', $receiptNumber);
    $pdfPath = $pdfDir . '/receipt_' . $safeReceiptNo . '.pdf';

    if (file_put_contents($pdfPath, $pdfOutput) === false) {
        throw new Exception('Failed to save generated receipt PDF.');
    }

    $subject = 'Receipt ' . $receiptNumber . ' - ' . $companyName;
    $clientName = trim((string)($rcp['client_name'] ?? 'Customer'));

    $body = '
        <p>Dear ' . h($clientName) . ',</p>
        <p>Please find attached your payment receipt.</p>
        <p>
            <strong>Receipt No:</strong> ' . h($receiptNumber) . '<br>
            <strong>Invoice No:</strong> ' . h($rcp['invoice_number']) . '<br>
            <strong>Amount Paid:</strong> ' . h(moneyFmt($amountPaid, $currencySymbol)) . '<br>
            <strong>Payment Date:</strong> ' . h($paymentDate) . '
        </p>';

    if ($authorizedName !== '') {
        $body .= '
        <p>
            <strong>Authorized Signatory:</strong> ' . h($authorizedName) . '
        </p>';
    }

    $body .= '
        <p>Thank you for your payment.</p>
        <p>Regards,<br>' . h($companyName) . '</p>
    ';

    if (!function_exists('sendEmailWithAttachment')) {
        throw new Exception('sendEmailWithAttachment() is missing in email-function.php');
    }

    $sent = sendEmailWithAttachment(
        (string)$rcp['client_email'],
        $subject,
        $body,
        $pdfPath,
        basename($pdfPath)
    );

    if (!$sent) {
        throw new Exception('Email sending failed.');
    }

    jsonResponse([
        'success' => true,
        'message' => 'Receipt email sent successfully with PDF attached.'
    ]);
} catch (Throwable $e) {
    jsonResponse([
        'success' => false,
        'error'   => $e->getMessage()
    ], 400);
}
