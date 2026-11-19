<?php
declare(strict_types=1);

/**
 * Build the data for a proforma invoice from an order.
 * Shared by proforma_view.php and send_proforma.php so the page and the emailed PDF match.
 * Totals use the same formula as Order::generateInvoice().
 */
function loadProforma(PDO $pdo, array $cfg, int $orderId): ?array
{
    $stmt = $pdo->prepare("
        SELECT o.*,
               c.name AS client_name, c.email AS client_email, c.phone AS client_phone,
               c.address AS client_address, c.company AS client_company, c.tin AS client_tin,
               (SELECT i.id FROM invoices i WHERE i.order_id = o.id ORDER BY i.id DESC LIMIT 1) AS invoice_id
        FROM orders o
        JOIN clients c ON c.id = o.client_id
        WHERE o.id = ? LIMIT 1
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        return null;
    }

    $itemStmt = $pdo->prepare("
        SELECT oi.*, s.name AS service_name
        FROM order_items oi
        LEFT JOIN services s ON s.id = oi.service_id
        WHERE oi.order_id = ? ORDER BY oi.id ASC
    ");
    $itemStmt->execute([$orderId]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    $subtotal = 0.0;
    $taxTotal = 0.0;
    foreach ($items as $it) {
        $line = (float)$it['quantity'] * (float)$it['unit_price'];
        $subtotal += $line;
        $taxTotal += $line * ((float)$it['tax_rate'] / 100);
    }
    $discount = ($order['discount_type'] ?? 'fixed') === 'percent'
        ? $subtotal * ((float)$order['discount_value'] / 100)
        : (float)$order['discount_value'];

    $prefix     = trim((string)($cfg['proforma_prefix'] ?? '')) ?: 'PF';
    $date       = $order['order_date'] ?: date('Y-m-d');
    $terms      = (int)($cfg['payment_terms'] ?? 0);
    $validUntil = $order['due_date'] ?: date('Y-m-d', strtotime($date . ' +' . max($terms, 30) . ' days'));

    return [
        'order'       => $order,
        'items'       => $items,
        'subtotal'    => $subtotal,
        'tax_total'   => $taxTotal,
        'discount'    => $discount,
        'total'       => $subtotal + $taxTotal - $discount,
        'number'      => $prefix . '-' . $order['order_number'],
        'date'        => $date,
        'valid_until' => $validUntil,
    ];
}

/** The logged-in user's saved signatory name and drawn signature, if any. */
function loadCurrentSignatory(PDO $pdo): array
{
    ensureUserSignatureProfile($pdo);
    $signatory = ['authorized_name' => '', 'profile_signature_data' => ''];
    if (!empty($_SESSION['user_id'])) {
        $stmt = $pdo->prepare('SELECT authorized_name, profile_signature_data FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$_SESSION['user_id']]);
        $signatory = $stmt->fetch(PDO::FETCH_ASSOC) ?: $signatory;
    }
    return $signatory;
}
