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
    'company_name','company_tagline','company_address','company_email','company_phone',
    'company_tin','company_website','currency','currency_symbol',
    'invoice_footer','invoice_prefix','order_prefix','receipt_prefix',
    'payment_terms','vat_rate','bank_name','account_name',
    'account_number','bank_branch','company_logo','document_logo','company_favicon',
    'efris_enabled','efris_environment','efris_taxpayer_type','efris_branch_id','efris_device_id',
    'brand_primary_color','brand_accent_color','brand_font_family'
];

ensureSettingsKeys($pdo, $requiredKeys);
$canManageEfris = strtolower((string)($_SESSION['user_role'] ?? '')) === 'admin';
$storedEfrisSettings = getSettings();

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $companyName    = trim($_POST['company_name']    ?? '');
        $companyTagline = trim($_POST['company_tagline'] ?? '');
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
        $brandPrimaryColor = trim((string)($_POST['brand_primary_color'] ?? '#1a2a72'));
        $brandAccentColor = trim((string)($_POST['brand_accent_color'] ?? '#fc7f10'));
        $brandFontFamily = trim((string)($_POST['brand_font_family'] ?? 'DM Sans'));
        $efrisEnabled   = $canManageEfris ? (isset($_POST['efris_enabled']) ? '1' : '0') : setting_value($storedEfrisSettings, 'efris_enabled', '0');
        $efrisEnvironment = $canManageEfris ? trim((string)($_POST['efris_environment'] ?? 'uat')) : setting_value($storedEfrisSettings, 'efris_environment', 'uat');
        $efrisTaxpayerType = $canManageEfris ? trim((string)($_POST['efris_taxpayer_type'] ?? 'vat_registered')) : setting_value($storedEfrisSettings, 'efris_taxpayer_type', 'vat_registered');
        $efrisBranchId  = $canManageEfris ? trim((string)($_POST['efris_branch_id'] ?? '')) : setting_value($storedEfrisSettings, 'efris_branch_id');
        $efrisDeviceId  = $canManageEfris ? trim((string)($_POST['efris_device_id'] ?? '')) : setting_value($storedEfrisSettings, 'efris_device_id');

        if ($companyName === '') throw new RuntimeException('Company name is required.');
        if (strlen($companyTagline) > 180) throw new RuntimeException('Company tagline must be 180 characters or fewer.');
        if ($companyEmail !== '' && !filter_var($companyEmail, FILTER_VALIDATE_EMAIL))
            throw new RuntimeException('Please enter a valid company email address.');
        if ($paymentTerms !== '' && !is_numeric($paymentTerms))
            throw new RuntimeException('Payment terms must be a number.');
        if ($vatRate !== '' && !is_numeric($vatRate))
            throw new RuntimeException('VAT rate must be a number.');
        foreach ([$brandPrimaryColor, $brandAccentColor] as $brandColor) if (!preg_match('/^#[0-9a-fA-F]{6}$/', $brandColor)) throw new RuntimeException('Brand colors must be valid six-digit hex colors.');
        if (!in_array($brandFontFamily, ['DM Sans', 'Arial', 'Georgia', 'System'], true)) throw new RuntimeException('Select a supported font family.');
        if (!in_array($efrisEnvironment, ['uat', 'production'], true)) throw new RuntimeException('Select a valid EFRIS environment.');
        if (!in_array($efrisTaxpayerType, ['vat_registered', 'non_vat_registered'], true)) throw new RuntimeException('Select a valid EFRIS taxpayer type.');
        if (strlen($efrisBranchId) > 100 || strlen($efrisDeviceId) > 150) throw new RuntimeException('EFRIS branch and device identifiers are too long.');

        $logoPath = trim($_POST['existing_company_logo'] ?? '');
        $faviconPath = trim($_POST['existing_company_favicon'] ?? 'assets/img/favicon.png');

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

        $documentLogoPath = trim($_POST['existing_document_logo'] ?? '');

        if (!empty($_FILES['document_logo_file']['name'])) {
            $file = $_FILES['document_logo_file'];
            if (!isset($file['error']) || is_array($file['error']))
                throw new RuntimeException('Invalid document logo upload.');
            if ($file['error'] !== UPLOAD_ERR_OK)
                throw new RuntimeException('Document logo upload failed. Error code: ' . $file['error']);
            if ($file['size'] > 5 * 1024 * 1024)
                throw new RuntimeException('Document logo file too large. Maximum 5 MB.');

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            $allowedMime = [
                'image/jpeg' => 'jpg','image/png' => 'png',
                'image/webp' => 'webp','image/gif' => 'gif','image/svg+xml' => 'svg'
            ];
            if (!isset($allowedMime[$mime]))
                throw new RuntimeException('Only JPG, PNG, WEBP, GIF or SVG document logos are allowed.');

            $ext       = $allowedMime[$mime];
            $uploadDir = __DIR__ . '/assets/img/uploads/settings';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true))
                throw new RuntimeException('Failed to create upload directory.');

            $fileName = 'document_logo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $fileName))
                throw new RuntimeException('Failed to save uploaded document logo.');

            $documentLogoPath = 'assets/img/uploads/settings/' . $fileName;
        }

        if (!empty($_FILES['company_favicon_file']['name'])) {
            $file = $_FILES['company_favicon_file'];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) throw new RuntimeException('Favicon upload failed.');
            if ($file['size'] > 1024 * 1024) throw new RuntimeException('Favicon must be 1 MB or smaller.');
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            $allowedFavicon = ['image/png'=>'png','image/x-icon'=>'ico','image/vnd.microsoft.icon'=>'ico','image/svg+xml'=>'svg'];
            if (!isset($allowedFavicon[$mime])) throw new RuntimeException('Favicon must be PNG, ICO or SVG.');
            $dir = __DIR__ . '/assets/img/uploads/settings';
            if (!is_dir($dir) && !mkdir($dir, 0775, true)) throw new RuntimeException('Failed to create favicon upload directory.');
            $filename = 'favicon_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $allowedFavicon[$mime];
            if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) throw new RuntimeException('Failed to save favicon.');
            $faviconPath = 'assets/img/uploads/settings/' . $filename;
        }

        $pdo->beginTransaction();
        foreach ([
            'company_name'    => $companyName,
            'company_tagline' => $companyTagline,
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
            'document_logo'   => $documentLogoPath,
            'company_favicon' => $faviconPath,
            'brand_primary_color' => $brandPrimaryColor,
            'brand_accent_color' => $brandAccentColor,
            'brand_font_family' => $brandFontFamily,
        ] as $k => $v) saveSetting($pdo, $k, $v);
        if ($canManageEfris) {
            foreach ([
                'efris_enabled' => $efrisEnabled,
                'efris_environment' => $efrisEnvironment,
                'efris_taxpayer_type' => $efrisTaxpayerType,
                'efris_branch_id' => $efrisBranchId,
                'efris_device_id' => $efrisDeviceId,
            ] as $key => $value) saveSetting($pdo, $key, $value);
        }
        $pdo->commit();

        $success = 'Settings updated successfully.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

$settings    = getSettings();
$currentLogo = setting_value($settings, 'company_logo');
$currentDocumentLogo = setting_value($settings, 'document_logo');
$currentFavicon = setting_value($settings, 'company_favicon', 'assets/img/favicon.png');
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
<div class="settings-tabs" role="tablist" aria-label="Settings sections">
    <button type="button" class="settings-tab active" role="tab" aria-selected="true" aria-controls="settings-company">Company Information</button>
    <button type="button" class="settings-tab" role="tab" aria-selected="false" aria-controls="settings-logo">Company Logo</button>
    <button type="button" class="settings-tab" role="tab" aria-selected="false" aria-controls="settings-branding">Branding &amp; Fonts</button>
    <button type="button" class="settings-tab" role="tab" aria-selected="false" aria-controls="settings-bank">Bank Details</button>
    <button type="button" class="settings-tab" role="tab" aria-selected="false" aria-controls="settings-invoice">Invoice &amp; Receipt Settings</button>
    <?php if ($canManageEfris): ?><button type="button" class="settings-tab" role="tab" aria-selected="false" aria-controls="settings-efris">Uganda EFRIS</button><?php endif; ?>
</div>
<div class="settings-grid">

    <!-- Company Information -->
    <div class="settings-card settings-panel active" id="settings-company" role="tabpanel">
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
                <div class="form-group settings-full-col">
                    <label for="company_tagline">Company Tagline / Subtitle</label>
                    <input type="text" id="company_tagline" name="company_tagline" maxlength="180" value="<?= h(setting_value($settings,'company_tagline')) ?>" placeholder="A short line displayed under your company name">
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
    <div class="settings-card settings-panel" id="settings-logo" role="tabpanel" hidden>
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
                <div class="form-group">
                    <label>Favicon (PNG, ICO or SVG)</label>
                    <input type="file" name="company_favicon_file" accept=".png,.ico,.svg,image/png,image/x-icon,image/svg+xml">
                    <input type="hidden" name="existing_company_favicon" value="<?= h($currentFavicon) ?>">
                    <?php if ($currentFavicon && is_file(__DIR__ . '/' . ltrim($currentFavicon, '/'))): ?><img src="<?= h($currentFavicon) ?>" alt="Current favicon" style="width:40px;height:40px;object-fit:contain;margin-top:8px"><?php endif; ?>
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
    <div class="settings-card settings-panel" id="settings-bank" role="tabpanel" hidden>
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
    <div class="settings-card settings-panel" id="settings-invoice" role="tabpanel" hidden>
        <div class="settings-card-header">
            <span class="settings-card-title">Invoice &amp; Receipt Settings</span>
        </div>
        <div class="settings-card-body">
            <div class="settings-logo-wrap" style="margin-bottom:18px">
                <div class="settings-logo-preview">
                    <?php if ($currentDocumentLogo && file_exists(__DIR__ . '/' . ltrim($currentDocumentLogo,'/'))): ?>
                        <img src="<?= h($currentDocumentLogo) ?>" alt="Document logo" id="docLogoPreviewImage">
                    <?php else: ?>
                        <span class="settings-logo-placeholder" id="docLogoPreviewPlaceholder">No document logo</span>
                        <img src="" alt="Document logo" id="docLogoPreviewImage" class="settings-logo-hidden">
                    <?php endif; ?>
                </div>
                <div class="settings-logo-fields">
                    <div class="form-group">
                        <label>Document Logo (Invoices, Receipts &amp; Quotations)</label>
                        <input type="file" id="document_logo_file" name="document_logo_file"
                               accept=".jpg,.jpeg,.png,.webp,.gif,.svg,image/*">
                        <span class="settings-hint">Square or portrait logo recommended. Leave empty to fall back to the company logo.</span>
                    </div>
                    <input type="hidden" name="existing_document_logo" value="<?= h($currentDocumentLogo) ?>">
                    <div class="form-group">
                        <label>Or Manual Path</label>
                        <input type="text" id="document_logo_manual"
                               value="<?= h($currentDocumentLogo) ?>" placeholder="assets/img/logo.png">
                        <span class="settings-hint">Optional path override.</span>
                    </div>
                </div>
            </div>
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

    <div class="settings-card settings-panel" id="settings-branding" role="tabpanel" hidden>
        <div class="settings-card-header"><span class="settings-card-title">Branding &amp; Fonts</span></div>
        <div class="settings-card-body"><div class="form-grid">
            <div class="form-group"><label for="brand_primary_color">Primary brand color</label><input type="color" id="brand_primary_color" name="brand_primary_color" value="<?= h(setting_value($settings, 'brand_primary_color', '#1a2a72')) ?>"></div>
            <div class="form-group"><label for="brand_accent_color">Accent color</label><input type="color" id="brand_accent_color" name="brand_accent_color" value="<?= h(setting_value($settings, 'brand_accent_color', '#fc7f10')) ?>"></div>
            <div class="form-group"><label for="brand_font_family">Application font</label><select id="brand_font_family" name="brand_font_family"><?php foreach (['DM Sans','Arial','Georgia','System'] as $font): ?><option value="<?= h($font) ?>" <?= setting_value($settings, 'brand_font_family', 'DM Sans') === $font ? 'selected' : '' ?>><?= h($font) ?></option><?php endforeach; ?></select></div>
        </div></div>
    </div>

    <?php if ($canManageEfris): ?><div class="settings-card settings-panel" id="settings-efris" role="tabpanel" hidden>
        <div class="settings-card-header"><span class="settings-card-title">Uganda EFRIS Configuration</span></div>
        <div class="settings-card-body">
            <p class="settings-hint">Configure company registration details for EFRIS. The company TIN is shared with Company Information. No invoice is sent to URA until an approved system-to-system connection has been configured and tested.</p>
            <div class="form-grid">
                <div class="form-group settings-full-col"><label><input type="checkbox" name="efris_enabled" value="1" <?= setting_value($settings, 'efris_enabled') === '1' ? 'checked' : '' ?>> Enable EFRIS workflow</label></div>
                <div class="form-group"><label for="efris_environment">Environment</label><select id="efris_environment" name="efris_environment"><option value="uat" <?= setting_value($settings, 'efris_environment', 'uat') === 'uat' ? 'selected' : '' ?>>UAT / Testing</option><option value="production" <?= setting_value($settings, 'efris_environment') === 'production' ? 'selected' : '' ?>>Production</option></select></div>
                <div class="form-group"><label for="efris_taxpayer_type">Taxpayer registration</label><select id="efris_taxpayer_type" name="efris_taxpayer_type"><option value="vat_registered" <?= setting_value($settings, 'efris_taxpayer_type', 'vat_registered') === 'vat_registered' ? 'selected' : '' ?>>VAT registered (e-invoice)</option><option value="non_vat_registered" <?= setting_value($settings, 'efris_taxpayer_type') === 'non_vat_registered' ? 'selected' : '' ?>>Non-VAT registered (e-receipt)</option></select></div>
                <div class="form-group"><label for="efris_branch_id">URA/EFRIS branch identifier</label><input id="efris_branch_id" name="efris_branch_id" maxlength="100" value="<?= h(setting_value($settings, 'efris_branch_id')) ?>"></div>
                <div class="form-group"><label for="efris_device_id">EFRIS device / integration identifier</label><input id="efris_device_id" name="efris_device_id" maxlength="150" value="<?= h(setting_value($settings, 'efris_device_id')) ?>"></div>
            </div>
            <p class="settings-hint" style="margin-top:14px">Direct URA integration requires taxpayer onboarding, the current URA interface specification, secure keys/certificates, and UAT approval. Keep credentials outside this settings form and never commit them to source control.</p>
        </div>
    </div><?php endif; ?>

    <!-- Actions -->
    <div class="settings-card settings-full-col">
        <div class="settings-card-body settings-actions-bar">
            <a href="dashboard.php" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Save Settings</button>
        </div>
    </div>

</div><!-- /.settings-grid -->
</form>

<style>
.settings-tabs{display:flex;gap:6px;overflow-x:auto;border-bottom:1px solid var(--border);margin-bottom:18px}.settings-tab{flex:0 0 auto;padding:12px 16px;border:0;border-bottom:2px solid transparent;background:transparent;color:var(--text-muted);font:inherit;font-weight:600;cursor:pointer}.settings-tab.active{color:var(--navy);border-bottom-color:var(--gold)}.settings-panel[hidden]{display:none!important}
</style>
<script>
(function () {
    const tabs = [...document.querySelectorAll('.settings-tab')];
    tabs.forEach(tab => tab.addEventListener('click', () => {
        tabs.forEach(item => { const active = item === tab; item.classList.toggle('active', active); item.setAttribute('aria-selected', active ? 'true' : 'false'); document.getElementById(item.getAttribute('aria-controls')).hidden = !active; });
    }));
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

    const docFileInput   = document.getElementById('document_logo_file');
    const docManualInput = document.getElementById('document_logo_manual');
    const docHiddenPath  = document.querySelector('input[name="existing_document_logo"]');
    const docPreviewImg  = document.getElementById('docLogoPreviewImage');
    const docPlaceholder = document.getElementById('docLogoPreviewPlaceholder');

    if (docManualInput && docHiddenPath) {
        docManualInput.addEventListener('change', () => docHiddenPath.value = docManualInput.value.trim());
    }

    if (docFileInput) {
        docFileInput.addEventListener('change', function () {
            const file = this.files && this.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = ev => {
                if (docPreviewImg) {
                    docPreviewImg.src = ev.target.result;
                    docPreviewImg.classList.remove('settings-logo-hidden');
                }
                if (docPlaceholder) docPlaceholder.style.display = 'none';
            };
            reader.readAsDataURL(file);
        });
    }
})();
</script>

<?php include 'includes/footer.php'; ?>
