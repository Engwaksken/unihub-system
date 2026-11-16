<?php
declare(strict_types=1);

/**
 * Automatic payment reminders for invoices with an outstanding balance.
 *
 * Run it on a schedule (e.g. once a day) from the server's cron:
 *   php /path/to/unihub-system/cron/send_invoice_reminders.php
 *
 * Hosts that only offer URL-based cron can call it over HTTP instead when
 * REMINDER_CRON_TOKEN is set in .env:
 *   https://your-domain.example/cron/send_invoice_reminders.php?token=<REMINDER_CRON_TOKEN>
 *
 * Pass --dry-run (CLI) to list who would be reminded without sending anything.
 *
 * Unpaid invoices past their due date are first marked 'overdue'.
 *
 * Each pending invoice (unpaid / partial / overdue) is reminded at most once
 * every `reminder_interval_days` (Settings → Invoice & Receipt, default 7).
 * The first reminder goes out once the invoice is that many days old, or as
 * soon as its due date is reached, whichever comes first.
 */

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    // Web access is only allowed with the secret token; otherwise pretend the script doesn't exist.
    require_once dirname(__DIR__) . '/config/db.php';
    $expected = (string)appEnv('REMINDER_CRON_TOKEN', '');
    $given    = (string)($_GET['token'] ?? '');
    if ($expected === '' || !hash_equals($expected, $given)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/email-function.php';

set_time_limit(0);

$dryRun = $isCli && in_array('--dry-run', $argv ?? [], true);

function reminderLog(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

// Prevent overlapping runs if cron fires again before the previous run ends.
$lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unihub_invoice_reminders.lock';
$lock = fopen($lockFile, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    reminderLog('Another reminder run is in progress; exiting.');
    exit(0);
}

try {
    $pdo = getDB();
    $cfg = getSettings();
    ensureEmailLogsSchema($pdo);

    $intervalDays = (int)($cfg['reminder_interval_days'] ?? 0);
    if ($intervalDays < 1) {
        $intervalDays = 7;
    }

    $currencySymbol = trim((string)($cfg['currency_symbol'] ?? 'UGX')) ?: 'UGX';
    $companyName    = trim((string)($cfg['company_name'] ?? '')) ?: 'Unihub Solutions Uganda';
    $companyPhone   = trim((string)($cfg['company_phone'] ?? ''));
    $companyEmail   = trim((string)($cfg['company_email'] ?? ''));

    // Unpaid invoices past their due date become 'overdue'. Partially paid ones keep 'partial'
    // so the payment progress stays visible; their reminders are still worded as overdue.
    $markOverdue = $pdo->prepare("
        UPDATE invoices
        SET status = 'overdue'
        WHERE LOWER(status) = 'unpaid'
          AND due_date IS NOT NULL
          AND due_date < CURDATE()
    ");
    if ($dryRun) {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE LOWER(status) = 'unpaid' AND due_date IS NOT NULL AND due_date < CURDATE()")->fetchColumn();
        reminderLog("[dry run] Would mark {$count} unpaid invoice(s) as overdue.");
    } else {
        $markOverdue->execute();
        reminderLog('Marked ' . $markOverdue->rowCount() . ' unpaid invoice(s) as overdue.');
    }

    // Reminders are on unless an admin explicitly switched them off.
    if (($cfg['auto_reminders_enabled'] ?? '1') === '0') {
        reminderLog('Automatic reminders are disabled in settings; skipping emails.');
        exit(0);
    }

    $stmt = $pdo->prepare("
        SELECT
            i.id,
            i.invoice_number,
            i.issued_date,
            i.due_date,
            i.total,
            i.status,
            o.order_number,
            c.name  AS client_name,
            c.email AS client_email,
            COALESCE((SELECT SUM(r.amount_paid) FROM receipts r WHERE r.invoice_id = i.id), 0) AS total_paid,
            (SELECT MAX(l.sent_at) FROM email_logs l
              WHERE l.email_type = 'reminder' AND l.reference_id = i.id AND l.status = 'sent') AS last_reminder_at
        FROM invoices i
        INNER JOIN orders  o ON o.id = i.order_id
        INNER JOIN clients c ON c.id = o.client_id
        WHERE LOWER(i.status) IN ('unpaid', 'partial', 'overdue')
          AND c.email IS NOT NULL AND c.email <> ''
        ORDER BY i.due_date ASC, i.id ASC
    ");
    $stmt->execute();
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $today     = new DateTimeImmutable('today');
    $threshold = (new DateTimeImmutable('now'))->modify("-{$intervalDays} days");
    // A little slack so a daily cron at a fixed time doesn't slip a day because last week's send finished seconds later.
    $repeatThreshold = $threshold->modify('+1 hour');

    $sent = 0;
    $failed = 0;
    $skipped = 0;

    foreach ($invoices as $inv) {
        $invoiceNo = (string)$inv['invoice_number'];
        $balance   = max(0.0, (float)$inv['total'] - (float)$inv['total_paid']);

        if ($balance <= 0.00001) {
            $skipped++;
            continue;
        }

        $dueDate    = !empty($inv['due_date']) ? new DateTimeImmutable((string)$inv['due_date']) : null;
        $issuedDate = !empty($inv['issued_date']) ? new DateTimeImmutable((string)$inv['issued_date']) : null;

        if (!empty($inv['last_reminder_at'])) {
            // Already reminded: wait a full interval before the next one.
            if (new DateTimeImmutable((string)$inv['last_reminder_at']) > $repeatThreshold) {
                $skipped++;
                continue;
            }
        } else {
            // Never reminded: start once the due date arrives or the invoice is an interval old.
            $isDue  = $dueDate !== null && $dueDate <= $today;
            $isAged = $issuedDate === null || $issuedDate <= $threshold;
            if (!$isDue && !$isAged) {
                $skipped++;
                continue;
            }
        }

        $toEmail = trim((string)$inv['client_email']);
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            reminderLog("Skipping {$invoiceNo}: invalid client email '{$toEmail}'.");
            $skipped++;
            continue;
        }

        $clientName = trim((string)($inv['client_name'] ?? '')) ?: 'Customer';
        $isOverdue  = $dueDate !== null && $dueDate < $today;
        $daysLate   = $isOverdue ? (int)$dueDate->diff($today)->days : 0;

        $subject = ($isOverdue ? 'Overdue payment reminder' : 'Payment reminder')
            . ': Invoice ' . $invoiceNo . ' - ' . $companyName;

        $body = '<p>Dear ' . ef_h($clientName) . ',</p>';
        if ($isOverdue) {
            $body .= '<p>This is a reminder that invoice <strong>' . ef_h($invoiceNo) . '</strong> is now '
                . $daysLate . ' day' . ($daysLate === 1 ? '' : 's') . ' past its due date and still has an outstanding balance.</p>';
        } else {
            $body .= '<p>This is a friendly reminder that invoice <strong>' . ef_h($invoiceNo) . '</strong> has an outstanding balance.</p>';
        }

        $body .= '<p>'
            . '<strong>Invoice No:</strong> ' . ef_h($invoiceNo) . '<br>'
            . (!empty($inv['order_number']) ? '<strong>Order No:</strong> ' . ef_h($inv['order_number']) . '<br>' : '')
            . '<strong>Invoice Total:</strong> ' . ef_h(ef_format_money($inv['total'], $currencySymbol)) . '<br>'
            . ((float)$inv['total_paid'] > 0 ? '<strong>Amount Paid:</strong> ' . ef_h(ef_format_money($inv['total_paid'], $currencySymbol)) . '<br>' : '')
            . '<strong>Balance Due:</strong> ' . ef_h(ef_format_money($balance, $currencySymbol)) . '<br>'
            . ($dueDate !== null ? '<strong>Due Date:</strong> ' . ef_h($dueDate->format('d F Y')) . '<br>' : '')
            . '</p>';

        $bankLines = array_filter([
            !empty($cfg['bank_name'])      ? 'Bank: ' . $cfg['bank_name'] : '',
            !empty($cfg['bank_branch'])    ? 'Branch: ' . $cfg['bank_branch'] : '',
            !empty($cfg['account_name'])   ? 'Account Name: ' . $cfg['account_name'] : '',
            !empty($cfg['account_number']) ? 'Account Number: ' . $cfg['account_number'] : '',
        ]);
        if ($bankLines) {
            $body .= '<p><strong>Payment Details:</strong><br>' . implode('<br>', array_map('ef_h', $bankLines)) . '</p>';
        }
        if (!empty($cfg['invoice_footer'])) {
            $body .= '<p>' . nl2br(ef_h($cfg['invoice_footer'])) . '</p>';
        }

        $contact = array_filter([$companyPhone, $companyEmail]);
        $body .= '<p>If you have already made this payment, please disregard this message'
            . ($contact ? ' or let us know on ' . ef_h(implode(' / ', $contact)) : '')
            . '.</p>'
            . '<p>Thank you for your business.</p>'
            . '<p>Regards,<br>' . ef_h($companyName) . '</p>';

        // Re-attach the invoice PDF if one was generated when the invoice was first emailed.
        $safeInvoiceNo = preg_replace('/[^A-Za-z0-9\-_]/', '_', $invoiceNo);
        $pdfPath = dirname(__DIR__) . '/uploads/invoices/invoice_' . $safeInvoiceNo . '.pdf';
        $attachments = is_file($pdfPath) ? [['path' => $pdfPath, 'name' => basename($pdfPath)]] : [];

        if ($dryRun) {
            reminderLog(sprintf('[dry run] Would remind %s <%s> about %s (balance %s%s).',
                $clientName, $toEmail, $invoiceNo, ef_format_money($balance, $currencySymbol),
                $isOverdue ? ", {$daysLate} day(s) overdue" : ''));
            $sent++;
            continue;
        }

        $result = ef_send_email([
            'to_email'    => $toEmail,
            'to_name'     => $clientName,
            'subject'     => $subject,
            'html_body'   => $body,
            'text_body'   => trim(html_entity_decode(strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n\n"], $body)), ENT_QUOTES, 'UTF-8')),
            'attachments' => $attachments,
        ]);

        $pdo->prepare("INSERT INTO email_logs
            (email_type, reference_id, recipient_name, recipient_email, subject, status, attachment_path, sent_at, error_message)
            VALUES ('reminder', ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([
                (int)$inv['id'],
                $clientName,
                $toEmail,
                $subject,
                $result['success'] ? 'sent' : 'failed',
                $attachments ? $pdfPath : null,
                $result['success'] ? date('Y-m-d H:i:s') : null,
                $result['error'],
            ]);

        if ($result['success']) {
            $sent++;
            reminderLog("Reminder sent for {$invoiceNo} to {$toEmail}.");
        } else {
            $failed++;
            reminderLog("Reminder FAILED for {$invoiceNo} to {$toEmail}: " . (string)$result['error']);
        }
    }

    reminderLog(sprintf(
        ($dryRun ? '[dry run] ' : '') . 'Done. %d pending invoice(s) checked: %d sent, %d failed, %d not due for a reminder yet.',
        count($invoices), $sent, $failed, $skipped
    ));
    exit($failed > 0 ? 1 : 0);
} catch (Throwable $e) {
    error_log('Invoice reminder run failed: ' . $e->getMessage());
    reminderLog('Run failed: ' . $e->getMessage());
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
