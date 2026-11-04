<?php
require_once 'config/db.php';
$pageTitle = 'Services & Products';
$cfg = getSettings();
$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $data['action'] ?? '';

    if ($action === 'bulk_delete') {
        $ids = array_values(array_unique(array_filter(array_map('intval', $data['ids'] ?? []), static fn($id) => $id > 0)));
        if (!$ids) jsonResponse(['success'=>false,'error'=>'Select at least one service.'],400);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        foreach (['order_items', 'quotation_items'] as $table) {
            try { $check = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE service_id IN ($marks)"); $check->execute($ids); if ((int)$check->fetchColumn() > 0) jsonResponse(['success'=>false,'error'=>'Some selected services are used by existing documents and cannot be deleted.'],409); }
            catch (PDOException $e) { if ($e->getCode() !== '42S02') throw $e; }
        }
        $pdo->beginTransaction();
        try { $pdo->prepare("DELETE FROM services WHERE id IN ($marks)")->execute($ids); $pdo->commit(); jsonResponse(['success'=>true,'message'=>count($ids).' service(s) deleted.']); }
        catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); jsonResponse(['success'=>false,'error'=>'Unable to delete selected services.'],409); }
    }

    if ($action === 'save') {
        $id   = (int)($data['id'] ?? 0);
        $name = trim($data['name'] ?? '');
        $price= (float)($data['unit_price'] ?? 0);
        if (!$name) jsonResponse(['success'=>false,'error'=>'Name required'],400);
        if ($id) {
            $pdo->prepare("UPDATE services SET name=?,description=?,unit_price=?,unit=?,tax_rate=?,is_active=? WHERE id=?")
                ->execute([$name,$data['description']??'',$price,$data['unit']??'item',(float)($data['tax_rate']??18),(int)($data['is_active']??1),$id]);
            jsonResponse(['success'=>true,'message'=>'Service updated']);
        } else {
            $pdo->prepare("INSERT INTO services (name,description,unit_price,unit,tax_rate,is_active) VALUES (?,?,?,?,?,?)")
                ->execute([$name,$data['description']??'',$price,$data['unit']??'item',(float)($data['tax_rate']??18),1]);
            jsonResponse(['success'=>true,'message'=>'Service added']);
        }
    }
    if ($action === 'delete') {
        $pdo->prepare("DELETE FROM services WHERE id=?")->execute([(int)($data['id']??0)]);
        jsonResponse(['success'=>true]);
    }
    if ($action === 'toggle') {
        $pdo->prepare("UPDATE services SET is_active = NOT is_active WHERE id=?")->execute([(int)($data['id']??0)]);
        jsonResponse(['success'=>true]);
    }
}

if (isset($_GET['get']) && is_numeric($_GET['get'])) {
    $s = $pdo->prepare("SELECT * FROM services WHERE id=?");
    $s->execute([$_GET['get']]);
    jsonResponse($s->fetch() ?: []);
}

$services = $pdo->query("SELECT * FROM services ORDER BY name")->fetchAll();
include 'includes/header.php';
?>

<div class="section-header">
    <span class="section-title">Services & Products</span>
    <button class="btn btn-primary" onclick="openSvcForm()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Add Service
    </button>
</div>

<div class="card">
    <div class="filter-bar">
        <input class="search-input" id="svc-search" placeholder="Search services…">
    </div>
    <div class="table-wrap">
        <table id="svc-table" data-client-pagination>
            <thead>
                <tr>
                    <th><input type="checkbox" id="select-all-services" aria-label="Select all services"></th>
                    <th>Service / Product</th>
                    <th>Description</th>
                    <th>Unit Price</th>
                    <th>Unit</th>
                    <th>VAT %</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($services as $s): ?>
            <tr>
                <td><input type="checkbox" class="service-bulk-select" value="<?= (int)$s['id'] ?>" aria-label="Select <?= htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8') ?>"></td>
                <td><strong><?= htmlspecialchars($s['name']) ?></strong></td>
                <td style="color:var(--text-muted);font-size:.84rem;max-width:220px"><?= htmlspecialchars($s['description'] ?: '—') ?></td>
                <td><?= formatMoney($s['unit_price'], $cfg['currency_symbol']) ?></td>
                <td style="font-size:.82rem;color:var(--text-muted)"><?= htmlspecialchars($s['unit']) ?></td>
                <td><?= $s['tax_rate'] ?>%</td>
                <td>
                    <button class="badge <?= $s['is_active'] ? 'badge-paid' : 'badge-draft' ?>"
                            style="cursor:pointer;background:none;border:none"
                            onclick="toggleService(<?= $s['id'] ?>)">
                        <?= $s['is_active'] ? 'Active' : 'Inactive' ?>
                    </button>
                </td>
                <td>
                    <div class="td-actions">
                        <button class="btn btn-ghost btn-sm" title="Edit service" aria-label="Edit service" onclick="openSvcForm(<?= $s['id'] ?>)"><i class="fas fa-pen"></i></button>
                        <button class="btn btn-danger btn-sm" title="Delete service" aria-label="Delete service" onclick="deleteSvc(<?= $s['id'] ?>)"><i class="fas fa-trash"></i></button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($services)): ?>
            <tr><td colspan="8">
                <div class="empty-state">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                    <p>No services yet. Add your first service.</p>
                </div>
            </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="bulk-actions" style="margin:0 0 14px"><button class="btn btn-danger btn-sm" type="button" id="delete-selected-services"><i class="fas fa-trash"></i> Delete selected</button></div>

<script>
document.getElementById('svc-search')?.addEventListener('input',e=>{const q=e.target.value.toLowerCase();document.querySelectorAll('#svc-table tbody tr').forEach(row=>row.hidden=!row.textContent.toLowerCase().includes(q));});
document.getElementById('select-all-services')?.addEventListener('change',e=>document.querySelectorAll('.service-bulk-select').forEach(box=>box.checked=e.target.checked));
document.getElementById('delete-selected-services')?.addEventListener('click',async()=>{const ids=[...document.querySelectorAll('.service-bulk-select:checked')].map(box=>Number(box.value));if(!ids.length)return alert('Select at least one service.');if(!confirm(`Delete ${ids.length} selected service(s)? Services used in existing documents cannot be deleted.`))return;try{const res=await fetch('services.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'bulk_delete',ids})});const data=await res.json();if(!res.ok||!data.success)throw new Error(data.error||'Delete failed');location.reload();}catch(err){alert(err.message);}});

async function openSvcForm(id = null) {
    let data = { name:'', description:'', unit_price:0, unit:'item', tax_rate:18 };
    if (id) {
        const r = await fetch(`services.php?get=${id}`);
        data = await r.json();
    }
    openModal(`
        <div class="modal-title">${id ? 'Edit' : 'Add'} Service</div>
        <input type="hidden" id="s-id" value="${id||''}">
        <div class="form-grid" style="gap:14px">
            <div class="form-group" style="grid-column:1/-1">
                <label>Service Name *</label>
                <input id="s-name" type="text" value="${escHtml(data.name)}" placeholder="Web Development">
            </div>
            <div class="form-group" style="grid-column:1/-1">
                <label>Description</label>
                <textarea id="s-desc" rows="2" placeholder="Brief description of the service">${escHtml(data.description||'')}</textarea>
            </div>
            <div class="form-group">
                <label>Unit Price (UGX)</label>
                <input id="s-price" type="number" value="${data.unit_price}" min="0" step="any">
            </div>
            <div class="form-group">
                <label>Unit</label>
                <select id="s-unit">
                    ${['item','hour','day','month','year','project','page','seat'].map(u=>`<option value="${u}" ${data.unit===u?'selected':''}>${u}</option>`).join('')}
                </select>
            </div>
            <div class="form-group">
                <label>VAT / Tax Rate (%)</label>
                <input id="s-tax" type="number" value="${data.tax_rate}" min="0" max="100" step="0.01">
            </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px">
            <button class="btn btn-ghost" onclick="closeModal()">Cancel</button>
            <button class="btn btn-primary" onclick="saveSvc()">Save Service</button>
        </div>
    `);
}

async function saveSvc() {
    const payload = {
        action:       'save',
        id:           document.getElementById('s-id').value,
        name:         document.getElementById('s-name').value.trim(),
        description:  document.getElementById('s-desc').value.trim(),
        unit_price:   document.getElementById('s-price').value,
        unit:         document.getElementById('s-unit').value,
        tax_rate:     document.getElementById('s-tax').value,
    };
    if (!payload.name) { showToast('Name is required','error'); return; }
    const res = await api('services.php', { method:'POST', body: JSON.stringify(payload) });
    if (res.success) { showToast(res.message); closeModal(); setTimeout(() => location.reload(), 600); }
    else showToast(res.error || 'Error', 'error');
}

async function deleteSvc(id) {
    if (!confirm('Delete this service?')) return;
    const res = await api('services.php', { method:'POST', body: JSON.stringify({ action:'delete', id }) });
    if (res.success) { showToast('Deleted'); setTimeout(() => location.reload(), 600); }
    else showToast('Delete failed','error');
}

async function toggleService(id) {
    const res = await api('services.php', { method:'POST', body: JSON.stringify({ action:'toggle', id }) });
    if (res.success) setTimeout(() => location.reload(), 300);
}
</script>

<?php include 'includes/footer.php'; ?>
