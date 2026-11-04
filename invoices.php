<?php
require_once 'config/db.php';

$pageTitle = 'Invoices';
$cfg = getSettings();
$pdo = getDB();

if (!function_exists('formatMoneySafe')) {
    function formatMoneySafe($amount, $currencySymbol = 'UGX ')
    {
        return $currencySymbol . number_format((float)$amount, 2);
    }
}

$invoiceLoadError = '';
$saveError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_invoice_signature') {
    try {
        $invoiceId       = (int)($_POST['invoice_id'] ?? 0);
        $authorizedName  = trim((string)($_POST['authorized_name'] ?? ''));
        $signatureType   = trim((string)($_POST['signature_type'] ?? 'draw'));
        $signatureData   = trim((string)($_POST['signature_data'] ?? ''));
        $existingPath    = trim((string)($_POST['existing_signature_path'] ?? ''));
        $signaturePath   = $existingPath;

        if ($invoiceId <= 0) {
            throw new Exception('Invalid invoice selected.');
        }

        if ($authorizedName === '') {
            throw new Exception('Authorized name is required.');
        }

        $stmt = $pdo->prepare("SELECT id, invoice_number FROM invoices WHERE id = ? LIMIT 1");
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$invoice) {
            throw new Exception('Invoice not found.');
        }

        $uploadDir = __DIR__ . '/uploads/invoice_signatures/';
        $uploadUrl = 'uploads/invoice_signatures/';

        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                throw new Exception('Failed to create invoice signature directory.');
            }
        }

        if ($signatureType === 'upload') {
            if (!empty($_FILES['signature_file']['name'])) {
                if (
                    !isset($_FILES['signature_file']['tmp_name']) ||
                    !is_uploaded_file($_FILES['signature_file']['tmp_name'])
                ) {
                    throw new Exception('Uploaded signature file is invalid.');
                }

                $allowed = [
                    'image/png'  => 'png',
                    'image/jpeg' => 'jpg',
                    'image/jpg'  => 'jpg',
                    'image/webp' => 'webp'
                ];

                $mime = mime_content_type($_FILES['signature_file']['tmp_name']);
                if (!isset($allowed[$mime])) {
                    throw new Exception('Only PNG, JPG, JPEG, or WEBP signature images are allowed.');
                }

                $ext = $allowed[$mime];
                $filename = 'invoice_' . $invoiceId . '_' . time() . '.' . $ext;
                $dest = $uploadDir . $filename;

                if (!move_uploaded_file($_FILES['signature_file']['tmp_name'], $dest)) {
                    throw new Exception('Failed to upload signature image.');
                }

                $signaturePath = $uploadUrl . $filename;
                $signatureData = '';
            } elseif ($signaturePath === '') {
                throw new Exception('Please upload a signature image.');
            }
        } else {
            $signatureType = 'draw';

            if ($signatureData === '') {
                throw new Exception('Please draw a signature.');
            }

            if (!preg_match('/^data:image\/png;base64,/', $signatureData)) {
                throw new Exception('Invalid drawn signature format.');
            }

            $raw = base64_decode(substr($signatureData, strpos($signatureData, ',') + 1), true);
            if ($raw === false) {
                throw new Exception('Failed to decode drawn signature.');
            }

            $filename = 'invoice_' . $invoiceId . '_drawn_' . time() . '.png';
            $dest = $uploadDir . $filename;

            if (file_put_contents($dest, $raw) === false) {
                throw new Exception('Failed to save drawn signature.');
            }

            $signaturePath = $uploadUrl . $filename;
        }

        $upd = $pdo->prepare("
            UPDATE invoices
            SET authorized_name = ?,
                signature_type = ?,
                signature_path = ?,
                signature_data = ?,
                signed_at = NOW()
            WHERE id = ?
        ");
        $upd->execute([
            $authorizedName,
            $signatureType,
            $signaturePath,
            ($signatureType === 'draw' ? $signatureData : null),
            $invoiceId
        ]);

        header('Location: invoices.php?signed=1');
        exit;
    } catch (Throwable $e) {
        $saveError = $e->getMessage();
    }
}

try {
    $stmt = $pdo->query("
        SELECT 
            i.id,
            i.order_id,
            i.invoice_number,
            i.issued_date,
            i.due_date,
            i.total,
            i.status,
            i.created_at,
            i.authorized_name,
            i.signature_type,
            i.signature_path,
            i.signature_data,
            i.signed_at,
            c.name AS client_name,
            c.email AS client_email,
            o.order_number,
            (
                SELECT r.id
                FROM receipts r
                WHERE r.invoice_id = i.id
                ORDER BY r.id DESC
                LIMIT 1
            ) AS receipt_id,
            (
                SELECT r.receipt_number
                FROM receipts r
                WHERE r.invoice_id = i.id
                ORDER BY r.id DESC
                LIMIT 1
            ) AS receipt_number
        FROM invoices i
        INNER JOIN orders o ON o.id = i.order_id
        INNER JOIN clients c ON c.id = o.client_id
        ORDER BY i.created_at DESC, i.id DESC
    ");
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $invoices = [];
    $invoiceLoadError = $e->getMessage();
}

$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$periodInvoices = array_values(array_filter($invoices, static function (array $invoice) use ($dateFrom, $dateTo): bool {
    $date = (string)($invoice['issued_date'] ?? '');
    return (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || $date >= $dateFrom)
        && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) || $date <= $dateTo);
}));
$totalInvoices = count($periodInvoices);
$perPage = 20;
$totalPages = max(1, (int)ceil($totalInvoices / $perPage));
$page = min($page, $totalPages);
$invoices = array_slice($periodInvoices, ($page - 1) * $perPage, $perPage);

include 'includes/header.php';
?>

<style>
.invoice-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 16px;
}

.invoice-filter-bar {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
    margin-bottom: 14px;
}

.invoice-search-wrap {
    position: relative;
    min-width: 260px;
    flex: 1;
}

.invoice-search-wrap i {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted);
}

.invoice-search-wrap input {
    padding-left: 38px;
}

.invoice-client-box {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.invoice-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.invoice-modal-backdrop,
.signature-modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.55);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 99999;
    padding: 20px;
}

.invoice-modal-backdrop.show,
.signature-modal-backdrop.show {
    display: flex;
}

.invoice-modal,
.signature-modal {
    width: 100%;
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 25px 70px rgba(0,0,0,.22);
    overflow: hidden;
}

.invoice-modal {
    max-width: 620px;
}

.signature-modal {
    max-width: 760px;
}

.invoice-modal-header,
.signature-modal-header {
    padding: 16px 20px;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
}

.invoice-modal-title,
.signature-modal-title {
    margin: 0;
    font-size: 1.05rem;
    font-weight: 700;
    color: #111827;
}

.invoice-modal-close {
    border: 0;
    background: transparent;
    font-size: 18px;
    cursor: pointer;
    color: #6b7280;
}

.invoice-modal-body,
.signature-modal-body {
    padding: 20px;
}

.invoice-modal-footer,
.signature-modal-footer {
    padding: 16px 20px;
    border-top: 1px solid #e5e7eb;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    flex-wrap: wrap;
}

.invoice-payment-summary,
.signature-summary {
    background: #f8fafc;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 14px 16px;
    margin-bottom: 16px;
}

.invoice-payment-summary div,
.signature-summary div {
    margin-bottom: 6px;
}

.invoice-payment-summary div:last-child,
.signature-summary div:last-child {
    margin-bottom: 0;
}

.invoice-toast {
    position: fixed;
    right: 18px;
    bottom: 18px;
    min-width: 260px;
    max-width: 420px;
    background: #111827;
    color: #fff;
    padding: 14px 16px;
    border-radius: 12px;
    box-shadow: 0 16px 35px rgba(0,0,0,.22);
    z-index: 100000;
    display: none;
    font-size: .95rem;
}

.invoice-toast.show {
    display: block;
}

.invoice-toast.success {
    background: #065f46;
}

.invoice-toast.error {
    background: #991b1b;
}

.signature-pad-wrap {
    border: 1px dashed #cbd5e1;
    border-radius: 14px;
    background: #fff;
    padding: 10px;
}

#invoice-signature-pad {
    width: 100%;
    height: 220px;
    display: block;
    background: #fff;
    border-radius: 10px;
    touch-action: none;
    cursor: crosshair;
}

.signature-upload-preview {
    margin-top: 10px;
    max-height: 100px;
    display: none;
}

.sign-status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: .78rem;
    padding: 6px 10px;
    border-radius: 999px;
    font-weight: 600;
}

.sign-status.signed {
    background: rgba(16,185,129,.12);
    color: #047857;
}

.sign-status.unsigned {
    background: rgba(245,158,11,.12);
    color: #b45309;
}
</style>

<div class="section-header invoice-toolbar">
    <span class="section-title">
        <i class="fas fa-file-invoice-dollar" style="margin-right:8px;color:var(--gold)"></i>
        Invoices
    </span>

    <a href="orders.php" class="btn btn-primary">
        <i class="fas fa-plus-circle"></i>
        New Order → Invoice
    </a>
</div>

<?php if ($saveError !== ''): ?>
    <div class="alert alert-danger" style="margin-bottom:16px">
        <i class="fas fa-exclamation-triangle"></i>
        <?= htmlspecialchars($saveError) ?>
    </div>
<?php endif; ?>

<?php if (isset($_GET['signed']) && $_GET['signed'] == '1'): ?>
    <div class="alert alert-success" style="margin-bottom:16px">
        <i class="fas fa-check-circle"></i>
        Invoice signature saved successfully.
    </div>
<?php endif; ?>

<?php if ($invoiceLoadError !== ''): ?>
    <div class="alert alert-danger" style="margin-bottom:16px">
        <i class="fas fa-exclamation-triangle"></i>
        Failed to load invoices: <?= htmlspecialchars($invoiceLoadError) ?>
    </div>
<?php endif; ?>

<div class="card">
    <form class="period-filter" method="get"><label>Issued from <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8') ?>"></label><label>To <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8') ?>"></label><button class="btn btn-primary btn-sm">Apply</button><a class="btn btn-ghost btn-sm" href="invoices.php">Clear</a></form>
    <div class="invoice-filter-bar">
        <div class="invoice-search-wrap">
            <i class="fas fa-search"></i>
            <input type="text" class="search-input" id="inv-search" placeholder="Search invoices, clients, emails or orders...">
        </div>

        <select id="inv-status-filter" class="search-input" style="max-width:180px">
            <option value="">All Statuses</option>
            <option value="unpaid">Unpaid</option>
            <option value="partial">Partial</option>
            <option value="paid">Paid</option>
            <option value="overdue">Overdue</option>
            <option value="cancelled">Cancelled</option>
        </select>
    </div>
    <nav class="users-pagination" aria-label="Invoice pages"><?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="<?= h('invoices.php?' . http_build_query(['date_from'=>$dateFrom,'date_to'=>$dateTo,'page'=>$page-1])) ?>">Previous</a><?php endif; ?><span class="users-page-info">Page <?= $page ?> of <?= $totalPages ?> · <?= $totalInvoices ?> invoices</span><?php if ($page < $totalPages): ?><a class="btn btn-ghost btn-sm" href="<?= h('invoices.php?' . http_build_query(['date_from'=>$dateFrom,'date_to'=>$dateTo,'page'=>$page+1])) ?>">Next</a><?php endif; ?></nav>

    <div class="table-wrap">
        <table id="inv-table">
            <thead>
                <tr>
                    <th>Invoice #</th>
                    <th>Client</th>
                    <th>Order</th>
                    <th>Issued</th>
                    <th>Due</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th>Receipt</th>
                    <th>Signature</th>
                    <th style="min-width:340px">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($invoices)): ?>
                <?php foreach ($invoices as $inv): ?>
                    <?php
                        $invoiceId     = (int)$inv['id'];
                        $invoiceTotal  = (float)($inv['total'] ?? 0);
                        $invoiceNumber = (string)($inv['invoice_number'] ?? '');
                        $status        = strtolower(trim((string)($inv['status'] ?? 'unpaid')));
                        $status        = $status !== '' ? $status : 'unpaid';
                        $receiptId     = (int)($inv['receipt_id'] ?? 0);
                        $receiptNumber = (string)($inv['receipt_number'] ?? '');
                        $isSigned      = !empty($inv['authorized_name']) || !empty($inv['signature_path']);

                        $badgeClass = 'info';
                        if ($status === 'paid') {
                            $badgeClass = 'success';
                        } elseif ($status === 'partial') {
                            $badgeClass = 'warning';
                        } elseif ($status === 'overdue') {
                            $badgeClass = 'danger';
                        } elseif ($status === 'cancelled') {
                            $badgeClass = 'secondary';
                        }

                        $searchText = strtolower(
                            $invoiceNumber . ' ' .
                            ($inv['client_name'] ?? '') . ' ' .
                            ($inv['client_email'] ?? '') . ' ' .
                            ($inv['order_number'] ?? '') . ' ' .
                            ($inv['authorized_name'] ?? '') . ' ' .
                            $status
                        );
                    ?>
                    <tr data-status="<?= htmlspecialchars($status) ?>" data-search="<?= htmlspecialchars($searchText) ?>">
                        <td>
                            <a href="invoice_view.php?id=<?= $invoiceId ?>" style="color:var(--gold);font-weight:600;text-decoration:none">
                                <?= htmlspecialchars($invoiceNumber) ?>
                            </a>
                        </td>

                        <td>
                            <div class="invoice-client-box">
                                <span>
                                    <?= htmlspecialchars($inv['client_name'] ?? 'Unknown Client') ?>
                                </span>
                                <?php if (!empty($inv['client_email'])): ?>
                                    <small style="color:var(--text-muted)">
                                         <?= htmlspecialchars($inv['client_email']) ?>
                                    </small>
                                <?php endif; ?>
                            </div>
                        </td>

                        <td style="font-size:.86rem;color:var(--text-muted)">
                            <?= htmlspecialchars($inv['order_number'] ?? '-') ?>
                        </td>

                        <td>
                            <?php if (!empty($inv['issued_date'])): ?>
                                <?= htmlspecialchars(date('d/m/Y', strtotime($inv['issued_date']))) ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if (!empty($inv['due_date'])): ?>
                                <?= htmlspecialchars(date('d/m/Y', strtotime($inv['due_date']))) ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>

                        <td>
                            <strong>
                                <?= function_exists('formatMoney')
                                    ? formatMoney($invoiceTotal, $cfg['currency_symbol'] ?? 'UGX ')
                                    : formatMoneySafe($invoiceTotal, $cfg['currency_symbol'] ?? 'UGX ') ?>
                            </strong>
                        </td>

                        <td>
                            <span class="badge badge-<?= htmlspecialchars($badgeClass) ?>">
                                <?= ucfirst(htmlspecialchars($status)) ?>
                            </span>
                        </td>

                        <td>
                            <?php if ($receiptId > 0): ?>
                                <a href="receipt_view.php?id=<?= $receiptId ?>" class="btn btn-outline btn-sm">
                                    <?= htmlspecialchars($receiptNumber !== '' ? $receiptNumber : 'View Receipt') ?>
                                </a>
                            <?php elseif (in_array($status, ['unpaid', 'partial', 'overdue'], true)): ?>
                                <button
                                    type="button"
                                    class="btn btn-primary btn-sm js-mark-paid"
                                    data-id="<?= $invoiceId ?>"
                                    data-number="<?= htmlspecialchars($invoiceNumber) ?>"
                                    data-total="<?= htmlspecialchars((string)$invoiceTotal) ?>"
                                >
                                    Mark Paid
                                </button>
                            <?php else: ?>
                                <span style="color:var(--text-dim);font-size:.82rem">-</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if ($isSigned): ?>
                                <span class="sign-status signed">
                                    Signed
                                </span>
                                <?php if (!empty($inv['authorized_name'])): ?>
                                    <div style="margin-top:6px;font-size:.82rem;color:var(--text-muted)">
                                        <?= htmlspecialchars($inv['authorized_name']) ?>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="sign-status unsigned">
                                    Not signed
                                </span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <div class="invoice-actions">
                                <a href="invoice_view.php?id=<?= $invoiceId ?>" class="btn btn-ghost btn-sm" title="View invoice" aria-label="View invoice">
                                    <i class="fas fa-eye"></i>
                                </a>

                                <a href="delivery_notes.php?view=<?= (int)$inv['order_id'] ?>" class="btn btn-ghost btn-sm" title="Delivery note" aria-label="Delivery note">
                                    <i class="fas fa-truck"></i>
                                </a>

                                <button
                                    type="button"
                                    class="btn btn-outline btn-sm open-invoice-signature-modal"
                                    title="<?= $isSigned ? 'Update signature' : 'Add signature' ?>" aria-label="<?= $isSigned ? 'Update signature' : 'Add signature' ?>"
                                    data-id="<?= $invoiceId ?>"
                                    data-number="<?= htmlspecialchars($invoiceNumber) ?>"
                                    data-client="<?= htmlspecialchars($inv['client_name'] ?? '') ?>"
                                    data-amount="<?= htmlspecialchars((string)$invoiceTotal) ?>"
                                    data-authorized="<?= htmlspecialchars((string)($inv['authorized_name'] ?? '')) ?>"
                                    data-signature-path="<?= htmlspecialchars((string)($inv['signature_path'] ?? '')) ?>"
                                >
                                    <i class="fas fa-signature"></i>
                                </button>

                                <button type="button" class="btn btn-outline btn-sm" title="Send email" aria-label="Send email" onclick="sendInvoiceEmail(<?= $invoiceId ?>, this)">
                                    <i class="fas fa-paper-plane"></i>
                                </button>

                                <?php if (in_array($status, ['unpaid', 'partial', 'overdue'], true)): ?>
                                    <button type="button" class="btn btn-danger btn-sm" title="Cancel invoice" aria-label="Cancel invoice" onclick="cancelInvoice(<?= $invoiceId ?>)">
                                        <i class="fas fa-times-circle"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="10">
                        <div class="empty-state" style="padding:28px;text-align:center">
                            <i class="fas fa-file-invoice-dollar" style="font-size:42px;color:var(--text-muted);margin-bottom:12px"></i>
                            <p style="margin:0">No invoices found.</p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="paymentModalBackdrop" class="invoice-modal-backdrop">
    <div class="invoice-modal">
        <div class="invoice-modal-header">
            <h3 class="invoice-modal-title">
                <i class="fas fa-money-check-alt" style="margin-right:8px;color:var(--gold)"></i>
                Record Payment
            </h3>
            <button type="button" class="invoice-modal-close" id="paymentModalClose">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="invoice-modal-body">
            <div class="invoice-payment-summary">
                <div>Invoice: <strong id="modalInvoiceNumber">-</strong></div>
                <div>Total: <strong id="modalInvoiceTotal">-</strong></div>
            </div>

            <input type="hidden" id="modalInvoiceId">

            <div class="form-grid" style="gap:14px">
                <div class="form-group">
                    <label for="pay-amount">Amount Paid</label>
                    <input type="number" id="pay-amount" min="0" step="0.01">
                </div>

                <div class="form-group">
                    <label for="pay-date">Payment Date</label>
                    <input type="date" id="pay-date">
                </div>

                <div class="form-group">
                    <label for="pay-method">Payment Method</label>
                    <select id="pay-method">
                        <option value="cash">Cash</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="mobile_money">Mobile Money</option>
                        <option value="cheque">Cheque</option>
                        <option value="card">Card</option>
                        <option value="other">Other</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="pay-ref">Reference / Transaction ID</label>
                    <input type="text" id="pay-ref" placeholder="Optional reference number">
                </div>

                <div class="form-group" style="grid-column:1/-1">
                    <label for="pay-notes">Notes</label>
                    <textarea id="pay-notes" rows="3" placeholder="Payment notes..."></textarea>
                </div>
            </div>
        </div>

        <div class="invoice-modal-footer">
            <button type="button" class="btn btn-ghost" id="paymentModalCancel">
                <i class="fas fa-times"></i>
                Cancel
            </button>
            <button type="button" class="btn btn-primary" id="confirmPayBtn">
                <i class="fas fa-check"></i>
                Confirm Payment
            </button>
        </div>
    </div>
</div>

<div id="invoiceSignatureModalBackdrop" class="signature-modal-backdrop">
    <div class="signature-modal">
        <div class="signature-modal-header">
            <h3 class="signature-modal-title">
                <i class="fas fa-signature" style="margin-right:8px;color:var(--gold)"></i>
                Invoice Authorized Signature
            </h3>
            <button type="button" class="invoice-modal-close" id="closeInvoiceSignatureModal">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <form method="post" enctype="multipart/form-data" id="invoiceSignatureForm">
            <input type="hidden" name="action" value="save_invoice_signature">
            <input type="hidden" name="invoice_id" id="signature_invoice_id">
            <input type="hidden" name="signature_type" id="invoice_signature_type" value="draw">
            <input type="hidden" name="signature_data" id="invoice_signature_data">
            <input type="hidden" name="existing_signature_path" id="invoice_existing_signature_path">

            <div class="signature-modal-body">
                <div class="signature-summary">
                    <div>Invoice: <strong id="signature_invoice_number">—</strong></div>
                    <div>Client: <strong id="signature_client_name">—</strong></div>
                    <div>Total: <strong id="signature_invoice_total">—</strong></div>
                </div>

                <div class="form-grid" style="gap:14px">
                    <div class="form-group" style="grid-column:1/-1">
                        <label for="invoice_authorized_name">Authorized Name</label>
                        <input type="text" name="authorized_name" id="invoice_authorized_name" required placeholder="Enter authorized signatory name">
                    </div>

                    <div class="form-group" style="grid-column:1/-1">
                        <label>Signature Option</label>
                        <div style="display:flex;gap:18px;flex-wrap:wrap">
                            <label style="display:flex;align-items:center;gap:8px">
                                <input type="radio" name="invoice_signature_option" value="draw" checked>
                                Draw Signature
                            </label>
                            <label style="display:flex;align-items:center;gap:8px">
                                <input type="radio" name="invoice_signature_option" value="upload">
                                Upload Signature
                            </label>
                        </div>
                    </div>

                    <div class="form-group" id="invoice-draw-signature-group" style="grid-column:1/-1">
                        <label>Draw Signature</label>
                        <div class="signature-pad-wrap">
                            <canvas id="invoice-signature-pad"></canvas>
                        </div>
                        <div style="display:flex;justify-content:flex-end;margin-top:10px">
                            <button type="button" class="btn btn-outline btn-sm" id="clearInvoiceSignaturePad">
                                <i class="fas fa-eraser"></i>
                                Clear
                            </button>
                        </div>
                    </div>

                    <div class="form-group" id="invoice-upload-signature-group" style="grid-column:1/-1;display:none">
                        <label for="invoice_signature_file">Upload Signature Image</label>
                        <input type="file" name="signature_file" id="invoice_signature_file" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp">
                        <img id="invoiceSignatureUploadPreview" class="signature-upload-preview" alt="Signature preview">
                    </div>
                </div>
            </div>

            <div class="signature-modal-footer">
                <button type="button" class="btn btn-ghost" id="cancelInvoiceSignatureModal">
                    <i class="fas fa-times"></i>
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i>
                    Save Signature
                </button>
            </div>
        </form>
    </div>
</div>

<div id="invoiceToast" class="invoice-toast"></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const searchInput   = document.getElementById('inv-search');
    const statusFilter  = document.getElementById('inv-status-filter');
    const tableRows     = document.querySelectorAll('#inv-table tbody tr');

    const modalBackdrop = document.getElementById('paymentModalBackdrop');
    const modalClose    = document.getElementById('paymentModalClose');
    const modalCancel   = document.getElementById('paymentModalCancel');
    const confirmPayBtn = document.getElementById('confirmPayBtn');

    const modalInvoiceId     = document.getElementById('modalInvoiceId');
    const modalInvoiceNumber = document.getElementById('modalInvoiceNumber');
    const modalInvoiceTotal  = document.getElementById('modalInvoiceTotal');

    const payAmount = document.getElementById('pay-amount');
    const payDate   = document.getElementById('pay-date');
    const payMethod = document.getElementById('pay-method');
    const payRef    = document.getElementById('pay-ref');
    const payNotes  = document.getElementById('pay-notes');

    function showToast(message, type) {
        const toast = document.getElementById('invoiceToast');
        toast.className = 'invoice-toast ' + (type || 'success');
        toast.textContent = message;
        toast.classList.add('show');

        setTimeout(function () {
            toast.classList.remove('show');
        }, 3500);
    }

    function applyFilters() {
        const q = (searchInput.value || '').toLowerCase().trim();
        const s = (statusFilter.value || '').toLowerCase().trim();

        tableRows.forEach(function (row) {
            const rowStatus = (row.getAttribute('data-status') || '').toLowerCase();
            const rowSearch = (row.getAttribute('data-search') || '').toLowerCase();

            const okSearch = !q || rowSearch.indexOf(q) !== -1;
            const okStatus = !s || rowStatus === s;

            row.style.display = (okSearch && okStatus) ? '' : 'none';
        });
    }

    function openPaymentModal(invoiceId, invoiceNumber, invoiceTotal) {
        modalInvoiceId.value = invoiceId;
        modalInvoiceNumber.textContent = invoiceNumber;
        modalInvoiceTotal.textContent = invoiceTotal;

        payAmount.value = parseFloat(invoiceTotal).toFixed(2);

        const now = new Date();
        const yyyy = now.getFullYear();
        const mm = String(now.getMonth() + 1).padStart(2, '0');
        const dd = String(now.getDate()).padStart(2, '0');
        payDate.value = yyyy + '-' + mm + '-' + dd;

        payMethod.value = 'cash';
        payRef.value = '';
        payNotes.value = '';

        modalBackdrop.classList.add('show');
        document.body.style.overflow = 'hidden';
    }

    function closePaymentModal() {
        modalBackdrop.classList.remove('show');
        document.body.style.overflow = '';
    }

    if (searchInput) {
        searchInput.addEventListener('input', applyFilters);
    }

    if (statusFilter) {
        statusFilter.addEventListener('change', applyFilters);
    }

    document.querySelectorAll('.js-mark-paid').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const invoiceId = this.getAttribute('data-id');
            const invoiceNumber = this.getAttribute('data-number');
            const invoiceTotal = this.getAttribute('data-total');
            openPaymentModal(invoiceId, invoiceNumber, invoiceTotal);
        });
    });

    if (modalClose) {
        modalClose.addEventListener('click', closePaymentModal);
    }

    if (modalCancel) {
        modalCancel.addEventListener('click', closePaymentModal);
    }

    if (modalBackdrop) {
        modalBackdrop.addEventListener('click', function (e) {
            if (e.target === modalBackdrop) {
                closePaymentModal();
            }
        });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modalBackdrop.classList.contains('show')) {
            closePaymentModal();
        }
    });

    if (confirmPayBtn) {
        confirmPayBtn.addEventListener('click', async function () {
            const invoiceId = parseInt(modalInvoiceId.value || '0', 10);
            const amount = parseFloat(payAmount.value || '0');
            const paymentDate = payDate.value || '';
            const paymentMethod = payMethod.value || '';
            const reference = payRef.value || '';
            const notes = payNotes.value || '';

            if (!invoiceId) {
                showToast('Missing invoice ID.', 'error');
                return;
            }

            if (!amount || amount <= 0) {
                showToast('Enter a valid payment amount.', 'error');
                payAmount.focus();
                return;
            }

            if (!paymentDate) {
                showToast('Select payment date.', 'error');
                payDate.focus();
                return;
            }

            if (!paymentMethod) {
                showToast('Select payment method.', 'error');
                payMethod.focus();
                return;
            }

            const originalHtml = confirmPayBtn.innerHTML;
            confirmPayBtn.disabled = true;
            confirmPayBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

            try {
                const response = await fetch('api/payments.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        invoice_id: invoiceId,
                        amount_paid: amount,
                        payment_date: paymentDate,
                        payment_method: paymentMethod,
                        reference: reference,
                        notes: notes
                    })
                });

                const raw = await response.text();
                let data = null;

                try {
                    data = JSON.parse(raw);
                } catch (err) {
                    console.error('Invalid JSON from payments.php:', raw);
                    throw new Error('Invalid server response from payments.php');
                }

                if (!response.ok || !data.success) {
                    throw new Error(data.error || data.message || 'Failed to record payment');
                }

                showToast(data.message || 'Payment recorded successfully', 'success');
                closePaymentModal();

                if (data.receipt_id) {
                    window.location.href = 'receipt_view.php?id=' + encodeURIComponent(data.receipt_id);
                } else {
                    window.location.reload();
                }
            } catch (error) {
                console.error(error);
                showToast(error.message || 'Payment failed.', 'error');
            } finally {
                confirmPayBtn.disabled = false;
                confirmPayBtn.innerHTML = originalHtml;
            }
        });
    }

    const signatureBackdrop = document.getElementById('invoiceSignatureModalBackdrop');
    const closeSignatureBtn = document.getElementById('closeInvoiceSignatureModal');
    const cancelSignatureBtn = document.getElementById('cancelInvoiceSignatureModal');
    const clearSignatureBtn = document.getElementById('clearInvoiceSignaturePad');
    const signatureForm = document.getElementById('invoiceSignatureForm');

    const signatureInvoiceId = document.getElementById('signature_invoice_id');
    const invoiceAuthorizedName = document.getElementById('invoice_authorized_name');
    const invoiceSignatureType = document.getElementById('invoice_signature_type');
    const invoiceSignatureData = document.getElementById('invoice_signature_data');
    const invoiceExistingSignaturePath = document.getElementById('invoice_existing_signature_path');

    const signatureInvoiceNumber = document.getElementById('signature_invoice_number');
    const signatureClientName = document.getElementById('signature_client_name');
    const signatureInvoiceTotal = document.getElementById('signature_invoice_total');

    const drawGroup = document.getElementById('invoice-draw-signature-group');
    const uploadGroup = document.getElementById('invoice-upload-signature-group');
    const invoiceSignatureFile = document.getElementById('invoice_signature_file');
    const invoiceSignaturePreview = document.getElementById('invoiceSignatureUploadPreview');

    const canvas = document.getElementById('invoice-signature-pad');
    const ctx = canvas.getContext('2d', { willReadFrequently: true });

    let isDrawing = false;
    let hasSignature = false;
    let lastX = 0;
    let lastY = 0;

    function prepareCanvas() {
        const dpr = window.devicePixelRatio || 1;
        const displayWidth = canvas.clientWidth || 600;
        const displayHeight = 220;

        canvas.width = Math.floor(displayWidth * dpr);
        canvas.height = Math.floor(displayHeight * dpr);

        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.scale(dpr, dpr);

        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, displayWidth, displayHeight);

        ctx.lineWidth = 2.2;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.strokeStyle = '#111827';
    }

    function clearCanvas() {
        const displayWidth = canvas.clientWidth || 600;
        const displayHeight = 220;

        ctx.setTransform(1, 0, 0, 1, 0, 0);
        const dpr = window.devicePixelRatio || 1;
        ctx.scale(dpr, dpr);
        ctx.clearRect(0, 0, displayWidth, displayHeight);
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, displayWidth, displayHeight);
        ctx.lineWidth = 2.2;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.strokeStyle = '#111827';

        hasSignature = false;
        invoiceSignatureData.value = '';
    }

    function getPoint(event) {
        const rect = canvas.getBoundingClientRect();

        if (event.touches && event.touches.length > 0) {
            return {
                x: event.touches[0].clientX - rect.left,
                y: event.touches[0].clientY - rect.top
            };
        }

        if (event.changedTouches && event.changedTouches.length > 0) {
            return {
                x: event.changedTouches[0].clientX - rect.left,
                y: event.changedTouches[0].clientY - rect.top
            };
        }

        return {
            x: event.clientX - rect.left,
            y: event.clientY - rect.top
        };
    }

    function startDrawing(event) {
        const point = getPoint(event);
        isDrawing = true;
        hasSignature = true;
        lastX = point.x;
        lastY = point.y;

        ctx.beginPath();
        ctx.moveTo(lastX, lastY);
        event.preventDefault();
    }

    function draw(event) {
        if (!isDrawing) return;

        const point = getPoint(event);

        ctx.beginPath();
        ctx.moveTo(lastX, lastY);
        ctx.lineTo(point.x, point.y);
        ctx.stroke();

        lastX = point.x;
        lastY = point.y;

        event.preventDefault();
    }

    function stopDrawing(event) {
        if (!isDrawing) return;
        isDrawing = false;
        event.preventDefault();
    }

    prepareCanvas();

    function openSignatureModal(data) {
        signatureInvoiceId.value = data.id || '';
        invoiceAuthorizedName.value = data.authorized || '';
        invoiceExistingSignaturePath.value = data.signaturePath || '';

        signatureInvoiceNumber.textContent = data.number || '—';
        signatureClientName.textContent = data.client || '—';
        signatureInvoiceTotal.textContent = data.amount || '—';

        invoiceSignatureType.value = 'draw';
        document.querySelector('input[name="invoice_signature_option"][value="draw"]').checked = true;
        drawGroup.style.display = '';
        uploadGroup.style.display = 'none';

        invoiceSignatureFile.value = '';
        invoiceSignaturePreview.style.display = 'none';
        invoiceSignaturePreview.removeAttribute('src');

        prepareCanvas();
        clearCanvas();

        signatureBackdrop.classList.add('show');
        document.body.style.overflow = 'hidden';
    }

    function closeSignatureModal() {
        signatureBackdrop.classList.remove('show');
        document.body.style.overflow = '';
    }

    document.querySelectorAll('.open-invoice-signature-modal').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openSignatureModal({
                id: this.dataset.id || '',
                number: this.dataset.number || '',
                client: this.dataset.client || '',
                amount: this.dataset.amount || '',
                authorized: this.dataset.authorized || '',
                signaturePath: this.dataset.signaturePath || ''
            });
        });
    });

    closeSignatureBtn.addEventListener('click', closeSignatureModal);
    cancelSignatureBtn.addEventListener('click', closeSignatureModal);

    signatureBackdrop.addEventListener('click', function (e) {
        if (e.target === signatureBackdrop) {
            closeSignatureModal();
        }
    });

    clearSignatureBtn.addEventListener('click', function () {
        clearCanvas();
    });

    document.querySelectorAll('input[name="invoice_signature_option"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            if (this.value === 'upload') {
                invoiceSignatureType.value = 'upload';
                drawGroup.style.display = 'none';
                uploadGroup.style.display = '';
            } else {
                invoiceSignatureType.value = 'draw';
                drawGroup.style.display = '';
                uploadGroup.style.display = 'none';
            }
        });
    });

    invoiceSignatureFile.addEventListener('change', function () {
        const file = this.files && this.files[0] ? this.files[0] : null;

        if (!file) {
            invoiceSignaturePreview.style.display = 'none';
            invoiceSignaturePreview.removeAttribute('src');
            return;
        }

        const reader = new FileReader();
        reader.onload = function (e) {
            invoiceSignaturePreview.src = e.target.result;
            invoiceSignaturePreview.style.display = 'block';
        };
        reader.readAsDataURL(file);
    });

    canvas.addEventListener('mousedown', startDrawing);
    canvas.addEventListener('mousemove', draw);
    canvas.addEventListener('mouseup', stopDrawing);
    canvas.addEventListener('mouseleave', stopDrawing);

    canvas.addEventListener('touchstart', startDrawing, { passive: false });
    canvas.addEventListener('touchmove', draw, { passive: false });
    canvas.addEventListener('touchend', stopDrawing, { passive: false });
    canvas.addEventListener('touchcancel', stopDrawing, { passive: false });

    signatureForm.addEventListener('submit', function (e) {
        if (invoiceSignatureType.value === 'draw') {
            if (!hasSignature) {
                e.preventDefault();
                alert('Please draw a signature before saving.');
                return;
            }

            invoiceSignatureData.value = canvas.toDataURL('image/png');
            if (!invoiceSignatureData.value) {
                e.preventDefault();
                alert('Failed to capture signature. Please draw again.');
            }
        } else {
            if (!invoiceSignatureFile.files.length && !invoiceExistingSignaturePath.value) {
                e.preventDefault();
                alert('Please upload a signature image before saving.');
            }
        }
    });
});

async function cancelInvoice(id) {
    if (!confirm('Cancel this invoice?')) return;

    try {
        const response = await fetch('api/invoices.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                action: 'cancel',
                id: Number(id)
            })
        });

        const raw = await response.text();
        let data = null;

        try {
            data = JSON.parse(raw);
        } catch (e) {
            console.error(raw);
            throw new Error('Invalid server response from api/invoices.php');
        }

        if (!response.ok || !data.success) {
            throw new Error(data.error || data.message || 'Failed to cancel invoice');
        }

        alert(data.message || 'Invoice cancelled successfully');
        window.location.reload();
    } catch (error) {
        alert(error.message || 'Failed to cancel invoice');
    }
}

async function sendInvoiceEmail(invoiceId, btn) {
    const originalHtml = btn ? btn.innerHTML : '';

    try {
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
        }

        const response = await fetch('send_invoice.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ invoice_id: Number(invoiceId) })
        });

        const raw = await response.text();
        let data = null;

        try {
            data = JSON.parse(raw);
        } catch (e) {
            console.error(raw);
            throw new Error('Invalid response from send_invoice.php');
        }

        if (!response.ok || !data.success) {
            throw new Error(data.error || data.message || 'Failed to send invoice email');
        }

        alert(data.message || 'Invoice email sent successfully');
    } catch (error) {
        alert(error.message || 'Failed to send invoice email');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    }
}
</script>

<?php include 'includes/footer.php'; ?>
