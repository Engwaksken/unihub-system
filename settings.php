<?php
require_once 'config/db.php';

$pdo = getDB();

if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('setting_value')) {
    function setting_value(array $settings, string $key, string $default = ''): string {
        return isset($settings[$key]) ? (string)$settings[$key] : $default;
    }
}

if (!function_exists('ensureSettingsKeys')) {
    function ensureSettingsKeys(PDO $pdo, array $keys): void {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE `key` = ?");
        $ins = $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES (?, '')");
        foreach ($keys as $key) {
            $chk->execute([$key]);
            if (!(int)$chk->fetchColumn()) $ins->execute([$key]);
        }
    }
}

if (!function_exists('saveSetting')) {
    function saveSetting(PDO $pdo, string $key, ?string $value): void {
        $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (:k,:v) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)")
            ->execute([':k' => $key, ':v' => $value]);
    }
}

$requiredKeys = [
    'company_name','company_address','company_email','company_phone',
    'company_tin','company_website','currency','currency_symbol',
    'invoice_footer','invoice_prefix','order_prefix','receipt_prefix',
    'payment_terms','vat_rate','bank_name','account_name',
    'account_number','bank_branch','company_logo'
];

ensureSettingsKeys($pdo, $requiredKeys);

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $companyName    = trim($_POST['company_name']    ?? '');
        $companyAddress = trim($_POST['company_address'] ?? '');
        $companyEmail   = trim($_POST['company_email']   ?? '');
        $companyPhone   = trim($_POST['company_phone']   ?? '');
        $companyTin     = trim($_POST['company_tin']     ?? '');
        $companyWebsite = trim($_POST['company_website'] ?? '');
        $currency       = trim($_POST['currency']        ?? 'UGX');
        $currencySymbol = trim($_POST['currency_symbol'] ?? 'UGX');
        $invoiceFooter  = trim($_POST['invoice_footer']  ?? '');
        $invoicePrefix  = trim($_POST['invoice_prefix']  ?? 'INV');
        $orderPrefix    = trim($_POST['order_prefix']    ?? 'ORD');
        $receiptPrefix  = trim($_POST['receipt_prefix']  ?? 'RCP');
        $paymentTerms   = trim($_POST['payment_terms']   ?? '30');
        $vatRate        = trim($_POST['vat_rate']        ?? '18');
        $bankName       = trim($_POST['bank_name']       ?? '');
        $accountName    = trim($_POST['account_name']    ?? '');
        $accountNumber  = trim($_POST['account_number']  ?? '');
        $bankBranch     = trim($_POST['bank_branch']     ?? '');

        if ($companyName === '') throw new RuntimeException('Company name is required.');
        if ($companyEmail !== '' && !filter_var($companyEmail, FILTER_VALIDATE_EMAIL))
            throw new RuntimeException('Please enter a valid company email address.');
        if ($paymentTerms !== '' && !is_numeric($paymentTerms))
            throw new RuntimeException('Payment terms must be a number.');
        if ($vatRate !== '' && !is_numeric($vatRate))
            throw new RuntimeException('VAT rate must be a number.');

        $logoPath = trim($_POST['existing_company_logo'] ?? '');

        if (!empty($_FILES['company_logo_file']['name'])) {
            $file = $_FILES['company_logo_file'];
            if (!isset($file['error']) || is_array($file['error']))
                throw new RuntimeException('Invalid logo upload.');
            if ($file['error'] !== UPLOAD_ERR_OK)
                throw new RuntimeException('Logo upload failed. Error code: ' . $file['error']);
            if ($file['size'] > 5 * 1024 * 1024)
                throw new RuntimeException('Logo file too large. Maximum 5 MB.');

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            $allowedMime = [
                'image/jpeg' => 'jpg','image/png' => 'png',
                'image/webp' => 'webp','image/gif' => 'gif','image/svg+xml' => 'svg'
            ];
            if (!isset($allowedMime[$mime]))
                throw new RuntimeException('Only JPG, PNG, WEBP, GIF or SVG logos are allowed.');

            $ext       = $allowedMime[$mime];
            $uploadDir = __DIR__ . '/assets/img/uploads/settings';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true))
                throw new RuntimeException('Failed to create upload directory.');

            $fileName = 'company_logo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $fileName))
                throw new RuntimeException('Failed to save uploaded logo.');

            $logoPath = 'assets/img/uploads/settings/' . $fileName;
        }

        $pdo->beginTransaction();
        foreach ([
            'company_name'    => $companyName,
            'company_address' => $companyAddress,
            'company_email'   => $companyEmail,
            'company_phone'   => $companyPhone,
            'company_tin'     => $companyTin,
            'company_website' => $companyWebsite,
            'currency'        => $currency,
            'currency_symbol' => $currencySymbol,
            'invoice_footer'  => $invoiceFooter,
            'invoice_prefix'  => $invoicePrefix,
            'order_prefix'    => $orderPrefix,
            'receipt_prefix'  => $receiptPrefix,
            'payment_terms'   => $paymentTerms,
            'vat_rate'        => $vatRate,
            'bank_name'       => $bankName,
            'account_name'    => $accountName,
            'account_number'  => $accountNumber,
            'bank_branch'     => $bankBranch,
            'company_logo'    => $logoPath,
        ] as $k => $v) saveSetting($pdo, $k, $v);
        $pdo->commit();

        $success = 'Settings updated successfully.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

$settings    = getSettings();
$currentLogo = setting_value($settings, 'company_logo');
$pageTitle   = 'System Settings';
include 'includes/header.php';
?>

<div class="section-header">
    <span class="section-title">System Settings</span>
</div>

<?php if ($success !== ''): ?>
    <div class="settings-alert settings-alert-success"><?= h($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="settings-alert settings-alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data">
<div class="settings-grid">

    <!-- Company Information -->
    <div class="settings-card">
        <div class="settings-card-header">
            <span class="settings-card-title">Company Information</span>
        </div>
        <div class="settings-card-body">
            <div class="form-grid">
                <div class="form-group settings-full-col">
                    <label>Company Name *</label>
                    <input type="text" name="company_name" required
                           value="<?= h(setting_value($settings,'company_name')) ?>">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="company_email"
                           value="<?= h(setting_value($settings,'company_email')) ?>">
                </div>
                <div class="form-group">
                    <label>Phone</label>
                    <input type="text" name="company_phone"
                           value="<?= h(setting_value($settings,'company_phone')) ?>">
                </div>
                <div class="form-group">
                    <label>TIN</label>
                    <input type="text" name="company_tin"
                           value="<?= h(setting_value($settings,'company_tin')) ?>">
                </div>
                <div class="form-group">
                    <label>Website</label>
                    <input type="text" name="company_website"
                           value="<?= h(setting_value($settings,'company_website')) ?>">
                </div>
                <div class="form-group settings-full-col">
                    <label>Address</label>
                    <textarea name="company_address"><?= h(setting_value($settings,'company_address')) ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <!-- Logo -->
    <div class="settings-card">
        <div class="settings-card-header">
            <span class="settings-card-title">Company Logo</span>
        </div>
        <div class="settings-card-body">
            <div class="settings-logo-wrap">
                <div class="settings-logo-preview" id="logoPreviewBox">
                    <?php if ($currentLogo && file_exists(__DIR__ . '/' . ltrim($currentLogo,'/'))): ?>
                        <img src="<?= h($currentLogo) ?>" alt="Logo" id="logoPreviewImage">
                    <?php else: ?>
                        <span class="settings-logo-placeholder" id="logoPreviewPlaceholder">No logo</span>
                        <img src="" alt="Logo" id="logoPreviewImage" class="settings-logo-hidden">
                    <?php endif; ?>
                </div>
                <div class="settings-logo-fields">
                    <div class="form-group">
                        <label>Upload Logo</label>
                        <input type="file" id="company_logo_file" name="company_logo_file"
                               accept=".jpg,.jpeg,.png,.webp,.gif,.svg,image/*">
                        <span class="settings-hint">Transparent PNG or SVG recommended.</span>
                    </div>
                    <input type="hidden" name="existing_company_logo" value="<?= h($currentLogo) ?>">
                    <div class="form-group">
                        <label>Or Manual Path</label>
                        <input type="text" id="company_logo_manual"
                               value="<?= h($currentLogo) ?>" placeholder="assets/img/logo.png">
                        <span class="settings-hint">Optional path override.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bank Details -->
    <div class="settings-card">
        <div class="settings-card-header">
            <span class="settings-card-title">Bank Details</span>
        </div>
        <div class="settings-card-body">
            <div class="form-grid">
                <div class="form-group">
                    <label>Bank Name</label>
                    <input type="text" name="bank_name"
                           value="<?= h(setting_value($settings,'bank_name')) ?>">
                </div>
                <div class="form-group">
                    <label>Bank Branch</label>
                    <input type="text" name="bank_branch"
                           value="<?= h(setting_value($settings,'bank_branch')) ?>">
                </div>
                <div class="form-group">
                    <label>Account Name</label>
                    <input type="text" name="account_name"
                           value="<?= h(setting_value($settings,'account_name')) ?>">
                </div>
                <div class="form-group">
                    <label>Account Number</label>
                    <input type="text" name="account_number"
                           value="<?= h(setting_value($settings,'account_number')) ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- Invoice & Receipt -->
    <div class="settings-card">
        <div class="settings-card-header">
            <span class="settings-card-title">Invoice &amp; Receipt Settings</span>
        </div>
        <div class="settings-card-body">
            <div class="form-grid">
                <div class="form-group">
                    <label>Invoice Prefix</label>
                    <input type="text" name="invoice_prefix"
                           value="<?= h(setting_value($settings,'invoice_prefix','INV')) ?>">
                </div>
                <div class="form-group">
                    <label>Order Prefix</label>
                    <input type="text" name="order_prefix"
                           value="<?= h(setting_value($settings,'order_prefix','ORD')) ?>">
                </div>
                <div class="form-group">
                    <label>Receipt Prefix</label>
                    <input type="text" name="receipt_prefix"
                           value="<?= h(setting_value($settings,'receipt_prefix','RCP')) ?>">
                </div>
                <div class="form-group">
                    <label>Payment Terms (Days)</label>
                    <input type="number" name="payment_terms" min="0"
                           value="<?= h(setting_value($settings,'payment_terms','30')) ?>">
                </div>
                <div class="form-group">
                    <label>Currency</label>
                    <input type="text" name="currency"
                           value="<?= h(setting_value($settings,'currency','UGX')) ?>">
                </div>
                <div class="form-group">
                    <label>Currency Symbol</label>
                    <input type="text" name="currency_symbol"
                           value="<?= h(setting_value($settings,'currency_symbol','UGX')) ?>">
                </div>
                <div class="form-group">
                    <label>VAT Rate (%)</label>
                    <input type="number" name="vat_rate" min="0" step="0.01"
                           value="<?= h(setting_value($settings,'vat_rate','18')) ?>">
                </div>
                <div class="form-group settings-full-col">
                    <label>Invoice Footer / Payment Instructions</label>
                    <textarea name="invoice_footer"><?= h(setting_value($settings,'invoice_footer')) ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <!-- Actions -->
    <div class="settings-card settings-full-col">
        <div class="settings-card-body settings-actions-bar">
            <a href="dashboard.php" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Save Settings</button>
        </div>
    </div>

</div><!-- /.settings-grid -->
</form>

<script>
(function () {
    const fileInput   = document.getElementById('company_logo_file');
    const manualInput = document.getElementById('company_logo_manual');
    const hiddenPath  = document.querySelector('input[name="existing_company_logo"]');
    const previewImg  = document.getElementById('logoPreviewImage');
    const placeholder = document.getElementById('logoPreviewPlaceholder');

    if (manualInput && hiddenPath) {
        manualInput.addEventListener('change', () => hiddenPath.value = manualInput.value.trim());
    }

    if (fileInput) {
        fileInput.addEventListener('change', function () {
            const file = this.files && this.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = ev => {
                previewImg.src = ev.target.result;
                previewImg.classList.remove('settings-logo-hidden');
                if (placeholder) placeholder.style.display = 'none';
            };
            reader.readAsDataURL(file);
        });
    }
})();
</script>

<?php include 'includes/footer.php'; ?>