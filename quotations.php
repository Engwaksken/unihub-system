<?php
require_once 'config/db.php';

$pageTitle = 'Quotations';
$pdo = getDB();
$cfg = getSettings();
ensureUserSignatureProfile($pdo);
$pdo->exec("CREATE TABLE IF NOT EXISTS quotations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    quote_number VARCHAR(50) NOT NULL UNIQUE,
    client_id INT UNSIGNED NOT NULL,
    quote_date DATE NOT NULL,
    valid_until DATE DEFAULT NULL,
    subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
    tax_total DECIMAL(15,2) NOT NULL DEFAULT 0,
    discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    total DECIMAL(15,2) NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    notes TEXT DEFAULT NULL,
    terms TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_quotations_date (quote_date),
    INDEX idx_quotations_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS quotation_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    quotation_id INT UNSIGNED NOT NULL,
    service_id INT UNSIGNED DEFAULT NULL,
    description VARCHAR(500) NOT NULL,
    quantity DECIMAL(12,2) NOT NULL DEFAULT 1,
    unit VARCHAR(50) DEFAULT NULL,
    unit_price DECIMAL(15,2) NOT NULL DEFAULT 0,
    tax_rate DECIMAL(7,2) NOT NULL DEFAULT 0,
    line_total DECIMAL(15,2) NOT NULL DEFAULT 0,
    INDEX idx_quotation_items_parent (quotation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
foreach (['authorized_name' => 'VARCHAR(150) NULL', 'signature_data' => 'LONGTEXT NULL'] as $sigColumn => $sigDefinition) {
    $columnCheck = $pdo->query('SHOW COLUMNS FROM quotations LIKE ' . $pdo->quote($sigColumn));
    if (!$columnCheck->fetch()) $pdo->exec("ALTER TABLE quotations ADD COLUMN `{$sigColumn}` {$sigDefinition}");
}

if (!function_exists('h')) {
    function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('moneyQ')) {
    function moneyQ($amount, $symbol = 'UGX '): string { return $symbol . number_format((float)$amount, 2); }
}
if (!function_exists('nextQuoteNumber')) {
    function nextQuoteNumber(PDO $pdo): string {
        $prefix = 'QUO-';
        $year = date('Y');
        $stmt = $pdo->prepare("SELECT quote_number FROM quotations WHERE quote_number LIKE ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$prefix . $year . '-%']);
        $last = $stmt->fetchColumn();
        $next = 1;
        if ($last && preg_match('/-(\d+)$/', (string)$last, $m)) $next = ((int)$m[1]) + 1;
        return $prefix . $year . '-' . str_pad((string)$next, 5, '0', STR_PAD_LEFT);
    }
}

$error = '';
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'create_quote') {
            $clientId = (int)($_POST['client_id'] ?? 0);
            $quoteDate = $_POST['quote_date'] ?? date('Y-m-d');
            $validUntil = !empty($_POST['valid_until']) ? $_POST['valid_until'] : null;
            $notes = trim((string)($_POST['notes'] ?? ''));
            $terms = trim((string)($_POST['terms'] ?? ''));
            $discountAmount = max(0, (float)($_POST['discount_amount'] ?? 0));

            $serviceIds = $_POST['service_id'] ?? [];
            $descriptions = $_POST['description'] ?? [];
            $quantities = $_POST['quantity'] ?? [];
            $units = $_POST['unit'] ?? [];
            $unitPrices = $_POST['unit_price'] ?? [];
            $taxRates = $_POST['tax_rate'] ?? [];

            if ($clientId <= 0) throw new Exception('Please select a client.');
            if (empty($descriptions)) throw new Exception('Please add at least one quotation item.');

            $serviceStmt = $pdo->prepare("SELECT id, name, description, unit_price, tax_rate, unit FROM services WHERE id = ? LIMIT 1");
            $subtotal = 0.0; $taxTotal = 0.0; $items = [];

            foreach ($descriptions as $i => $postedDesc) {
                $serviceId = !empty($serviceIds[$i]) ? (int)$serviceIds[$i] : null;
                $qty = (float)($quantities[$i] ?? 0);
                if ($qty <= 0) continue;

                if ($serviceId) {
                    $serviceStmt->execute([$serviceId]);
                    $srv = $serviceStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$srv) throw new Exception('Invalid service selected.');
                    $desc = trim((string)($srv['description'] ?: $srv['name']));
                    $unit = trim((string)($srv['unit'] ?? ''));
                    $price = (float)($srv['unit_price'] ?? 0);
                    $taxRate = (float)($srv['tax_rate'] ?? 0);
                } else {
                    $desc = trim((string)$postedDesc);
                    $unit = trim((string)($units[$i] ?? ''));
                    $price = (float)($unitPrices[$i] ?? 0);
                    $taxRate = (float)($taxRates[$i] ?? 0);
                }

                if ($desc === '') continue;
                $line = $qty * $price;
                $tax = $line * ($taxRate / 100);
                $lineTotal = $line + $tax;
                $subtotal += $line;
                $taxTotal += $tax;
                $items[] = compact('serviceId','desc','qty','unit','price','taxRate','lineTotal');
            }

            if (!$items) throw new Exception('Please add valid quotation items.');
            $total = max(0, $subtotal + $taxTotal - $discountAmount);

            $profileStmt = $pdo->prepare('SELECT authorized_name, profile_signature_data FROM users WHERE id=? LIMIT 1');
            $profileStmt->execute([(int)($_SESSION['user_id'] ?? 0)]);
            $signatory = $profileStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $pdo->beginTransaction();
            $quoteNumber = nextQuoteNumber($pdo);
            $stmt = $pdo->prepare("INSERT INTO quotations (quote_number, client_id, quote_date, valid_until, subtotal, tax_total, discount_amount, total, status, notes, terms, authorized_name, signature_data) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?)");
            $stmt->execute([$quoteNumber, $clientId, $quoteDate, $validUntil, $subtotal, $taxTotal, $discountAmount, $total, $notes, $terms, $signatory['authorized_name'] ?? null, $signatory['profile_signature_data'] ?? null]);
            $quoteId = (int)$pdo->lastInsertId();

            $itemStmt = $pdo->prepare("INSERT INTO quotation_items (quotation_id, service_id, description, quantity, unit, unit_price, tax_rate, line_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($items as $it) $itemStmt->execute([$quoteId, $it['serviceId'], $it['desc'], $it['qty'], $it['unit'], $it['price'], $it['taxRate'], $it['lineTotal']]);
            $pdo->commit();
            header('Location: quotations.php?view=' . $quoteId . '&created=1'); exit;
        }

        if ($action === 'update_status') {
            $quoteId = (int)($_POST['quote_id'] ?? 0);
            $status = $_POST['status'] ?? 'draft';
            $allowed = ['draft', 'sent', 'accepted', 'rejected', 'expired'];
            if ($quoteId <= 0 || !in_array($status, $allowed, true)) throw new Exception('Invalid quotation status.');
            $stmt = $pdo->prepare('UPDATE quotations SET status = ?, updated_at = NOW() WHERE id = ?');
            $stmt->execute([$status, $quoteId]);
            header('Location: quotations.php?view=' . $quoteId . '&updated=1'); exit;
        }

        if ($action === 'update_quote_signature') {
            $quoteId = (int)($_POST['quote_id'] ?? 0);
            $authorizedName = trim((string)($_POST['authorized_name'] ?? ''));
            if ($quoteId <= 0 || $authorizedName === '') throw new Exception('Authorized name is required.');
            $profileStmt = $pdo->prepare('SELECT profile_signature_data FROM users WHERE id=? LIMIT 1');
            $profileStmt->execute([(int)($_SESSION['user_id'] ?? 0)]);
            $profileSignature = (string)($profileStmt->fetchColumn() ?: '');
            $upd = $pdo->prepare('UPDATE quotations SET authorized_name=?, signature_data=? WHERE id=?');
            $upd->execute([$authorizedName, $profileSignature ?: null, $quoteId]);
            header('Location: quotations.php?view=' . $quoteId . '&updated=1'); exit;
        }

        if ($action === 'delete_quote') {
            $quoteId = (int)($_POST['quote_id'] ?? 0);
            if ($quoteId <= 0) throw new Exception('Invalid quotation selected.');
            $pdo->prepare('DELETE FROM quotation_items WHERE quotation_id = ?')->execute([$quoteId]);
            $pdo->prepare('DELETE FROM quotations WHERE id = ?')->execute([$quoteId]);
            header('Location: quotations.php?deleted=1'); exit;
        }

        if ($action === 'bulk_delete_quotes') {
            $ids = array_values(array_unique(array_filter(array_map('intval', $_POST['quote_ids'] ?? []), static fn($id) => $id > 0)));
            if (!$ids) throw new Exception('Select at least one quotation to delete.');
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM quotation_items WHERE quotation_id IN ($marks)")->execute($ids);
            $pdo->prepare("DELETE FROM quotations WHERE id IN ($marks)")->execute($ids);
            $pdo->commit();
            header('Location: quotations.php?deleted=1'); exit;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

$clients = $pdo->query("SELECT id, name, email, phone, company FROM clients ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$services = $pdo->query("SELECT id, name, description, unit_price, tax_rate, unit FROM services ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$viewId = (int)($_GET['view'] ?? 0);
$quote = null; $quoteItems = [];
if ($viewId > 0) {
    $stmt = $pdo->prepare("SELECT q.*, c.name AS client_name, c.email AS client_email, c.phone AS client_phone, c.address AS client_address, c.company AS client_company, c.tin AS client_tin FROM quotations q JOIN clients c ON c.id = q.client_id WHERE q.id = ? LIMIT 1");
    $stmt->execute([$viewId]);
    $quote = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($quote) {
        $stmt = $pdo->prepare("SELECT qi.*, s.name AS service_name FROM quotation_items qi LEFT JOIN services s ON s.id = qi.service_id WHERE qi.quotation_id = ? ORDER BY qi.id ASC");
        $stmt->execute([$viewId]);
        $quoteItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$dateWhere = [];
$dateParams = [];
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) { $dateWhere[] = 'q.quote_date >= ?'; $dateParams[] = $dateFrom; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) { $dateWhere[] = 'q.quote_date <= ?'; $dateParams[] = $dateTo; }
$dateSql = $dateWhere ? ' WHERE ' . implode(' AND ', $dateWhere) : '';
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM quotations q$dateSql");
$countStmt->execute($dateParams);
$quoteCount = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($quoteCount / $perPage));
$page = min($page, $totalPages);
$listStmt = $pdo->prepare("SELECT q.*, c.name AS client_name, c.email AS client_email FROM quotations q JOIN clients c ON c.id = q.client_id$dateSql ORDER BY q.created_at DESC, q.id DESC LIMIT ? OFFSET ?");
foreach ($dateParams as $index => $value) $listStmt->bindValue($index + 1, $value, PDO::PARAM_STR);
$listStmt->bindValue(count($dateParams) + 1, $perPage, PDO::PARAM_INT);
$listStmt->bindValue(count($dateParams) + 2, ($page - 1) * $perPage, PDO::PARAM_INT);
$listStmt->execute();
$quotes = $listStmt->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
?>

<div class="section-header quote-section-header no-print">
    <span class="section-title"><i class="fas fa-file-signature quote-section-icon"></i> Quotations</span>
    <button type="button" class="btn btn-primary" onclick="openQuoteModal()"><i class="fas fa-plus-circle"></i> New Quotation</button>
</div>

<?php if ($error): ?><div class="settings-alert settings-alert-danger no-print"><?= h($error) ?></div><?php endif; ?>
<?php if (isset($_GET['created'])): ?><div class="settings-alert settings-alert-success no-print">Quotation created successfully.</div><?php endif; ?>
<?php if (isset($_GET['updated'])): ?><div class="settings-alert settings-alert-success no-print">Quotation status updated.</div><?php endif; ?>
<?php if (isset($_GET['deleted'])): ?><div class="settings-alert settings-alert-success no-print">Quotation deleted successfully.</div><?php endif; ?>

<?php if ($quote): ?>
<div class="quote-actions no-print">
    <a href="quotations.php" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Back</a>
    <button class="btn btn-outline" onclick="window.print()"><i class="fas fa-print"></i> Print / PDF</button>
    <form method="post" class="quote-status-form">
        <input type="hidden" name="action" value="update_status">
        <input type="hidden" name="quote_id" value="<?= (int)$quote['id'] ?>">
        <select name="status" class="search-input quote-status-select">
            <?php foreach (['draft','sent','accepted','rejected','expired'] as $st): ?>
                <option value="<?= h($st) ?>" <?= $quote['status'] === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-primary" type="submit">Update Status</button>
    </form>
    <form method="post" class="quote-status-form">
        <input type="hidden" name="action" value="update_quote_signature">
        <input type="hidden" name="quote_id" value="<?= (int)$quote['id'] ?>">
        <input type="text" name="authorized_name" value="<?= h($quote['authorized_name'] ?? '') ?>" aria-label="Authorized name" required>
        <button class="btn btn-outline" type="submit" title="Update from profile signature">Update Signatory</button>
    </form>
    <form method="post" onsubmit="return confirm('Delete this quotation?')">
        <input type="hidden" name="action" value="delete_quote">
        <input type="hidden" name="quote_id" value="<?= (int)$quote['id'] ?>">
        <button class="btn btn-danger" type="submit">Delete</button>
    </form>
</div>

<div class="print-quote">
    <div class="quote-header">
        <div class="quote-brand"><img src="assets/img/logo.png" alt="Logo"><div><div class="quote-company"><?= h($cfg['company_name'] ?? 'Company') ?></div><div class="quote-muted"><?= h($cfg['company_address'] ?? '') ?><br><?= h($cfg['company_phone'] ?? '') ?><?php if (!empty($cfg['company_email'])): ?> | <?= h($cfg['company_email']) ?><?php endif; ?><br><?php if (!empty($cfg['company_tin'])): ?>TIN: <?= h($cfg['company_tin']) ?><?php endif; ?></div></div></div>
        <div class="quote-doc-title"><h2>QUOTATION</h2><div class="quote-muted"><?= h($quote['quote_number']) ?></div><div class="status-pill status-<?= h($quote['status']) ?>"><?= h($quote['status']) ?></div></div>
    </div>
    <div class="quote-meta">
        <div class="quote-box"><div class="quote-box-title">Quote For</div><strong><?= h($quote['client_name']) ?></strong><br><?php if (!empty($quote['client_company'])): ?><?= h($quote['client_company']) ?><br><?php endif; ?><?php if (!empty($quote['client_phone'])): ?><?= h($quote['client_phone']) ?><br><?php endif; ?><?php if (!empty($quote['client_email'])): ?><?= h($quote['client_email']) ?><br><?php endif; ?><?php if (!empty($quote['client_address'])): ?><?= nl2br(h($quote['client_address'])) ?><br><?php endif; ?><?php if (!empty($quote['client_tin'])): ?>TIN: <?= h($quote['client_tin']) ?><?php endif; ?></div>
        <div class="quote-box"><div class="quote-box-title">Quotation Details</div><strong>Quote No:</strong> <?= h($quote['quote_number']) ?><br><strong>Quote Date:</strong> <?= date('d M Y', strtotime($quote['quote_date'])) ?><br><strong>Valid Until:</strong> <?= !empty($quote['valid_until']) ? date('d M Y', strtotime($quote['valid_until'])) : '-' ?><br><strong>Status:</strong> <?= ucfirst(h($quote['status'])) ?></div>
    </div>
    <table class="quote-table"><thead><tr><th class="quote-col-num">#</th><th>Description</th><th class="text-right quote-col-qty">Qty</th><th class="quote-col-unit">Unit</th><th class="text-right quote-col-price">Unit Price</th><th class="text-right quote-col-vat">VAT %</th><th class="text-right quote-col-total">Line Total</th></tr></thead><tbody>
    <?php foreach ($quoteItems as $i => $it): ?><tr><td><?= $i + 1 ?></td><td><?= nl2br(h($it['description'])) ?></td><td class="text-right"><?= number_format((float)$it['quantity'], 2) ?></td><td><?= h($it['unit'] ?? '') ?></td><td class="text-right"><?= moneyQ($it['unit_price'], $cfg['currency_symbol'] ?? 'UGX ') ?></td><td class="text-right"><?= number_format((float)$it['tax_rate'], 2) ?>%</td><td class="text-right"><?= moneyQ($it['line_total'], $cfg['currency_symbol'] ?? 'UGX ') ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <div class="quote-totals"><div class="quote-total-row"><span>Subtotal</span><span><?= moneyQ($quote['subtotal'], $cfg['currency_symbol'] ?? 'UGX ') ?></span></div><div class="quote-total-row"><span>Tax</span><span><?= moneyQ($quote['tax_total'], $cfg['currency_symbol'] ?? 'UGX ') ?></span></div><?php if ((float)$quote['discount_amount'] > 0): ?><div class="quote-total-row"><span>Discount</span><span>- <?= moneyQ($quote['discount_amount'], $cfg['currency_symbol'] ?? 'UGX ') ?></span></div><?php endif; ?><div class="quote-total-row grand"><span>Total Quote</span><span><?= moneyQ($quote['total'], $cfg['currency_symbol'] ?? 'UGX ') ?></span></div></div>
    <?php if (!empty($quote['notes'])): ?><div class="quote-box quote-notes-box"><div class="quote-box-title">Notes</div><?= nl2br(h($quote['notes'])) ?></div><?php endif; ?>
    <?php if (!empty($quote['terms'])): ?><div class="quote-box quote-terms-box"><div class="quote-box-title">Terms & Conditions</div><?= nl2br(h($quote['terms'])) ?></div><?php endif; ?>
    <div class="quote-signature-wrap"><div class="quote-signature-box"><?php if (!empty($quote['signature_data'])): ?><img class="quote-saved-signature" src="<?= h($quote['signature_data']) ?>" alt="Authorized signature"><?php endif; ?><div class="quote-signature-line"><?= h($quote['authorized_name'] ?? 'Authorized Signature') ?></div><div class="quote-signature-for">For: <?= h($cfg['company_name'] ?? 'Company') ?></div></div></div>
</div>
<?php else: ?>
<div class="quote-card no-print">
    <div class="quote-title">All Quotations</div>
    <form class="period-filter" method="get"><label>From <input type="date" name="date_from" value="<?= h($dateFrom) ?>"></label><label>To <input type="date" name="date_to" value="<?= h($dateTo) ?>"></label><button class="btn btn-primary btn-sm">Apply</button><a class="btn btn-ghost btn-sm" href="quotations.php">Clear</a></form>
    <div class="bulk-actions"><button class="btn btn-danger btn-sm" type="submit" form="bulkDeleteQuotes" onclick="return confirm('Delete all selected quotations and their items?')"><i class="fas fa-trash"></i> Delete selected</button><span><?= $quoteCount ?> quotations</span></div>
    <form method="post" id="bulkDeleteQuotes"><input type="hidden" name="action" value="bulk_delete_quotes"><div id="selectedQuoteIds"></div></form>
    <div class="table-wrap"><table class="quote-table"><thead><tr><th><input type="checkbox" id="selectAllQuotes" aria-label="Select all quotations on this page"></th><th>Quote #</th><th>Client</th><th>Quote Date</th><th>Total</th><th>Status</th><th class="quote-actions-col">Actions</th></tr></thead><tbody>
    <?php foreach ($quotes as $q): ?><tr><td><input type="checkbox" class="quote-select" value="<?= (int)$q['id'] ?>" aria-label="Select quotation <?= h($q['quote_number']) ?>"></td><td><strong class="quote-number-link"><?= h($q['quote_number']) ?></strong></td><td><?= h($q['client_name']) ?></td><td><?= !empty($q['quote_date']) ? date('d M Y', strtotime($q['quote_date'])) : '-' ?></td><td><?= moneyQ($q['total'], $cfg['currency_symbol'] ?? 'UGX ') ?></td><td><span class="status-pill status-<?= h($q['status']) ?>"><?= h($q['status']) ?></span></td><td><div class="quote-row-actions"><a href="quotations.php?view=<?= (int)$q['id'] ?>" class="btn btn-ghost btn-sm" title="View" aria-label="View"><i class="fas fa-eye"></i></a><form method="post" onsubmit="return confirm('Delete this quotation?')"><input type="hidden" name="action" value="delete_quote"><input type="hidden" name="quote_id" value="<?= (int)$q['id'] ?>"><button class="btn btn-danger btn-sm" title="Delete" aria-label="Delete" type="submit"><i class="fas fa-trash"></i></button></form></div></td></tr><?php endforeach; ?>
    <?php if (empty($quotes)): ?><tr><td colspan="7" class="quote-empty-cell">No quotations found for this period.</td></tr><?php endif; ?>
    </tbody></table></div>
    <nav class="users-pagination" aria-label="Quotation pages"><?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="<?= h('quotations.php?' . http_build_query(['date_from'=>$dateFrom,'date_to'=>$dateTo,'page'=>$page-1])) ?>">Previous</a><?php endif; ?><span class="users-page-info">Page <?= $page ?> of <?= $totalPages ?></span><?php if ($page < $totalPages): ?><a class="btn btn-ghost btn-sm" href="<?= h('quotations.php?' . http_build_query(['date_from'=>$dateFrom,'date_to'=>$dateTo,'page'=>$page+1])) ?>">Next</a><?php endif; ?></nav>
</div>
<?php endif; ?>

<div id="quoteModal" class="quote-modal-backdrop no-print"><div class="quote-modal"><div class="quote-modal-header"><h3><i class="fas fa-file-signature"></i> New Quotation</h3><button type="button" class="quote-modal-close" onclick="closeQuoteModal()"><i class="fas fa-times"></i></button></div><form method="post" id="quoteForm"><input type="hidden" name="action" value="create_quote"><div class="quote-modal-body"><div class="quote-form-grid"><div class="quote-form-group"><label>Client</label><select name="client_id" required><option value="">Select client</option><?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?><?= !empty($c['company']) ? ' - ' . h($c['company']) : '' ?></option><?php endforeach; ?></select></div><div class="quote-form-group"><label>Quote Date</label><input type="date" name="quote_date" value="<?= date('Y-m-d') ?>" required></div><div class="quote-form-group"><label>Valid Until</label><input type="date" name="valid_until" value="<?= date('Y-m-d', strtotime('+14 days')) ?>"></div><div class="quote-form-group"><label>Discount Amount</label><input type="number" step="0.01" min="0" name="discount_amount" id="discountAmount" value="0"></div></div><hr class="quote-form-divider"><div class="quote-title">Quotation Items</div><div id="quoteItems"><div class="quote-item-row"><select name="service_id[]" class="serviceSelect"><option value="">Custom Item</option><?php foreach ($services as $s): ?><?php $serviceDesc = trim((string)($s['description'] ?? '')); if ($serviceDesc === '') $serviceDesc = (string)$s['name']; ?><option value="<?= (int)$s['id'] ?>" data-desc="<?= h($serviceDesc) ?>" data-price="<?= h($s['unit_price'] ?? 0) ?>" data-tax="<?= h($s['tax_rate'] ?? 0) ?>" data-unit="<?= h($s['unit'] ?? '') ?>"><?= h($s['name']) ?></option><?php endforeach; ?></select><input type="text" name="description[]" class="descInput" placeholder="Description" required><input type="number" name="quantity[]" class="qtyInput" value="1" min="0.01" step="0.01" required><input type="text" name="unit[]" class="unitInput" placeholder="Unit"><input type="number" name="unit_price[]" class="priceInput" value="0" min="0" step="0.01" required><input type="number" name="tax_rate[]" class="taxInput" value="0" min="0" step="0.01"><button type="button" class="btn btn-danger btn-sm removeItem">x</button></div></div><button type="button" class="btn btn-outline quote-add-item-btn" id="addItem"><i class="fas fa-plus"></i> Add Item</button><div class="quote-totals quote-preview-totals"><div class="quote-total-row"><span>Subtotal</span><span id="previewSubtotal">0.00</span></div><div class="quote-total-row"><span>Tax</span><span id="previewTax">0.00</span></div><div class="quote-total-row"><span>Discount</span><span id="previewDiscount">0.00</span></div><div class="quote-total-row grand"><span>Total</span><span id="previewTotal">0.00</span></div></div><div class="quote-form-group quote-notes-field"><label>Notes</label><textarea name="notes" rows="3"></textarea></div><div class="quote-form-group quote-terms-field"><label>Terms & Conditions</label><textarea name="terms" rows="3"></textarea></div></div><div class="quote-modal-footer"><button type="button" class="btn btn-ghost" onclick="closeQuoteModal()">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Quotation</button></div></form></div></div>

<script>
const quoteSelectAll=document.getElementById('selectAllQuotes');
quoteSelectAll?.addEventListener('change',()=>document.querySelectorAll('.quote-select').forEach(box=>box.checked=quoteSelectAll.checked));
document.getElementById('bulkDeleteQuotes')?.addEventListener('submit',event=>{const picked=[...document.querySelectorAll('.quote-select:checked')];if(!picked.length){event.preventDefault();alert('Select at least one quotation.');return;}const holder=document.getElementById('selectedQuoteIds');holder.replaceChildren(...picked.map(box=>{const input=document.createElement('input');input.type='hidden';input.name='quote_ids[]';input.value=box.value;return input;}));});
function openQuoteModal(){const modal=document.getElementById('quoteModal');if(modal){modal.classList.add('show');document.body.style.overflow='hidden';}}
function closeQuoteModal(){const modal=document.getElementById('quoteModal');if(modal){modal.classList.remove('show');document.body.style.overflow='';}}
document.addEventListener('DOMContentLoaded',function(){const quoteItems=document.getElementById('quoteItems');const addItem=document.getElementById('addItem');const discountAmount=document.getElementById('discountAmount');const quoteModal=document.getElementById('quoteModal');function money(n){return Number(n||0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});}function recalc(){let subtotal=0,taxTotal=0;document.querySelectorAll('.quote-item-row').forEach(row=>{const qty=parseFloat(row.querySelector('.qtyInput')?.value||0);const price=parseFloat(row.querySelector('.priceInput')?.value||0);const taxRate=parseFloat(row.querySelector('.taxInput')?.value||0);const line=qty*price;subtotal+=line;taxTotal+=line*taxRate/100;});const discount=parseFloat(discountAmount?.value||0);const total=Math.max(0,subtotal+taxTotal-discount);['Subtotal','Tax','Discount','Total'].forEach((x)=>{});document.getElementById('previewSubtotal').textContent=money(subtotal);document.getElementById('previewTax').textContent=money(taxTotal);document.getElementById('previewDiscount').textContent=money(discount);document.getElementById('previewTotal').textContent=money(total);}function bindRow(row){const service=row.querySelector('.serviceSelect'),desc=row.querySelector('.descInput'),unit=row.querySelector('.unitInput'),price=row.querySelector('.priceInput'),tax=row.querySelector('.taxInput');service?.addEventListener('change',function(){const opt=this.options[this.selectedIndex];if(this.value){desc.value=opt.dataset.desc||opt.textContent.trim();unit.value=opt.dataset.unit||'';price.value=opt.dataset.price||0;tax.value=opt.dataset.tax||0;desc.readOnly=unit.readOnly=price.readOnly=tax.readOnly=true;}else{desc.value='';unit.value='';price.value=0;tax.value=0;desc.readOnly=unit.readOnly=price.readOnly=tax.readOnly=false;}recalc();});row.querySelectorAll('input,select').forEach(el=>{el.addEventListener('input',recalc);el.addEventListener('change',recalc);});row.querySelector('.removeItem')?.addEventListener('click',function(){if(document.querySelectorAll('.quote-item-row').length<=1){alert('At least one item is required.');return;}row.remove();recalc();});}addItem?.addEventListener('click',function(){const first=document.querySelector('.quote-item-row');if(!first||!quoteItems)return;const clone=first.cloneNode(true);clone.querySelectorAll('input').forEach(input=>{if(input.classList.contains('qtyInput'))input.value='1';else if(input.classList.contains('priceInput')||input.classList.contains('taxInput'))input.value='0';else input.value='';input.readOnly=false;});clone.querySelectorAll('select').forEach(select=>select.value='');quoteItems.appendChild(clone);bindRow(clone);recalc();});document.querySelectorAll('.quote-item-row').forEach(bindRow);discountAmount?.addEventListener('input',recalc);document.addEventListener('keydown',e=>{if(e.key==='Escape')closeQuoteModal();});quoteModal?.addEventListener('click',function(e){if(e.target===this)closeQuoteModal();});recalc();});
</script>
<?php include 'includes/footer.php'; ?>
