<?php
declare(strict_types=1);

class Client
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function allWithOrderCount(): array
    {
        $stmt = $this->pdo->query("
            SELECT c.*, COUNT(o.id) AS order_count
            FROM clients c
            LEFT JOIN orders o ON o.client_id = c.id
            GROUP BY c.id
            ORDER BY c.name ASC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM clients WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);

        $client = $stmt->fetch(PDO::FETCH_ASSOC);

        return $client ?: null;
    }

    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO clients 
                (name, email, phone, address, company, tin)
            VALUES 
                (:name, :email, :phone, :address, :company, :tin)
        ");

        $stmt->execute([
            ':name'    => $data['name'],
            ':email'   => $data['email'],
            ':phone'   => $data['phone'],
            ':address' => $data['address'],
            ':company' => $data['company'],
            ':tin'     => $data['tin'],
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE clients
            SET 
                name = :name,
                email = :email,
                phone = :phone,
                address = :address,
                company = :company,
                tin = :tin
            WHERE id = :id
        ");

        return $stmt->execute([
            ':name'    => $data['name'],
            ':email'   => $data['email'],
            ':phone'   => $data['phone'],
            ':address' => $data['address'],
            ':company' => $data['company'],
            ':tin'     => $data['tin'],
            ':id'      => $id,
        ]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM clients WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function emailExists(?string $email, int $ignoreId = 0): bool
    {
        if (!$email) {
            return false;
        }

        $stmt = $this->pdo->prepare("
            SELECT id 
            FROM clients 
            WHERE email = ? AND id != ?
            LIMIT 1
        ");

        $stmt->execute([$email, $ignoreId]);

        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function hasOrders(int $clientId): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) 
            FROM orders 
            WHERE client_id = ?
        ");

        $stmt->execute([$clientId]);

        return (int)$stmt->fetchColumn() > 0;
    }
}