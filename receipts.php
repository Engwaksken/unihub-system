<?php
require_once 'config/db.php';

$pageTitle = 'Receipts';
$cfg = getSettings();
$pdo = getDB();

if (!function_exists('formatMoneySafe')) {
    function formatMoneySafe($amount, $currencySymbol = 'UGX ')
    {
        return $currencySymbol . number_format((float)$amount, 2);
    }
}

$saveError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_signature') {
    try {
        $receiptId      = (int)($_POST['receipt_id'] ?? 0);
        $authorizedName = trim((string)($_POST['authorized_name'] ?? ''));
        $signatureType  = trim((string)($_POST['signature_type'] ?? 'draw'));
        $signatureData  = trim((string)($_POST['signature_data'] ?? ''));
        $existingPath   = trim((string)($_POST['existing_signature_path'] ?? ''));
        $signaturePath  = $existingPath;

        if ($receiptId <= 0) {
            throw new Exception('Invalid receipt selected.');
        }

        if ($authorizedName === '') {
            throw new Exception('Authorized name is required.');
        }

        $stmt = $pdo->prepare("SELECT id, receipt_number FROM receipts WHERE id = ? LIMIT 1");
        $stmt->execute([$receiptId]);
        $receipt = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$receipt) {
            throw new Exception('Receipt not found.');
        }

        $uploadDir = __DIR__ . '/uploads/receipt_signatures/';
        $uploadUrl = 'uploads/receipt_signatures/';

        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                throw new Exception('Failed to create signature upload directory.');
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
                $filename = 'receipt_' . $receiptId . '_' . time() . '.' . $ext;
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

            $filename = 'receipt_' . $receiptId . '_drawn_' . time() . '.png';
            $dest = $uploadDir . $filename;

            if (file_put_contents($dest, $raw) === false) {
                throw new Exception('Failed to save drawn signature.');
            }

            $signaturePath = $uploadUrl . $filename;
        }

        $upd = $pdo->prepare("
            UPDATE receipts
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
            $receiptId
        ]);

        header('Location: receipt_view.php?id=' . $receiptId . '&signed=1');
        exit;
    } catch (Throwable $e) {
        $saveError = $e->getMessage();
    }
}

$receipts = $pdo->query("
    SELECT 
        r.*,
        i.invoice_number,
        i.total AS invoice_total,
        c.name AS client_name,
        c.email AS client_email,
        o.order_number
    FROM receipts r
    JOIN invoices i ON i.id = r.invoice_id
    JOIN orders   o ON o.id = i.order_id
    JOIN clients  c ON c.id = o.client_id
    ORDER BY r.created_at DESC, r.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$periodReceipts = array_values(array_filter($receipts, static function (array $receipt) use ($dateFrom, $dateTo): bool {
    $date = (string)($receipt['payment_date'] ?? '');
    return (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || $date >= $dateFrom)
        && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) || $date <= $dateTo);
}));
$totalReceipts = count($periodReceipts);
$perPage = 20;
$totalPages = max(1, (int)ceil($totalReceipts / $perPage));
$page = min($page, $totalPages);
$receipts = array_slice($periodReceipts, ($page - 1) * $perPage, $perPage);

include 'includes/header.php';
?>

<style>
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
.signature-modal-backdrop.show {
    display: flex;
}
.signature-modal {
    width: 100%;
    max-width: 760px;
    background: #fff;
    border-radius: 18px;
    box-shadow: 0 25px 60px rgba(0,0,0,.22);
    overflow: hidden;
}
.signature-modal-header,
.signature-modal-footer {
    padding: 16px 20px;
    border-bottom: 1px solid #e5e7eb;
}
.signature-modal-footer {
    border-bottom: 0;
    border-top: 1px solid #e5e7eb;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    flex-wrap: wrap;
}
.signature-modal-title {
    margin: 0;
    font-size: 1.05rem;
    font-weight: 700;
}
.signature-modal-body {
    padding: 20px;
}
.signature-summary {
    background: #f8fafc;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 14px 16px;
    margin-bottom: 16px;
}
.signature-summary div {
    margin-bottom: 6px;
}
.signature-summary div:last-child {
    margin-bottom: 0;
}
.signature-pad-wrap {
    border: 1px dashed #cbd5e1;
    border-radius: 14px;
    background: #fff;
    padding: 10px;
}
#signature-pad {
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
.receipt-sign-status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: .78rem;
    padding: 6px 10px;
    border-radius: 999px;
    font-weight: 600;
}
.receipt-sign-status.signed {
    background: rgba(16,185,129,.12);
    color: #047857;
}
.receipt-sign-status.unsigned {
    background: rgba(245,158,11,.12);
    color: #b45309;
}
</style>

<div class="section-header" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
    <span class="section-title">
        <i class="fas fa-receipt" style="margin-right:8px;color:var(--green)"></i>
        Payment Receipts
    </span>
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
        Authorized signature saved successfully.
    </div>
<?php endif; ?>

<div class="card">
    <form class="period-filter" method="get"><label>Payment date from <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8') ?>"></label><label>To <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8') ?>"></label><button class="btn btn-primary btn-sm">Apply</button><a class="btn btn-ghost btn-sm" href="receipts.php">Clear</a></form>
    <div class="filter-bar">
        <div style="position:relative;max-width:420px;width:100%">
            <i class="fas fa-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-muted)"></i>
            <input class="search-input" id="rcp-search" placeholder="Search receipts…" style="padding-left:38px">
        </div>
    </div>

    <div class="table-wrap">
        <table id="rcp-table">
            <thead>
                <tr>
                    <th>Receipt #</th>
                    <th>Invoice #</th>
                    <th>Client</th>
                    <th>Payment Date</th>
                    <th>Amount Paid</th>
                    <th>Method</th>
                    <th>Reference</th>
                    <th>Authorized Signature</th>
                    <th style="min-width:280px">Actions</th>
                </tr>
            </thead>

            <tbody>
            <?php if (!empty($receipts)): ?>
                <?php foreach ($receipts as $r): ?>
                <?php
                    $isSigned = !empty($r['authorized_name']) || !empty($r['signature_path']);
                ?>
                <tr>
                    <td>
                        <strong style="color:var(--green)">
                            <i class="fas fa-file-invoice-dollar" style="margin-right:6px"></i>
                            <?= htmlspecialchars($r['receipt_number']) ?>
                        </strong>
                    </td>

                    <td>
                        <a href="invoice_view.php?id=<?= (int)$r['invoice_id'] ?>" style="color:var(--gold);text-decoration:none">
                            <i class="fas fa-file-invoice" style="margin-right:6px"></i>
                            <?= htmlspecialchars($r['invoice_number']) ?>
                        </a>
                    </td>

                    <td>
                        <div style="display:flex;flex-direction:column;gap:4px">
                            <span>
                                <i class="fas fa-user" style="margin-right:6px;color:var(--text-muted)"></i>
                                <?= htmlspecialchars($r['client_name']) ?>
                            </span>
                            <?php if (!empty($r['client_email'])): ?>
                                <small style="color:var(--text-muted)">
                                    <i class="fas fa-envelope" style="margin-right:6px"></i>
                                    <?= htmlspecialchars($r['client_email']) ?>
                                </small>
                            <?php endif; ?>
                        </div>
                    </td>

                    <td>
                        <i class="fas fa-calendar-alt" style="margin-right:6px;color:var(--text-muted)"></i>
                        <?= !empty($r['payment_date']) ? date('d/m/Y', strtotime($r['payment_date'])) : '—' ?>
                    </td>

                    <td>
                        <strong>
                            <i class="fas fa-coins" style="margin-right:6px;color:var(--green)"></i>
                            <?= function_exists('formatMoney')
                                ? formatMoney($r['amount_paid'], $cfg['currency_symbol'] ?? 'UGX ')
                                : formatMoneySafe($r['amount_paid'], $cfg['currency_symbol'] ?? 'UGX ') ?>
                        </strong>
                    </td>

                    <td>
                        <span class="badge badge-invoiced" style="font-size:.72rem">
                            <i class="fas fa-money-check-alt" style="margin-right:5px"></i>
                            <?= ucwords(str_replace('_', ' ', (string)$r['payment_method'])) ?>
                        </span>
                    </td>

                    <td style="font-size:.82rem;color:var(--text-muted)">
                        <?= htmlspecialchars($r['reference'] ?: '—') ?>
                    </td>

                    <td>
                        <?php if ($isSigned): ?>
                            <span class="receipt-sign-status signed">
                                <i class="fas fa-signature"></i>
                                Signed
                            </span>
                            <?php if (!empty($r['authorized_name'])): ?>
                                <div style="margin-top:6px;font-size:.82rem;color:var(--text-muted)">
                                    <?= htmlspecialchars($r['authorized_name']) ?>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="receipt-sign-status unsigned">
                                <i class="fas fa-pen-nib"></i>
                                Not signed
                            </span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <div style="display:flex;gap:8px;flex-wrap:wrap">
                            <a href="receipt_view.php?id=<?= (int)$r['id'] ?>" class="btn btn-ghost btn-sm">
                                <i class="fas fa-eye"></i>
                                View / Print
                            </a>

                            <button
                                type="button"
                                class="btn btn-primary btn-sm open-signature-modal"
                                title="<?= $isSigned ? 'Update signature' : 'Add signature' ?>" aria-label="<?= $isSigned ? 'Update signature' : 'Add signature' ?>"
                                data-id="<?= (int)$r['id'] ?>"
                                data-number="<?= htmlspecialchars($r['receipt_number']) ?>"
                                data-client="<?= htmlspecialchars($r['client_name']) ?>"
                                data-amount="<?= htmlspecialchars((string)$r['amount_paid']) ?>"
                                data-authorized="<?= htmlspecialchars((string)($r['authorized_name'] ?? '')) ?>"
                                data-signature-path="<?= htmlspecialchars((string)($r['signature_path'] ?? '')) ?>"
                            >
                                <i class="fas fa-signature"></i>
                            </button>

                            <button type="button" class="btn btn-outline btn-sm" title="Send email" aria-label="Send email" onclick="sendReceiptEmail(<?= (int)$r['id'] ?>, this)">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="9">
                        <div class="empty-state">
                            <i class="fas fa-receipt" style="font-size:42px;color:var(--text-muted);margin-bottom:12px"></i>
                            <p>No receipts yet. Mark an invoice as paid to generate a receipt.</p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <nav class="users-pagination" aria-label="Receipt pages"><?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="<?= h('receipts.php?' . http_build_query(['date_from'=>$dateFrom,'date_to'=>$dateTo,'page'=>$page-1])) ?>">Previous</a><?php endif; ?><span class="users-page-info">Page <?= $page ?> of <?= $totalPages ?> · <?= $totalReceipts ?> receipts</span><?php if ($page < $totalPages): ?><a class="btn btn-ghost btn-sm" href="<?= h('receipts.php?' . http_build_query(['date_from'=>$dateFrom,'date_to'=>$dateTo,'page'=>$page+1])) ?>">Next</a><?php endif; ?></nav>
</div>

<div id="signatureModalBackdrop" class="signature-modal-backdrop">
    <div class="signature-modal">
        <div class="signature-modal-header" style="display:flex;justify-content:space-between;align-items:center;gap:12px">
            <h3 class="signature-modal-title">
                <i class="fas fa-signature" style="margin-right:8px;color:var(--green)"></i>
                Authorized Signature
            </h3>
            <button type="button" id="closeSignatureModal" class="btn btn-ghost btn-sm">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <form method="post" enctype="multipart/form-data" id="signatureForm">
            <input type="hidden" name="action" value="save_signature">
            <input type="hidden" name="receipt_id" id="modal_receipt_id">
            <input type="hidden" name="signature_type" id="signature_type" value="draw">
            <input type="hidden" name="signature_data" id="signature_data">
            <input type="hidden" name="existing_signature_path" id="existing_signature_path">

            <div class="signature-modal-body">
                <div class="signature-summary">
                    <div>Receipt: <strong id="modal_receipt_number">—</strong></div>
                    <div>Client: <strong id="modal_client_name">—</strong></div>
                    <div>Amount: <strong id="modal_amount_paid">—</strong></div>
                </div>

                <div class="form-grid" style="gap:14px">
                    <div class="form-group" style="grid-column:1/-1">
                        <label for="authorized_name">Authorized Name</label>
                        <input type="text" name="authorized_name" id="authorized_name" required placeholder="Enter authorized signatory name">
                    </div>

                    <div class="form-group" style="grid-column:1/-1">
                        <label>Signature Option</label>
                        <div style="display:flex;gap:18px;flex-wrap:wrap">
                            <label style="display:flex;align-items:center;gap:8px">
                                <input type="radio" name="signature_option" value="draw" checked>
                                Draw Signature
                            </label>
                            <label style="display:flex;align-items:center;gap:8px">
                                <input type="radio" name="signature_option" value="upload">
                                Upload Signature
                            </label>
                        </div>
                    </div>

                    <div class="form-group" id="draw-signature-group" style="grid-column:1/-1">
                        <label>Draw Signature</label>
                        <div class="signature-pad-wrap">
                            <canvas id="signature-pad"></canvas>
                        </div>
                        <div style="display:flex;justify-content:flex-end;margin-top:10px">
                            <button type="button" class="btn btn-outline btn-sm" id="clearSignaturePad">
                                <i class="fas fa-eraser"></i>
                                Clear
                            </button>
                        </div>
                    </div>

                    <div class="form-group" id="upload-signature-group" style="grid-column:1/-1;display:none">
                        <label for="signature_file">Upload Signature Image</label>
                        <input type="file" name="signature_file" id="signature_file" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp">
                        <img id="signatureUploadPreview" class="signature-upload-preview" alt="Signature preview">
                    </div>
                </div>
            </div>

            <div class="signature-modal-footer">
                <button type="button" class="btn btn-ghost" id="cancelSignatureModal">
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

<script>
if (typeof filterTable === 'function') {
    filterTable('rcp-search', 'rcp-table');
}

async function sendReceiptEmail(receiptId, btn) {
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';

    try {
        const res = await fetch('send_receipt.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ receipt_id: receiptId })
        });

        const raw = await res.text();
        let data = {};

        try {
            data = JSON.parse(raw);
        } catch (e) {
            throw new Error('Invalid response from send_receipt.php');
        }

        if (data.success) {
            alert(data.message || 'Receipt email sent successfully.');
        } else {
            alert(data.error || data.message || 'Failed to send receipt email.');
        }
    } catch (e) {
        alert(e.message || 'Network error while sending receipt email.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = original;
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const modalBackdrop = document.getElementById('signatureModalBackdrop');
    const closeBtn = document.getElementById('closeSignatureModal');
    const cancelBtn = document.getElementById('cancelSignatureModal');
    const clearBtn = document.getElementById('clearSignaturePad');
    const form = document.getElementById('signatureForm');

    const receiptIdInput = document.getElementById('modal_receipt_id');
    const authorizedNameInput = document.getElementById('authorized_name');
    const signatureTypeInput = document.getElementById('signature_type');
    const signatureDataInput = document.getElementById('signature_data');
    const existingSignaturePathInput = document.getElementById('existing_signature_path');

    const receiptNumberEl = document.getElementById('modal_receipt_number');
    const clientNameEl = document.getElementById('modal_client_name');
    const amountPaidEl = document.getElementById('modal_amount_paid');

    const drawGroup = document.getElementById('draw-signature-group');
    const uploadGroup = document.getElementById('upload-signature-group');
    const signatureFileInput = document.getElementById('signature_file');
    const previewImg = document.getElementById('signatureUploadPreview');

    const canvas = document.getElementById('signature-pad');
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
        signatureDataInput.value = '';
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

    document.querySelectorAll('.open-signature-modal').forEach(function (btn) {
        btn.addEventListener('click', function () {
            receiptIdInput.value = this.dataset.id || '';
            authorizedNameInput.value = this.dataset.authorized || '';
            existingSignaturePathInput.value = this.dataset.signaturePath || '';

            receiptNumberEl.textContent = this.dataset.number || '—';
            clientNameEl.textContent = this.dataset.client || '—';
            amountPaidEl.textContent = this.dataset.amount || '—';

            signatureTypeInput.value = 'draw';
            document.querySelector('input[name="signature_option"][value="draw"]').checked = true;
            drawGroup.style.display = '';
            uploadGroup.style.display = 'none';

            signatureFileInput.value = '';
            previewImg.style.display = 'none';
            previewImg.removeAttribute('src');

            prepareCanvas();
            clearCanvas();

            modalBackdrop.classList.add('show');
            document.body.style.overflow = 'hidden';
        });
    });

    function closeModal() {
        modalBackdrop.classList.remove('show');
        document.body.style.overflow = '';
    }

    closeBtn.addEventListener('click', closeModal);
    cancelBtn.addEventListener('click', closeModal);

    modalBackdrop.addEventListener('click', function (e) {
        if (e.target === modalBackdrop) {
            closeModal();
        }
    });

    clearBtn.addEventListener('click', function () {
        clearCanvas();
    });

    document.querySelectorAll('input[name="signature_option"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            if (this.value === 'upload') {
                signatureTypeInput.value = 'upload';
                drawGroup.style.display = 'none';
                uploadGroup.style.display = '';
            } else {
                signatureTypeInput.value = 'draw';
                drawGroup.style.display = '';
                uploadGroup.style.display = 'none';
            }
        });
    });

    signatureFileInput.addEventListener('change', function () {
        const file = this.files && this.files[0] ? this.files[0] : null;

        if (!file) {
            previewImg.style.display = 'none';
            previewImg.removeAttribute('src');
            return;
        }

        const reader = new FileReader();
        reader.onload = function (e) {
            previewImg.src = e.target.result;
            previewImg.style.display = 'block';
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

    form.addEventListener('submit', function (e) {
        if (signatureTypeInput.value === 'draw') {
            if (!hasSignature) {
                e.preventDefault();
                alert('Please draw a signature before saving.');
                return;
            }

            signatureDataInput.value = canvas.toDataURL('image/png');
            if (!signatureDataInput.value) {
                e.preventDefault();
                alert('Failed to capture signature. Please draw again.');
                return;
            }
        } else {
            if (!signatureFileInput.files.length && !existingSignaturePathInput.value) {
                e.preventDefault();
                alert('Please upload a signature image before saving.');
                return;
            }
        }
    });
});
</script>

<?php include 'includes/footer.php'; ?>
