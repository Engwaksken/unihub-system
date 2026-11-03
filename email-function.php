<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';

$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

if (!function_exists('ef_get_pdo')) {
    function ef_get_pdo(): PDO
    {
        if (!function_exists('getDB')) {
            throw new RuntimeException('Database helper getDB() was not found.');
        }

        $db = getDB();
        if (!$db instanceof PDO) {
            throw new RuntimeException('getDB() exists but does not return a PDO instance.');
        }

        return $db;
    }
}

if (!function_exists('ef_h')) {
    function ef_h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('ef_format_money')) {
    function ef_format_money($amount, string $currency = 'UGX'): string
    {
        $value = is_numeric($amount) ? (float)$amount : 0.0;
        return trim($currency) . ' ' . number_format($value, 2);
    }
}

if (!function_exists('ef_get_settings')) {
    function ef_get_settings(PDO $pdo): array
    {
        $stmt = $pdo->query("SELECT `key`, `value` FROM settings");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $settings = [];
        foreach ($rows as $row) {
            $key = (string)($row['key'] ?? '');
            if ($key !== '') {
                $settings[$key] = $row['value'];
            }
        }

        return $settings;
    }
}

if (!function_exists('ef_get_email_settings')) {
    function ef_get_email_settings(PDO $pdo): array
    {
        $stmt = $pdo->query("
            SELECT id, smtp_host, smtp_port, smtp_user, smtp_password, smtp_secure, from_email, from_name
            FROM email_settings
            ORDER BY id DESC
            LIMIT 1
        ");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException('No email settings found in email_settings table.');
        }

        // Environment values take precedence so production credentials need not live in the database.
        $smtpHost   = trim((string)appEnv('SMTP_HOST', (string)($row['smtp_host'] ?? '')));
        $smtpPort   = (int)appEnv('SMTP_PORT', (string)($row['smtp_port'] ?? 0));
        $smtpUser   = trim((string)appEnv('SMTP_USER', (string)($row['smtp_user'] ?? '')));
        $smtpPass   = (string)appEnv('SMTP_PASS', (string)($row['smtp_password'] ?? ''));
        $smtpSecure = strtolower(trim((string)appEnv('SMTP_SECURE', (string)($row['smtp_secure'] ?? ''))));
        $fromEmail  = trim((string)appEnv('SMTP_FROM_EMAIL', (string)($row['from_email'] ?? '')));
        $fromName   = trim((string)appEnv('SMTP_FROM_NAME', (string)($row['from_name'] ?? '')));

        if ($smtpHost === '') {
            throw new RuntimeException('SMTP host is missing.');
        }
        if ($smtpPort <= 0) {
            throw new RuntimeException('SMTP port is invalid.');
        }
        if ($fromEmail === '') {
            throw new RuntimeException('From email is missing.');
        }

        if ($smtpSecure === 'ssl') {
            $smtpSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($smtpSecure === 'tls' || $smtpSecure === 'starttls') {
            $smtpSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($smtpSecure === 'none' || $smtpSecure === '') {
            $smtpSecure = '';
        }

        return [
            'id'            => (int)$row['id'],
            'smtp_host'     => $smtpHost,
            'smtp_port'     => $smtpPort,
            'smtp_user'     => $smtpUser,
            'smtp_password' => $smtpPass,
            'smtp_secure'   => $smtpSecure,
            'from_email'    => $fromEmail,
            'from_name'     => $fromName !== '' ? $fromName : $fromEmail,
        ];
    }
}

if (!function_exists('ef_build_mailer')) {
    function ef_build_mailer(array $smtp): PHPMailer
    {
        if (!class_exists(PHPMailer::class)) {
            throw new RuntimeException('PHPMailer is not installed. Run: composer require phpmailer/phpmailer');
        }

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = (string)$smtp['smtp_host'];
        $mail->Port       = (int)$smtp['smtp_port'];
        $mail->SMTPAuth   = !empty($smtp['smtp_user']) || !empty($smtp['smtp_password']);
        $mail->Username   = (string)$smtp['smtp_user'];
        $mail->Password   = (string)$smtp['smtp_password'];
        $mail->CharSet    = 'UTF-8';
        $mail->isHTML(true);
        $mail->Timeout    = 30;

        if (!empty($smtp['smtp_secure'])) {
            $mail->SMTPSecure = (string)$smtp['smtp_secure'];
        }

        $mail->setFrom((string)$smtp['from_email'], (string)$smtp['from_name']);

        return $mail;
    }
}

if (!function_exists('configureMailer')) {
    function configureMailer(): PHPMailer
    {
        $pdo  = ef_get_pdo();
        $smtp = ef_get_email_settings($pdo);
        return ef_build_mailer($smtp);
    }
}

if (!function_exists('ef_resolve_attachment_path')) {
    function ef_resolve_attachment_path(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            throw new RuntimeException('Attachment path is empty.');
        }

        if (preg_match('~^([a-zA-Z]:[\\\\/]|/)~', $path)) {
            $resolved = $path;
        } else {
            $resolved = __DIR__ . '/' . ltrim($path, '/');
        }

        if (!is_file($resolved)) {
            throw new RuntimeException('Attachment file not found: ' . $path);
        }

        return $resolved;
    }
}

if (!function_exists('sendEmailWithAttachment')) {
    function sendEmailWithAttachment(
        string $to,
        string $subject,
        string $body,
        string $attachmentPath,
        string $attachmentName = '',
        string $toName = '',
        string $emailType = 'other',
        int $referenceId = 0
    ): bool {
        $sent = false;
        $error = null;
        try {
            $mail = configureMailer();
            $mail->addAddress($to, $toName !== '' ? $toName : $to);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $body)));

            $resolved = ef_resolve_attachment_path($attachmentPath);
            $mail->addAttachment($resolved, $attachmentName !== '' ? $attachmentName : basename($resolved));
            $sent = $mail->send();
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        try {
            $pdo = ef_get_pdo();
            ensureEmailLogsSchema($pdo);
            $log = $pdo->prepare("INSERT INTO email_logs
                (email_type, reference_id, recipient_name, recipient_email, subject, status, attachment_path, sent_at, error_message)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $log->execute([
                in_array($emailType, ['invoice', 'receipt'], true) ? $emailType : 'other',
                max(0, $referenceId), $toName ?: null, $to, $subject,
                $sent ? 'sent' : 'failed', $attachmentPath ?: null,
                $sent ? date('Y-m-d H:i:s') : null, $error,
            ]);
        } catch (Throwable $logError) {
            error_log('Failed to write email log: ' . $logError->getMessage());
        }

        if (!$sent && $error !== null) {
            throw new RuntimeException($error);
        }
        return $sent;
    }
}

if (!function_exists('ef_send_email')) {
    function ef_send_email(array $data): array
    {
        try {
            $toEmail     = trim((string)($data['to_email'] ?? ''));
            $toName      = trim((string)($data['to_name'] ?? ''));
            $subject     = trim((string)($data['subject'] ?? ''));
            $htmlBody    = (string)($data['html_body'] ?? '');
            $textBody    = (string)($data['text_body'] ?? strip_tags($htmlBody));
            $attachments = is_array($data['attachments'] ?? null) ? $data['attachments'] : [];

            if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Recipient email is invalid.');
            }
            if ($subject === '') {
                throw new RuntimeException('Email subject is required.');
            }
            if ($htmlBody === '') {
                throw new RuntimeException('Email body is empty.');
            }

            $mail = configureMailer();
            $mail->addAddress($toEmail, $toName !== '' ? $toName : $toEmail);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = $textBody !== '' ? $textBody : strip_tags($htmlBody);

            foreach ($attachments as $attachment) {
                if (!is_array($attachment)) {
                    continue;
                }

                $path = trim((string)($attachment['path'] ?? ''));
                $name = trim((string)($attachment['name'] ?? ''));

                if ($path === '') {
                    continue;
                }

                $resolved = ef_resolve_attachment_path($path);
                $mail->addAttachment($resolved, $name !== '' ? $name : basename($resolved));
            }

            $mail->send();

            return [
                'success' => true,
                'message' => 'Email sent successfully.',
                'error'   => null,
            ];
        } catch (PHPMailerException $e) {
            return [
                'success' => false,
                'message' => 'Failed to send email.',
                'error'   => $e->getMessage(),
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => 'Failed to send email.',
                'error'   => $e->getMessage(),
            ];
        }
    }
}

if (!function_exists('ef_get_invoice_data')) {
    function ef_get_invoice_data(PDO $pdo, int $invoiceId): array
    {
        $stmt = $pdo->prepare("
            SELECT i.*,
                   o.order_number,
                   o.notes AS order_notes,
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
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$invoice) {
            throw new RuntimeException('Invoice not found.');
        }

        return $invoice;
    }
}

if (!function_exists('ef_get_receipt_data')) {
    function ef_get_receipt_data(PDO $pdo, int $receiptId): array
    {
        $stmt = $pdo->prepare("
            SELECT r.*,
                   i.invoice_number,
                   i.total AS invoice_total,
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
        $receipt = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$receipt) {
            throw new RuntimeException('Receipt not found.');
        }

        return $receipt;
    }
}

if (!function_exists('ef_send_invoice_email')) {
    function ef_send_invoice_email(int $invoiceId, ?string $attachmentPath = null): array
    {
        try {
            $pdo      = ef_get_pdo();
            $settings = ef_get_settings($pdo);
            $invoice  = ef_get_invoice_data($pdo, $invoiceId);

            $companyName    = trim((string)($settings['company_name'] ?? 'Our Company'));
            $companyPhone   = trim((string)($settings['company_phone'] ?? ''));
            $companyEmail   = trim((string)($settings['company_email'] ?? ''));
            $companyWebsite = trim((string)($settings['company_website'] ?? ''));
            $currency       = trim((string)($settings['currency_symbol'] ?? $settings['currency'] ?? 'UGX'));

            $clientName  = trim((string)($invoice['client_name'] ?? 'Customer'));
            $clientEmail = trim((string)($invoice['client_email'] ?? ''));
            $invoiceNo   = trim((string)($invoice['invoice_number'] ?? ''));
            $total       = ef_format_money($invoice['total'] ?? 0, $currency);
            $issuedDate  = !empty($invoice['issued_date']) ? date('d M Y', strtotime((string)$invoice['issued_date'])) : '-';
            $dueDate     = !empty($invoice['due_date']) ? date('d M Y', strtotime((string)$invoice['due_date'])) : '-';

            if ($clientEmail === '') {
                throw new RuntimeException('Client email is missing for this invoice.');
            }

            $subject = 'Invoice ' . $invoiceNo . ' from ' . $companyName;

            $html = '
                <div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.7;color:#222;">
                    <p>Dear ' . ef_h($clientName) . ',</p>
                    <p>Please find your invoice details below:</p>

                    <table style="border-collapse:collapse;width:100%;max-width:580px;margin:16px 0;">
                        <tr>
                            <td style="padding:8px;border:1px solid #ddd;"><strong>Invoice Number</strong></td>
                            <td style="padding:8px;border:1px solid #ddd;">' . ef_h($invoiceNo) . '</td>
                        </tr>
                        <tr>
                            <td style="padding:8px;border:1px solid #ddd;"><strong>Issued Date</strong></td>
                            <td style="padding:8px;border:1px solid #ddd;">' . ef_h($issuedDate) . '</td>
                        </tr>
                        <tr>
                            <td style="padding:8px;border:1px solid #ddd;"><strong>Due Date</strong></td>
                            <td style="padding:8px;border:1px solid #ddd;">' . ef_h($dueDate) . '</td>
                        </tr>
                        <tr>
                            <td style="padding:8px;border:1px solid #ddd;"><strong>Total Amount</strong></td>
                            <td style="padding:8px;border:1px solid #ddd;">' . ef_h($total) . '</td>
                        </tr>
                    </table>

                    <p>Kindly review and make payment by the due date.</p>
                    <p>Thank you for doing business with us.</p>

                    <p style="margin-top:24px;">
                        Regards,<br>
                        <strong>' . ef_h($companyName) . '</strong><br>' .
                        ($companyPhone !== '' ? ef_h($companyPhone) . '<br>' : '') .
                        ($companyEmail !== '' ? ef_h($companyEmail) . '<br>' : '') .
                        ($companyWebsite !== '' ? ef_h($companyWebsite) : '') .
                    '</p>
                </div>
            ';

            $text = "Dear {$clientName},\n\n"
                . "Please find your invoice details below:\n"
                . "Invoice Number: {$invoiceNo}\n"
                . "Issued Date: {$issuedDate}\n"
                . "Due Date: {$dueDate}\n"
                . "Total Amount: {$total}\n\n"
                . "Kindly review and make payment by the due date.\n\n"
                . "Thank you for doing business with us.\n\n"
                . "{$companyName}";

            $attachments = [];
            if ($attachmentPath !== null && trim($attachmentPath) !== '') {
                $attachments[] = [
                    'path' => $attachmentPath,
                    'name' => 'Invoice-' . $invoiceNo . '.pdf',
                ];
            }

            return ef_send_email([
                'to_email'    => $clientEmail,
                'to_name'     => $clientName,
                'subject'     => $subject,
                'html_body'   => $html,
                'text_body'   => $text,
                'attachments' => $attachments,
            ]);
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => 'Failed to send invoice email.',
                'error'   => $e->getMessage(),
            ];
        }
    }
}

if (!function_exists('ef_send_receipt_email')) {
    function ef_send_receipt_email(int $receiptId, ?string $attachmentPath = null): array
    {
        try {
            $pdo      = ef_get_pdo();
            $settings = ef_get_settings($pdo);
            $receipt  = ef_get_receipt_data($pdo, $receiptId);

            $companyName    = trim((string)($settings['company_name'] ?? 'Our Company'));
            $companyPhone   = trim((string)($settings['company_phone'] ?? ''));
            $companyEmail   = trim((string)($settings['company_email'] ?? ''));
            $companyWebsite = trim((string)($settings['company_website'] ?? ''));
            $currency       = trim((string)($settings['currency_symbol'] ?? $settings['currency'] ?? 'UGX'));

            $clientName  = trim((string)($receipt['client_name'] ?? 'Customer'));
            $clientEmail = trim((string)($receipt['client_email'] ?? ''));
            $receiptNo   = trim((string)($receipt['receipt_number'] ?? ''));
            $invoiceNo   = trim((string)($receipt['invoice_number'] ?? ''));
            $amountRaw   = $receipt['amount_paid'] ?? $receipt['amount'] ?? $receipt['invoice_total'] ?? 0;
            $amount      = ef_format_money($amountRaw, $currency);
            $paymentDate = !empty($receipt['payment_date'])
                ? date('d M Y', strtotime((string)$receipt['payment_date']))
                : (!empty($receipt['issued_at']) ? date('d M Y', strtotime((string)$receipt['issued_at'])) : date('d M Y'));

            if ($clientEmail === '') {
                throw new RuntimeException('Client email is missing for this receipt.');
            }

            $subject = 'Receipt ' . $receiptNo . ' from ' . $companyName;

            $html = '
                <div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.7;color:#222;">
                    <p>Dear ' . ef_h($clientName) . ',</p>
                    <p>Thank you for your payment. Your receipt details are below:</p>

                    <table style="border-collapse:collapse;width:100%;max-width:580px;margin:16px 0;">
                        <tr>
                            <td style="padding:8px;border:1px solid #ddd;"><strong>Receipt Number</strong></td>
                            <td style="padding:8px;border:1px solid #ddd;">' . ef_h($receiptNo) . '</td>
                        </tr>
                        <tr>
                            <td style="padding:8px;border:1px solid #ddd;"><strong>Invoice Number</strong></td>
                            <td style="padding:8px;border:1px solid #ddd;">' . ef_h($invoiceNo) . '</td>
                        </tr>
                        <tr>
                            <td style="padding:8px;border:1px solid #ddd;"><strong>Amount Paid</strong></td>
                            <td style="padding:8px;border:1px solid #ddd;">' . ef_h($amount) . '</td>
                        </tr>
                        <tr>
                            <td style="padding:8px;border:1px solid #ddd;"><strong>Payment Date</strong></td>
                            <td style="padding:8px;border:1px solid #ddd;">' . ef_h($paymentDate) . '</td>
                        </tr>
                    </table>

                    <p>Please keep this receipt for your records.</p>

                    <p style="margin-top:24px;">
                        Regards,<br>
                        <strong>' . ef_h($companyName) . '</strong><br>' .
                        ($companyPhone !== '' ? ef_h($companyPhone) . '<br>' : '') .
                        ($companyEmail !== '' ? ef_h($companyEmail) . '<br>' : '') .
                        ($companyWebsite !== '' ? ef_h($companyWebsite) : '') .
                    '</p>
                </div>
            ';

            $text = "Dear {$clientName},\n\n"
                . "Thank you for your payment. Your receipt details are below:\n"
                . "Receipt Number: {$receiptNo}\n"
                . "Invoice Number: {$invoiceNo}\n"
                . "Amount Paid: {$amount}\n"
                . "Payment Date: {$paymentDate}\n\n"
                . "Please keep this receipt for your records.\n\n"
                . "{$companyName}";

            $attachments = [];
            if ($attachmentPath !== null && trim($attachmentPath) !== '') {
                $attachments[] = [
                    'path' => $attachmentPath,
                    'name' => 'Receipt-' . $receiptNo . '.pdf',
                ];
            }

            return ef_send_email([
                'to_email'    => $clientEmail,
                'to_name'     => $clientName,
                'subject'     => $subject,
                'html_body'   => $html,
                'text_body'   => $text,
                'attachments' => $attachments,
            ]);
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => 'Failed to send receipt email.',
                'error'   => $e->getMessage(),
            ];
        }
    }
}

if (!function_exists('ef_test_email_settings')) {
    function ef_test_email_settings(string $toEmail, string $toName = ''): array
    {
        $subject = 'SMTP Test Email';
        $html = '
            <div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.7;color:#222;">
                <p>Hello,</p>
                <p>This is a test email from your system SMTP configuration.</p>
                <p>If you received this message, your email settings are working correctly.</p>
            </div>
        ';
        $text = "Hello,\n\nThis is a test email from your system SMTP configuration.";

        return ef_send_email([
            'to_email'  => $toEmail,
            'to_name'   => $toName,
            'subject'   => $subject,
            'html_body' => $html,
            'text_body' => $text,
        ]);
    }
}
