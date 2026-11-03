<?php
require_once '../config/db.php';
header('Content-Type: application/json');

$data   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $data['action'] ?? '';
$pdo    = getDB();

if ($action === 'cancel') {
    $pdo->prepare("UPDATE invoices SET status='cancelled' WHERE id=? AND status='unpaid'")->execute([(int)($data['id']??0)]);
    $pdo->prepare("UPDATE orders SET status='cancelled' WHERE id=(SELECT order_id FROM invoices WHERE id=?)")->execute([(int)($data['id']??0)]);
    jsonResponse(['success'=>true]);
}

jsonResponse(['success'=>false,'error'=>'Unknown action'],400);
