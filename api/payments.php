<?php
declare(strict_types=1);

require_once '../config/db.php';

header('Content-Type: application/json; charset=utf-8');

if (!function_exists('jsonResponse')) {
    function jsonResponse(array $data, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($data);
        exit;
    }
}

if (!function_exists('normalizeStatus')) {
    function normalizeStatus(?string $status): string
    {
        $status = strtolower(trim((string)$status));
        return $status !== '' ? $status : 'unpaid';
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse([
        'success' => false,
        'error'   => 'Method not allowed'
    ], 405);
}

try {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        $data = $_POST ?: [];
    }

    $pdo = getDB();
    ensureUserSignatureProfile($pdo);
    $cfg = getSettings();

    $invoiceId     = (int)($data['invoice_id'] ?? 0);
    $amountPaid    = (float)($data['amount_paid'] ?? 0);
    $paymentDate   = trim((string)($data['payment_date'] ?? date('Y-m-d')));
    $paymentMethod = trim((string)($data['payment_method'] ?? 'cash'));
    $reference     = trim((string)($data['reference'] ?? ''));
    $notes         = trim((string)($data['notes'] ?? ''));

    if ($invoiceId <= 0) {
        jsonResponse([
            'success' => false,
            'error'   => 'Invoice ID required'
        ], 400);
    }

    if ($amountPaid <= 0) {
        jsonResponse([
            'success' => false,
            'error'   => 'Amount must be greater than zero'
        ], 400);
    }

    if ($paymentDate === '') {
        $paymentDate = date('Y-m-d');
    }

    $dateObj = DateTime::createFromFormat('Y-m-d', $paymentDate);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $paymentDate) {
        jsonResponse([
            'success' => false,
            'error'   => 'Invalid payment date format'
        ], 400);
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT *
        FROM invoices
        WHERE id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$invoiceId]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        $pdo->rollBack();
        jsonResponse([
            'success' => false,
            'error'   => 'Invoice not found'
        ], 404);
    }

    $invoiceStatus = normalizeStatus($invoice['status'] ?? 'unpaid');

    if ($invoiceStatus === 'cancelled') {
        $pdo->rollBack();
        jsonResponse([
            'success' => false,
            'error'   => 'Invoice is cancelled'
        ], 400);
    }

    $invoiceTotal = (float)($invoice['total'] ?? 0);
    if ($invoiceTotal <= 0) {
        $pdo->rollBack();
        jsonResponse([
            'success' => false,
            'error'   => 'Invoice total is invalid'
        ], 400);
    }

    $sumStmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount_paid), 0) AS total_paid
        FROM receipts
        WHERE invoice_id = ?
    ");
    $sumStmt->execute([$invoiceId]);
    $alreadyPaid = (float)$sumStmt->fetchColumn();

    $balanceBefore = max(0, $invoiceTotal - $alreadyPaid);

    if ($balanceBefore <= 0 || $invoiceStatus === 'paid') {
        $pdo->rollBack();
        jsonResponse([
            'success' => false,
            'error'   => 'Invoice already paid'
        ], 400);
    }

    if ($amountPaid > $balanceBefore) {
        $pdo->rollBack();
        jsonResponse([
            'success' => false,
            'error'   => 'Amount paid exceeds outstanding balance of ' . number_format($balanceBefore, 2)
        ], 400);
    }

    $receiptPrefix = $cfg['receipt_prefix'] ?? 'RCP-';
    $receiptNumber = nextNumber('receipts', 'receipt_number', $receiptPrefix);

    $insert = $pdo->prepare("
        INSERT INTO receipts (
            receipt_number,
            invoice_id,
            payment_date,
            amount_paid,
            payment_method,
            reference,
            notes,
            authorized_name,
            signature_type,
            signature_data,
            signed_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draw', ?, CASE WHEN ? IS NULL OR ? = '' THEN NULL ELSE NOW() END)
    ");
    $insert->execute([
        $receiptNumber,
        $invoiceId,
        $paymentDate,
        $amountPaid,
        $paymentMethod,
        $reference,
        $notes,
        $signatory['authorized_name'] ?? null,
        $signatory['profile_signature_data'] ?? null,
        $signatory['profile_signature_data'] ?? null,
        $signatory['profile_signature_data'] ?? null
    ]);

    $receiptId = (int)$pdo->lastInsertId();

    $newPaidTotal   = $alreadyPaid + $amountPaid;
    $balanceAfter   = max(0, $invoiceTotal - $newPaidTotal);
    $newInvStatus   = 'unpaid';
    $newOrderStatus = 'pending';

    if ($balanceAfter <= 0.00001) {
        $newInvStatus   = 'paid';
        $newOrderStatus = 'paid';
    } elseif ($newPaidTotal > 0) {
        $newInvStatus   = 'partial';
        $newOrderStatus = 'partial';
    }

    $updateInvoice = $pdo->prepare("
        UPDATE invoices
        SET status = ?
        WHERE id = ?
    ");
    $updateInvoice->execute([$newInvStatus, $invoiceId]);

    if (!empty($invoice['order_id'])) {
        $updateOrder = $pdo->prepare("
            UPDATE orders
            SET status = ?
            WHERE id = ?
        ");
        $updateOrder->execute([$newOrderStatus, (int)$invoice['order_id']]);
    }

    $pdo->commit();

    jsonResponse([
        'success'          => true,
        'receipt_id'       => $receiptId,
        'receipt_number'   => $receiptNumber,
        'invoice_id'       => $invoiceId,
        'invoice_total'    => $invoiceTotal,
        'previous_paid'    => $alreadyPaid,
        'amount_paid'      => $amountPaid,
        'total_paid'       => $newPaidTotal,
        'balance'          => $balanceAfter,
        'invoice_status'   => $newInvStatus,
        'message'          => $newInvStatus === 'paid'
            ? 'Payment recorded successfully. Invoice marked as paid.'
            : 'Payment recorded successfully. Invoice marked as partial.'
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    jsonResponse([
        'success' => false,
        'error'   => $e->getMessage()
    ], 500);
}
