<?php
declare(strict_types=1);

class Order
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function all(): array
    {
        $stmt = $this->pdo->query("
            SELECT 
                o.*, 
                c.name AS client_name,
                COUNT(DISTINCT oi.id) AS item_count,
                COALESCE(i.id, 0) AS invoice_id,
                COALESCE(i.invoice_number, '') AS invoice_number
            FROM orders o
            JOIN clients c ON c.id = o.client_id
            LEFT JOIN order_items oi ON oi.order_id = o.id
            LEFT JOIN invoices i ON i.order_id = o.id
            GROUP BY o.id
            ORDER BY o.created_at DESC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function findWithItems(int $id): ?array
    {
        $order = $this->find($id);

        if (!$order) {
            return null;
        }

        $stmt = $this->pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
        $stmt->execute([$id]);

        $order['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $order;
    }

    public function create(array $data): int
    {
        $this->pdo->beginTransaction();

        try {
            $orderNum = nextNumber('orders', 'order_number', $data['order_prefix']);

            $stmt = $this->pdo->prepare("
                INSERT INTO orders 
                    (order_number, client_id, order_date, due_date, notes, discount_type, discount_value, status)
                VALUES 
                    (?, ?, ?, ?, ?, ?, ?, 'pending')
            ");

            $stmt->execute([
                $orderNum,
                $data['client_id'],
                $data['order_date'],
                $data['due_date'],
                $data['notes'],
                $data['discount_type'],
                $data['discount_value']
            ]);

            $orderId = (int)$this->pdo->lastInsertId();

            $this->saveItems($orderId, $data['items']);

            $this->pdo->commit();

            return $orderId;

        } catch (Throwable $e) {
            $this->rollBackIfActive();
            throw $e;
        }
    }

    public function update(int $id, array $data): int
    {
        $order = $this->find($id);

        if (!$order) {
            throw new Exception('Order not found');
        }

        if (!in_array($order['status'], ['pending', 'draft'], true)) {
            throw new Exception('Only pending or draft orders can be edited');
        }

        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare("
                UPDATE orders 
                SET 
                    client_id = ?,
                    order_date = ?,
                    due_date = ?,
                    notes = ?,
                    discount_type = ?,
                    discount_value = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");

            $stmt->execute([
                $data['client_id'],
                $data['order_date'],
                $data['due_date'],
                $data['notes'],
                $data['discount_type'],
                $data['discount_value'],
                $id
            ]);

            $this->pdo->prepare("DELETE FROM order_items WHERE order_id = ?")->execute([$id]);

            $this->saveItems($id, $data['items']);

            $this->pdo->commit();

            return $id;

        } catch (Throwable $e) {
            $this->rollBackIfActive();
            throw $e;
        }
    }

    private function saveItems(int $orderId, array $items): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO order_items 
                (order_id, service_id, description, quantity, unit_price, tax_rate)
            VALUES 
                (?, ?, ?, ?, ?, ?)
        ");

        foreach ($items as $item) {
            $description = trim((string)($item['description'] ?? ''));

            if ($description === '') {
                continue;
            }

            $stmt->execute([
                $orderId,
                !empty($item['service_id']) ? (int)$item['service_id'] : null,
                $description,
                (float)($item['quantity'] ?? 0),
                (float)($item['unit_price'] ?? 0),
                (float)($item['tax_rate'] ?? 0)
            ]);
        }
    }

    public function cancel(int $id): bool
    {
        $order = $this->find($id);

        if (!$order) {
            throw new Exception('Order not found');
        }

        if (!in_array($order['status'], ['pending', 'draft', 'invoiced'], true)) {
            throw new Exception('Only pending, draft, or invoiced orders can be cancelled');
        }

        $stmt = $this->pdo->prepare("
            UPDATE orders 
            SET status = 'cancelled', updated_at = NOW()
            WHERE id = ?
        ");

        return $stmt->execute([$id]);
    }

    public function deleteCancelled(int $id): bool
    {
        $order = $this->find($id);

        if (!$order) {
            throw new Exception('Order not found');
        }

        if ($order['status'] !== 'cancelled') {
            throw new Exception('Only cancelled orders can be deleted');
        }

        $this->pdo->beginTransaction();

        try {
            $invoiceIds = $this->getInvoiceIdsByOrder($id);

            foreach ($invoiceIds as $invoiceId) {
                $this->deleteInvoiceDependencies((int)$invoiceId);
            }

            $this->deleteByColumnIfTableExists('invoices', 'order_id', $id);
            $this->deleteByColumnIfTableExists('order_items', 'order_id', $id);

            $stmt = $this->pdo->prepare("DELETE FROM orders WHERE id = ? AND status = 'cancelled'");
            $stmt->execute([$id]);

            if ($stmt->rowCount() < 1) {
                throw new Exception('Order could not be deleted');
            }

            $this->pdo->commit();

            return true;

        } catch (Throwable $e) {
            $this->rollBackIfActive();
            throw $e;
        }
    }

    public function generateInvoice(int $orderId, array $cfg): int
    {
        $order = $this->find($orderId);

        if (!$order) {
            throw new Exception('Order not found');
        }

        if ($order['status'] === 'cancelled') {
            throw new Exception('Cancelled orders cannot be invoiced');
        }

        $check = $this->pdo->prepare("SELECT id FROM invoices WHERE order_id = ? LIMIT 1");
        $check->execute([$orderId]);

        if ($check->fetch()) {
            throw new Exception('Invoice already exists for this order');
        }

        $stmt = $this->pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
        $stmt->execute([$orderId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$items) {
            throw new Exception('Order has no items');
        }

        $subtotal = 0;
        $taxTotal = 0;

        foreach ($items as $it) {
            $line = (float)$it['quantity'] * (float)$it['unit_price'];
            $subtotal += $line;
            $taxTotal += $line * ((float)$it['tax_rate'] / 100);
        }

        $discount = $order['discount_type'] === 'percent'
            ? ($subtotal * ((float)$order['discount_value'] / 100))
            : (float)$order['discount_value'];

        $total = $subtotal + $taxTotal - $discount;

        $invNum = nextNumber('invoices', 'invoice_number', $cfg['invoice_prefix'] ?? 'INV');
        $terms = (int)($cfg['payment_terms'] ?? 0);

        $dueDate = $order['due_date'] ?: date('Y-m-d', strtotime('+' . $terms . ' days'));

        ensureUserSignatureProfile($this->pdo);
        $signatory = ['authorized_name' => null, 'profile_signature_data' => null];
        if (!empty($_SESSION['user_id'])) {
            $profileStmt = $this->pdo->prepare('SELECT authorized_name, profile_signature_data FROM users WHERE id=? LIMIT 1');
            $profileStmt->execute([(int)$_SESSION['user_id']]);
            $signatory = $profileStmt->fetch(PDO::FETCH_ASSOC) ?: $signatory;
        }

        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO invoices 
                    (invoice_number, order_id, issued_date, due_date, subtotal, tax_total, discount_amount, total, status, authorized_name, signature_type, signature_data, signed_at)
                VALUES 
                    (?, ?, CURDATE(), ?, ?, ?, ?, ?, 'unpaid', ?, 'draw', ?, CASE WHEN ? IS NULL OR ? = '' THEN NULL ELSE NOW() END)
            ");

            $stmt->execute([
                $invNum,
                $orderId,
                $dueDate,
                $subtotal,
                $taxTotal,
                $discount,
                $total,
                $signatory['authorized_name'] ?? null,
                $signatory['profile_signature_data'] ?? null,
                $signatory['profile_signature_data'] ?? null,
                $signatory['profile_signature_data'] ?? null
            ]);

            $invoiceId = (int)$this->pdo->lastInsertId();

            $this->pdo->prepare("UPDATE orders SET status = 'invoiced', updated_at = NOW() WHERE id = ?")
                ->execute([$orderId]);

            $this->pdo->commit();

            return $invoiceId;

        } catch (Throwable $e) {
            $this->rollBackIfActive();
            throw $e;
        }
    }

    private function getInvoiceIdsByOrder(int $orderId): array
    {
        if (!$this->tableExists('invoices')) {
            return [];
        }

        $stmt = $this->pdo->prepare("SELECT id FROM invoices WHERE order_id = ?");
        $stmt->execute([$orderId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    private function deleteInvoiceDependencies(int $invoiceId): void
    {
        $dependencyTables = [
            'receipt_items'     => 'invoice_id',
            'receipts'          => 'invoice_id',
            'payments'          => 'invoice_id',
            'invoice_payments'  => 'invoice_id',
            'invoice_items'     => 'invoice_id',
            'payment_receipts'  => 'invoice_id',
        ];

        foreach ($dependencyTables as $table => $column) {
            $this->deleteByColumnIfTableExists($table, $column, $invoiceId);
        }
    }

    private function deleteByColumnIfTableExists(string $table, string $column, int $value): void
    {
        if (!$this->tableExists($table)) {
            return;
        }

        if (!$this->columnExists($table, $column)) {
            return;
        }

        $sql = "DELETE FROM `$table` WHERE `$column` = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$value]);
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);

        return (bool)$stmt->fetchColumn();
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);

        return (bool)$stmt->fetchColumn();
    }

    private function rollBackIfActive(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
