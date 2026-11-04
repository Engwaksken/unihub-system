<?php
require_once 'config/db.php';
require_once 'api/order.php';

$pageTitle = 'Orders';
$cfg = getSettings();
$pdo = getDB();

$orderModel = new Order($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $data['action'] ?? '';

    try {
        if ($action === 'save') {
            $id        = (int)($data['id'] ?? 0);
            $clientId  = (int)($data['client_id'] ?? 0);
            $items     = $data['items'] ?? [];
            $orderDate = $data['order_date'] ?? date('Y-m-d');
            $dueDate   = !empty($data['due_date']) ? $data['due_date'] : null;

            if (!$clientId) jsonResponse(['success' => false, 'error' => 'Client is required'], 400);
            if (empty($items)) jsonResponse(['success' => false, 'error' => 'Add at least one item'], 400);

            $payload = [
                'client_id'      => $clientId,
                'order_date'     => $orderDate,
                'due_date'       => $dueDate,
                'notes'          => $data['notes'] ?? '',
                'discount_type'  => $data['discount_type'] ?? 'fixed',
                'discount_value' => (float)($data['discount_value'] ?? 0),
                'items'          => $items,
                'order_prefix'   => $cfg['order_prefix'] ?? 'ORD'
            ];

            $orderId = $id > 0
                ? $orderModel->update($id, $payload)
                : $orderModel->create($payload);

            jsonResponse([
                'success' => true,
                'message' => 'Order saved',
                'id' => $orderId
            ]);
        }

        if ($action === 'generate_invoice') {
            $orderId = (int)($data['order_id'] ?? 0);
            $invoiceId = $orderModel->generateInvoice($orderId, $cfg);

            jsonResponse([
                'success' => true,
                'invoice_id' => $invoiceId,
                'message' => 'Invoice generated'
            ]);
        }

        if ($action === 'cancel') {
            $id = (int)($data['id'] ?? 0);

            if ($id <= 0) {
                jsonResponse(['success' => false, 'error' => 'Invalid order ID'], 400);
            }

            $orderModel->cancel($id);

            jsonResponse([
                'success' => true,
                'message' => 'Order cancelled'
            ]);
        }

        if ($action === 'delete') {
            $id = (int)($data['id'] ?? 0);

            if ($id <= 0) {
                jsonResponse(['success' => false, 'error' => 'Invalid order ID'], 400);
            }

            $orderModel->deleteCancelled($id);

            jsonResponse([
                'success' => true,
                'message' => 'Cancelled order deleted successfully'
            ]);
        }

        jsonResponse(['success' => false, 'error' => 'Invalid action'], 400);

    } catch (Throwable $e) {
        jsonResponse([
            'success' => false,
            'error' => $e->getMessage()
        ], 400);
    }
}

if (isset($_GET['get']) && is_numeric($_GET['get'])) {
    jsonResponse($orderModel->findWithItems((int)$_GET['get']) ?: []);
}

$orders   = $orderModel->all();
$clients  = $pdo->query("SELECT id,name,company FROM clients ORDER BY name")->fetchAll();
$services = $pdo->query("SELECT * FROM services WHERE is_active=1 ORDER BY name")->fetchAll();

include 'includes/header.php';
?>

<div class="section-header">
    <span class="section-title">
        <i class="fas fa-clipboard-list"></i> Orders
    </span>

    <button class="btn btn-primary" id="btn-new-order" onclick="openOrderForm()">
        <i class="fas fa-plus-circle"></i> New Order
    </button>
</div>

<div id="order-form-wrap" style="display:none;margin-bottom:24px">
    <div class="card">
        <div class="card-header">
            <span class="card-title" id="order-form-title">
                <i class="fas fa-file-alt"></i> New Order
            </span>

            <button class="btn btn-ghost btn-sm" onclick="cancelOrderForm()">
                <i class="fas fa-times"></i> Cancel
            </button>
        </div>

        <input type="hidden" id="of-id">

        <div class="form-grid" style="margin-bottom:18px">
            <div class="form-group">
                <label><i class="fas fa-user"></i> Client *</label>
                <select id="of-client">
                    <option value="">— Select Client —</option>
                    <?php foreach ($clients as $cl): ?>
                        <option value="<?= (int)$cl['id'] ?>">
                            <?= htmlspecialchars($cl['name']) ?><?= !empty($cl['company']) ? ' (' . htmlspecialchars($cl['company']) . ')' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label><i class="fas fa-calendar"></i> Order Date</label>
                <input type="date" id="of-date" value="<?= date('Y-m-d') ?>">
            </div>

            <div class="form-group">
                <label><i class="fas fa-calendar-check"></i> Due Date</label>
                <input type="date" id="of-due">
            </div>
        </div>

        <div style="margin-bottom:14px">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
                <strong style="font-size:.88rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">
                    <i class="fas fa-list"></i> Line Items
                </strong>

                <button class="btn btn-outline btn-sm" type="button" onclick="addItemRow(window.__services)">
                    <i class="fas fa-plus"></i> Add Item
                </button>
            </div>

            <div class="table-wrap">
                <table class="items-table">
                    <thead>
                    <tr>
                        <th>Service</th>
                        <th>Description</th>
                        <th>Qty</th>
                        <th>Unit Price</th>
                        <th>VAT %</th>
                        <th style="text-align:right">Line Total</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody id="items-body"></tbody>
                </table>
            </div>
        </div>

        <div style="display:flex;gap:20px;align-items:flex-start;flex-wrap:wrap;justify-content:space-between;margin-bottom:18px">
            <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
                <div class="form-group">
                    <label><i class="fas fa-tags"></i> Discount Type</label>
                    <select id="disc-type" onchange="calcTotals()" style="width:120px">
                        <option value="fixed">Fixed (UGX)</option>
                        <option value="percent">Percent (%)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-percent"></i> Discount Value</label>
                    <input type="number" id="disc-value" value="0" min="0" step="any" oninput="calcTotals()" style="width:120px">
                </div>
            </div>

            <div class="summary-box">
                <div class="summary-row"><span>Subtotal</span><span id="sum-subtotal">UGX 0</span></div>
                <div class="summary-row"><span>VAT / Tax</span><span id="sum-tax">UGX 0</span></div>
                <div class="summary-row"><span>Discount</span><span id="sum-discount">UGX 0</span></div>
                <div class="summary-row total"><span>Total</span><span id="sum-total">UGX 0</span></div>
            </div>
        </div>

        <div class="form-group" style="margin-bottom:18px">
            <label><i class="fas fa-note-sticky"></i> Notes</label>
            <textarea id="of-notes" rows="2" placeholder="Additional notes or payment instructions…"></textarea>
        </div>

        <div style="display:flex;gap:10px;justify-content:flex-end">
            <button class="btn btn-ghost" onclick="cancelOrderForm()">
                <i class="fas fa-times"></i> Cancel
            </button>

            <button class="btn btn-primary" onclick="saveOrder()">
                <i class="fas fa-save"></i> Save Order
            </button>
        </div>
    </div>
</div>

<div class="card" id="orders-list">
    <div class="filter-bar">
        <input class="search-input" id="ord-search" placeholder="Search orders…">
    </div>

    <div class="table-wrap">
        <table id="orders-table" data-client-pagination data-date-column="2">
            <thead>
            <tr>
                <th>Order #</th><th>Client</th><th>Date</th><th>Due</th><th>Items</th><th>Status</th><th>Invoice</th><th>Actions</th>
            </tr>
            </thead>

            <tbody>
            <?php foreach ($orders as $o): ?>
                <tr>
                    <td>
                        <strong style="color:var(--gold)">
                            <?= htmlspecialchars($o['order_number']) ?>
                        </strong>
                    </td>

                    <td><?= htmlspecialchars($o['client_name']) ?></td>
                    <td><?= !empty($o['order_date']) ? date('d/m/Y', strtotime($o['order_date'])) : '—' ?></td>
                    <td><?= !empty($o['due_date']) ? date('d/m/Y', strtotime($o['due_date'])) : '—' ?></td>
                    <td><?= (int)$o['item_count'] ?></td>

                    <td>
                        <span class="badge badge-<?= htmlspecialchars($o['status']) ?>">
                            <?= ucfirst(htmlspecialchars($o['status'])) ?>
                        </span>
                    </td>

                    <td>
                        <?php if (!empty($o['invoice_id'])): ?>
                            <a href="invoice_view.php?id=<?= (int)$o['invoice_id'] ?>" class="btn btn-outline btn-sm">
                                <i class="fas fa-file-invoice"></i>
                                <?= htmlspecialchars($o['invoice_number']) ?>
                            </a>
                        <?php elseif (in_array($o['status'], ['pending', 'draft'], true)): ?>
                            <button class="btn btn-primary btn-sm" onclick="generateInvoice(<?= (int)$o['id'] ?>)">
                                <i class="fas fa-file-circle-plus"></i> Generate
                            </button>
                        <?php else: ?>
                            <span style="color:var(--text-dim);font-size:.82rem">
                                <?= ucfirst(htmlspecialchars($o['status'])) ?>
                            </span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <div class="td-actions">
                            <?php if (in_array($o['status'], ['pending', 'draft'], true)): ?>
                                <button class="btn btn-ghost btn-sm" onclick="editOrder(<?= (int)$o['id'] ?>)" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </button>

                                <button class="btn btn-warning btn-sm" onclick="cancelOrder(<?= (int)$o['id'] ?>)" title="Cancel">
                                    <i class="fas fa-ban"></i>
                                </button>
                            <?php endif; ?>

                            <?php if ($o['status'] === 'cancelled'): ?>
                                <button class="btn btn-danger btn-sm" onclick="deleteOrder(<?= (int)$o['id'] ?>)" title="Delete cancelled order">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if (empty($orders)): ?>
                <tr>
                    <td colspan="8">
                        <div class="empty-state">
                            <i class="fas fa-clipboard-list"></i>
                            <p>No orders yet. Create your first order.</p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
window.__services = <?= json_encode($services) ?>;
filterTable('ord-search', 'orders-table');

function openOrderForm() {
    document.getElementById('order-form-wrap').style.display = 'block';
    document.getElementById('orders-list').style.display = 'none';
    document.getElementById('btn-new-order').style.display = 'none';

    document.getElementById('of-id').value = '';
    document.getElementById('of-client').value = '';
    document.getElementById('of-date').value = new Date().toISOString().split('T')[0];
    document.getElementById('of-due').value = '';
    document.getElementById('of-notes').value = '';
    document.getElementById('disc-type').value = 'fixed';
    document.getElementById('disc-value').value = 0;
    document.getElementById('items-body').innerHTML = '';

    itemIndex = 0;
    addItemRow(window.__services);
    calcTotals();

    document.getElementById('order-form-title').innerHTML = '<i class="fas fa-file-alt"></i> New Order';
}

function cancelOrderForm() {
    document.getElementById('order-form-wrap').style.display = 'none';
    document.getElementById('orders-list').style.display = 'block';
    document.getElementById('btn-new-order').style.display = 'inline-flex';
}

async function editOrder(id) {
    const data = await (await fetch(`orders.php?get=${id}`)).json();

    openOrderForm();

    document.getElementById('order-form-title').innerHTML = '<i class="fas fa-edit"></i> Edit Order';
    document.getElementById('of-id').value = data.id;
    document.getElementById('of-client').value = data.client_id;
    document.getElementById('of-date').value = data.order_date;
    document.getElementById('of-due').value = data.due_date || '';
    document.getElementById('of-notes').value = data.notes || '';
    document.getElementById('disc-type').value = data.discount_type || 'fixed';
    document.getElementById('disc-value').value = data.discount_value || 0;

    document.getElementById('items-body').innerHTML = '';
    itemIndex = 0;

    (data.items || []).forEach(it => addItemRow(window.__services, it));
    calcTotals();
}

async function saveOrder() {
    const items = collectItems();

    if (!document.getElementById('of-client').value) {
        showToast('Please select a client', 'error');
        return;
    }

    if (!items.length) {
        showToast('Add at least one line item', 'error');
        return;
    }

    const payload = {
        action: 'save',
        id: document.getElementById('of-id').value,
        client_id: document.getElementById('of-client').value,
        order_date: document.getElementById('of-date').value,
        due_date: document.getElementById('of-due').value,
        notes: document.getElementById('of-notes').value,
        discount_type: document.getElementById('disc-type').value,
        discount_value: document.getElementById('disc-value').value,
        items
    };

    const res = await api('orders.php', {
        method: 'POST',
        body: JSON.stringify(payload)
    });

    if (res.success) {
        showToast(res.message);
        setTimeout(() => location.reload(), 700);
    } else {
        showToast(res.error || 'Error saving order', 'error');
    }
}

async function generateInvoice(orderId) {
    if (!confirm('Generate invoice for this order?')) return;

    const res = await api('orders.php', {
        method: 'POST',
        body: JSON.stringify({
            action: 'generate_invoice',
            order_id: orderId
        })
    });

    if (res.success) {
        showToast('Invoice generated!');
        setTimeout(() => location.href = `invoice_view.php?id=${res.invoice_id}`, 800);
    } else {
        showToast(res.error || 'Error generating invoice', 'error');
    }
}

async function cancelOrder(id) {
    if (!confirm('Cancel this order?')) return;

    const res = await api('orders.php', {
        method: 'POST',
        body: JSON.stringify({
            action: 'cancel',
            id
        })
    });

    if (res.success) {
        showToast(res.message || 'Order cancelled');
        setTimeout(() => location.reload(), 600);
    } else {
        showToast(res.error || 'Cancel failed', 'error');
    }
}

async function deleteOrder(id) {
    if (!confirm('Delete this cancelled order permanently? Linked invoice records will also be removed.')) return;

    const res = await api('orders.php', {
        method: 'POST',
        body: JSON.stringify({
            action: 'delete',
            id
        })
    });

    if (res.success) {
        showToast(res.message || 'Order deleted');
        setTimeout(() => location.reload(), 600);
    } else {
        showToast(res.error || 'Delete failed', 'error');
    }
}

<?php if (isset($_GET['new'])): ?>
window.addEventListener('load', () => openOrderForm());
<?php endif; ?>
</script>

<?php include 'includes/footer.php'; ?>
