<?php
require_once 'config/db.php';
require_once 'api/client.php';

$pageTitle = 'Clients';
$cfg = getSettings();
$pdo = getDB();

$clientModel = new Client($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $action = $data['action'] ?? '';

    try {
        if ($action === 'bulk_delete') {
            $ids = array_values(array_unique(array_filter(array_map('intval', $data['ids'] ?? []), static fn($id) => $id > 0)));
            if (!$ids) jsonResponse(['success' => false, 'error' => 'Select at least one client.'], 400);
            foreach ($ids as $id) if ($clientModel->hasOrders($id)) jsonResponse(['success' => false, 'error' => 'Selected clients with orders cannot be deleted. Remove those clients from the selection.'], 409);
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('DELETE FROM clients WHERE id=?');
                foreach ($ids as $id) $stmt->execute([$id]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            jsonResponse(['success' => true, 'message' => count($ids) . ' client(s) deleted.']);
        }

        if ($action === 'save') {
            $id = (int)($data['id'] ?? 0);

            $payload = [
                'name'    => trim($data['name'] ?? ''),
                'email'   => trim($data['email'] ?? '') ?: null,
                'phone'   => trim($data['phone'] ?? '') ?: null,
                'address' => trim($data['address'] ?? '') ?: null,
                'company' => trim($data['company'] ?? '') ?: null,
                'tin'     => trim($data['tin'] ?? '') ?: null,
            ];

            if ($payload['name'] === '') {
                jsonResponse(['success' => false, 'error' => 'Name is required'], 400);
            }

            if ($payload['email'] && $clientModel->emailExists($payload['email'], $id)) {
                jsonResponse(['success' => false, 'error' => 'Email already exists'], 400);
            }

            if ($id > 0) {
                $clientModel->update($id, $payload);
                jsonResponse(['success' => true, 'message' => 'Client updated']);
            }

            $newId = $clientModel->create($payload);
            jsonResponse([
                'success' => true,
                'message' => 'Client added',
                'id' => $newId
            ]);
        }

        if ($action === 'delete') {
            $id = (int)($data['id'] ?? 0);

            if ($id <= 0) {
                jsonResponse(['success' => false, 'error' => 'Invalid client ID'], 400);
            }

            if (!$clientModel->find($id)) {
                jsonResponse(['success' => false, 'error' => 'Client not found'], 404);
            }

            if ($clientModel->hasOrders($id)) {
                jsonResponse([
                    'success' => false,
                    'error' => 'Client has orders/invoices and cannot be deleted.'
                ], 400);
            }

            $clientModel->delete($id);

            jsonResponse([
                'success' => true,
                'message' => 'Client deleted'
            ]);
        }

        jsonResponse(['success' => false, 'error' => 'Invalid action'], 400);

    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            jsonResponse(['success' => false, 'error' => 'Duplicate or linked record detected'], 400);
        }

        jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

if (isset($_GET['get']) && is_numeric($_GET['get'])) {
    jsonResponse($clientModel->find((int)$_GET['get']) ?: []);
}

$clients = $clientModel->allWithOrderCount();

include 'includes/header.php';
?>

<div class="section-header">
    <span class="section-title">
        <i class="fas fa-users"></i> Clients
    </span>

    <button class="btn btn-primary" onclick="openClientForm()">
        <i class="fas fa-plus-circle"></i> Add Client
    </button>
</div>

<div class="card">
    <div class="filter-bar">
        <input class="search-input" id="client-search" placeholder="Search clients…" autocomplete="off">
    </div>
    <div class="bulk-actions" style="margin:0 0 14px"><button type="button" class="btn btn-danger btn-sm" id="delete-selected-clients"><i class="fas fa-trash"></i> Delete selected</button><span id="client-selection-count"></span></div>

    <div class="table-wrap">
        <table id="clients-table" data-client-pagination>
            <thead>
                <tr>
                    <th><input type="checkbox" id="select-all-clients" aria-label="Select all clients"></th><th>#</th><th>Name</th><th>Company</th><th>Email</th><th>Phone</th><th>Orders</th><th>Actions</th>
                </tr>
            </thead>

            <tbody>
            <?php foreach ($clients as $i => $c): ?>
                <tr>
                    <td><input type="checkbox" class="client-bulk-select" value="<?= (int)$c['id'] ?>" aria-label="Select <?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8') ?>"></td>
                    <td style="color:var(--text-dim);font-size:.8rem"><?= $i + 1 ?></td>
                    <td><strong><?= htmlspecialchars($c['name']) ?></strong></td>
                    <td><?= htmlspecialchars($c['company'] ?: '—') ?></td>
                    <td><?= htmlspecialchars($c['email'] ?: '—') ?></td>
                    <td><?= htmlspecialchars($c['phone'] ?: '—') ?></td>
                    <td>
                        <span class="badge badge-invoiced">
                            <?= (int)$c['order_count'] ?>
                        </span>
                    </td>
                    <td>
                        <div class="td-actions">
                            <button class="btn btn-ghost btn-sm" onclick="openClientForm(<?= (int)$c['id'] ?>)">
                                <i class="fas fa-edit"></i>
                            </button>

                            <button class="btn btn-danger btn-sm"
                                    onclick="deleteClient(<?= (int)$c['id'] ?>, '<?= htmlspecialchars(addslashes($c['name'])) ?>')">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if (empty($clients)): ?>
                <tr>
                    <td colspan="8">
                        <div class="empty-state">
                            <i class="fas fa-user-plus"></i>
                            <p>No clients yet. Add your first client.</p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.getElementById('client-search')?.addEventListener('input',e=>{const q=e.target.value.toLowerCase();document.querySelectorAll('#clients-table tbody tr').forEach(row=>row.hidden=!row.textContent.toLowerCase().includes(q));});
document.getElementById('select-all-clients')?.addEventListener('change', e => document.querySelectorAll('.client-bulk-select').forEach(box => box.checked = e.target.checked));
document.getElementById('delete-selected-clients')?.addEventListener('click', async () => {
    const ids = [...document.querySelectorAll('.client-bulk-select:checked')].map(box => Number(box.value));
    if (!ids.length) return alert('Select at least one client.');
    if (!confirm(`Delete ${ids.length} selected client(s)? Clients linked to orders cannot be deleted.`)) return;
    try { const res = await fetch('clients.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'bulk_delete',ids})}); const data=await res.json(); if(!res.ok||!data.success) throw new Error(data.error||'Delete failed'); location.reload(); } catch(err) { alert(err.message); }
});

async function openClientForm(id = null) {
    let data = { name:'', email:'', phone:'', address:'', company:'', tin:'' };

    if (id) {
        const res = await fetch(`clients.php?get=${id}`);
        data = await res.json();
    }

    openModal(`
        <div class="modal-title">
            <i class="fas ${id ? 'fa-edit' : 'fa-plus-circle'}"></i>
            ${id ? 'Edit' : 'Add'} Client
        </div>

        <input type="hidden" id="c-id" value="${id || ''}">

        <div class="form-grid" style="gap:14px">
            <div class="form-group">
                <label><i class="fas fa-user"></i> Full Name *</label>
                <input id="c-name" type="text" value="${escHtml(data.name || '')}" placeholder="John Mugisha">
            </div>

            <div class="form-group">
                <label><i class="fas fa-building"></i> Company</label>
                <input id="c-company" type="text" value="${escHtml(data.company || '')}" placeholder="Acme Ltd">
            </div>

            <div class="form-group">
                <label><i class="fas fa-envelope"></i> Email</label>
                <input id="c-email" type="email" value="${escHtml(data.email || '')}" placeholder="john@example.com">
            </div>

            <div class="form-group">
                <label><i class="fas fa-phone"></i> Phone</label>
                <input id="c-phone" type="text" value="${escHtml(data.phone || '')}" placeholder="+256 700 000 000">
            </div>

            <div class="form-group" style="grid-column:1/-1">
                <label><i class="fas fa-map-marker-alt"></i> Address</label>
                <textarea id="c-address" rows="2" placeholder="Physical address">${escHtml(data.address || '')}</textarea>
            </div>

            <div class="form-group">
                <label><i class="fas fa-file-invoice"></i> TIN</label>
                <input id="c-tin" type="text" value="${escHtml(data.tin || '')}" placeholder="URA-...">
            </div>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px">
            <button class="btn btn-ghost" onclick="closeModal()">
                <i class="fas fa-times"></i> Cancel
            </button>

            <button class="btn btn-primary" onclick="saveClient()">
                <i class="fas fa-save"></i> Save Client
            </button>
        </div>
    `);
}

async function saveClient() {
    const payload = {
        action: 'save',
        id: document.getElementById('c-id').value,
        name: document.getElementById('c-name').value.trim(),
        email: document.getElementById('c-email').value.trim(),
        phone: document.getElementById('c-phone').value.trim(),
        address: document.getElementById('c-address').value.trim(),
        company: document.getElementById('c-company').value.trim(),
        tin: document.getElementById('c-tin').value.trim()
    };

    if (!payload.name) {
        showToast('Name is required', 'error');
        return;
    }

    const res = await api('clients.php', {
        method: 'POST',
        body: JSON.stringify(payload)
    });

    if (res.success) {
        showToast(res.message);
        closeModal();
        setTimeout(() => location.reload(), 600);
    } else {
        showToast(res.error || 'Error saving client', 'error');
    }
}

async function deleteClient(id, name) {
    if (!confirm(`Delete client "${name}"? This cannot be undone.`)) return;

    const res = await api('clients.php', {
        method: 'POST',
        body: JSON.stringify({
            action: 'delete',
            id: id
        })
    });

    if (res.success) {
        showToast(res.message || 'Client deleted');
        setTimeout(() => location.reload(), 600);
    } else {
        showToast(res.error || 'Delete failed', 'error');
    }
}

<?php if (isset($_GET['new'])): ?>
window.addEventListener('load', () => openClientForm());
<?php endif; ?>
</script>

<?php include 'includes/footer.php'; ?>
