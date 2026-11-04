
// ---- SIDEBAR TOGGLE ----------------------------------------
function toggleSidebar(forceOpen) {
    const sidebar = document.querySelector('.sidebar');
    const toggle = document.querySelector('.menu-toggle');
    if (!sidebar) return;
    const open = typeof forceOpen === 'boolean' ? forceOpen : !sidebar.classList.contains('open');
    sidebar.classList.toggle('open', open);
    document.body.classList.toggle('sidebar-open', open);
    if (toggle) toggle.setAttribute('aria-expanded', String(open));
}

document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && document.body.classList.contains('sidebar-open')) toggleSidebar(false);
});
document.addEventListener('click', event => {
    if (event.target.closest('.sidebar .nav-item a') && window.matchMedia('(max-width: 768px)').matches) toggleSidebar(false);
});

// ---- MODAL -------------------------------------------------
let pendingConfirmResolver = null;
function openModal(html) {
    document.getElementById('modal-body').innerHTML = html;
    document.getElementById('modal').classList.add('active');
    document.getElementById('modal-overlay').classList.add('active');
}
function closeModal() {
    document.getElementById('modal').classList.remove('active');
    document.getElementById('modal-overlay').classList.remove('active');
    document.getElementById('modal-body').innerHTML = '';
    if (pendingConfirmResolver) {
        const resolve = pendingConfirmResolver;
        pendingConfirmResolver = null;
        resolve(false);
    }
}

function resolveModalConfirmation(confirmed) {
    const resolve = pendingConfirmResolver;
    pendingConfirmResolver = null;
    closeModal();
    if (resolve) resolve(confirmed);
}

function confirmModal(message, options = {}) {
    if (pendingConfirmResolver) resolveModalConfirmation(false);
    return new Promise(resolve => {
        pendingConfirmResolver = resolve;
        const escape = value => String(value).replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
        const title = escape(options.title || 'Please confirm');
        const confirmText = escape(options.confirmText || 'Confirm');
        const cancelText = escape(options.cancelText || 'Cancel');
        const dangerClass = options.danger === false ? 'btn-primary' : 'btn-danger';
        openModal(`<section class="confirm-dialog" role="alertdialog" aria-modal="true" aria-labelledby="confirm-dialog-title"><h3 id="confirm-dialog-title">${title}</h3><p>${escape(message)}</p><div class="confirm-dialog-actions"><button type="button" class="btn btn-ghost" onclick="resolveModalConfirmation(false)">${cancelText}</button><button type="button" class="btn ${dangerClass}" onclick="resolveModalConfirmation(true)">${confirmText}</button></div></section>`);
        const style = document.createElement('style');
        style.textContent = '.confirm-dialog{padding:8px}.confirm-dialog h3{margin:0 0 10px;color:var(--text);font-size:1.15rem}.confirm-dialog p{margin:0;color:var(--text-muted);line-height:1.6}.confirm-dialog-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:22px}';
        document.getElementById('modal-body').appendChild(style);
        document.querySelector('.confirm-dialog-actions button:last-child')?.focus();
    });
}

// ---- TOAST -------------------------------------------------
function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast show' + (type === 'error' ? ' error' : '');
    clearTimeout(t._timer);
    t._timer = setTimeout(() => t.classList.remove('show'), 3500);
}

// ---- FETCH HELPER ------------------------------------------
async function api(url, opts = {}) {
    const res = await fetch(url, {
        headers: { 'Content-Type': 'application/json' },
        ...opts,
    });
    return res.json();
}

// ---- SEARCH / FILTER TABLE ---------------------------------
function filterTable(inputId, tableId) {
    const input = document.getElementById(inputId);
    if (!input) return;
    input.addEventListener('input', () => {
        const q = input.value.toLowerCase();
        document.querySelectorAll(`#${tableId} tbody tr`).forEach(row => {
            row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
        });
    });
}

// Lightweight paging and inclusive date-period filters for long list tables.
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('table[data-client-pagination]').forEach(table => {
        const rows = [...table.querySelectorAll('tbody tr')].filter(row => row.cells.length > 1);
        if (!rows.length) return;
        const controls = document.createElement('div');
        controls.className = 'users-pagination table-pagination-controls';
        controls.style.cssText = 'display:flex;gap:8px;align-items:center;justify-content:flex-end;flex-wrap:wrap;margin:12px 0';
        const dateColumn = Number(table.dataset.dateColumn);
        if (Number.isInteger(dateColumn) && dateColumn >= 0) {
            controls.innerHTML = '<label>From <input type="date" class="table-date-from"></label><label>To <input type="date" class="table-date-to"></label>';
        }
        const prev = document.createElement('button'); prev.type = 'button'; prev.className = 'btn btn-ghost btn-sm'; prev.textContent = 'Previous';
        const info = document.createElement('span'); info.className = 'users-page-info';
        const next = document.createElement('button'); next.type = 'button'; next.className = 'btn btn-ghost btn-sm'; next.textContent = 'Next';
        controls.append(prev, info, next);
        table.closest('.table-wrap').after(controls);
        let page = 1;
        const dateVal = text => {
            const s = text.trim();
            let m = s.match(/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})$/);
            if (m) return `${m[3]}-${m[2].padStart(2,'0')}-${m[1].padStart(2,'0')}`;
            m = s.match(/^(\d{1,2})\s+([A-Za-z]{3})\s+(\d{4})$/);
            if (m) { const d = new Date(`${m[2]} 1, 2000`); if (!Number.isNaN(+d)) return `${m[3]}-${String(d.getMonth()+1).padStart(2,'0')}-${m[1].padStart(2,'0')}`; }
            return /^\d{4}-\d{2}-\d{2}/.test(s) ? s.slice(0,10) : '';
        };
        const draw = () => {
            const q = table.id === 'clients-table' ? (document.getElementById('client-search')?.value || '').toLowerCase() : table.id === 'svc-table' ? (document.getElementById('svc-search')?.value || '').toLowerCase() : (document.getElementById('ord-search')?.value || '').toLowerCase();
            const from = controls.querySelector('.table-date-from')?.value || '', to = controls.querySelector('.table-date-to')?.value || '';
            const visible = rows.filter(row => {
                const date = Number.isInteger(dateColumn) ? dateVal(row.cells[dateColumn]?.textContent || '') : '';
                return row.textContent.toLowerCase().includes(q) && (!from || (date && date >= from)) && (!to || (date && date <= to));
            });
            const pages = Math.max(1, Math.ceil(visible.length / 20)); page = Math.min(page, pages);
            rows.forEach(row => row.hidden = true);
            visible.slice((page-1)*20, page*20).forEach(row => row.hidden = false);
            info.textContent = `Page ${page} of ${pages} · ${visible.length} records`;
            prev.disabled = page <= 1; next.disabled = page >= pages;
        };
        prev.addEventListener('click', () => { page--; draw(); }); next.addEventListener('click', () => { page++; draw(); });
        controls.querySelectorAll('input').forEach(input => input.addEventListener('change', () => { page = 1; draw(); }));
        const search = document.getElementById(table.id === 'clients-table' ? 'client-search' : table.id === 'svc-table' ? 'svc-search' : 'ord-search');
        search?.addEventListener('input', () => { page = 1; draw(); });
        draw();
    });
});

// ---- FORMAT NUMBER -----------------------------------------
function fmt(n) {
    return 'UGX ' + parseFloat(n || 0).toLocaleString('en-UG', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
}

// ============================================================
// ORDER FORM — DYNAMIC LINE ITEMS
// ============================================================
let itemIndex = 0;

function addItemRow(services = [], prefill = null) {
    itemIndex++;
    const i = itemIndex;
    const tbody = document.getElementById('items-body');
    if (!tbody) return;

    const tr = document.createElement('tr');
    tr.id = `item-row-${i}`;
    tr.innerHTML = `
        <td>
            <select id="svc-${i}" onchange="fillService(${i})" style="min-width:160px">
                <option value="">— Custom —</option>
                ${services.map(s => `<option value="${s.id}" data-price="${s.unit_price}" data-tax="${s.tax_rate}" data-desc="${escHtml(s.name)}">${escHtml(s.name)}</option>`).join('')}
            </select>
        </td>
        <td><input type="text" id="desc-${i}" placeholder="Description" style="min-width:180px" oninput="calcTotals()"></td>
        <td><input type="number" id="qty-${i}"   value="1"   min="0.01" step="any" style="width:70px"  oninput="calcTotals()"></td>
        <td><input type="number" id="price-${i}" value="0"   min="0"    step="any" style="width:120px" oninput="calcTotals()"></td>
        <td><input type="number" id="tax-${i}"   value="18"  min="0"    step="any" style="width:64px"  oninput="calcTotals()"></td>
        <td id="line-total-${i}" style="text-align:right;white-space:nowrap;font-size:.85rem">—</td>
        <td class="td-del"><button type="button" onclick="removeItem(${i})" title="Remove">×</button></td>
    `;
    tbody.appendChild(tr);

    if (prefill) {
        document.getElementById(`desc-${i}`).value  = prefill.description || '';
        document.getElementById(`qty-${i}`).value   = prefill.quantity    || 1;
        document.getElementById(`price-${i}`).value = prefill.unit_price  || 0;
        document.getElementById(`tax-${i}`).value   = prefill.tax_rate    || 18;
        if (prefill.service_id) {
            document.getElementById(`svc-${i}`).value = prefill.service_id;
        }
    }
    calcTotals();
}

function fillService(i) {
    const sel = document.getElementById(`svc-${i}`);
    const opt = sel.selectedOptions[0];
    if (!opt || !opt.value) return;
    document.getElementById(`price-${i}`).value = opt.dataset.price || 0;
    document.getElementById(`tax-${i}`).value   = opt.dataset.tax   || 18;
    document.getElementById(`desc-${i}`).value  = opt.dataset.desc  || '';
    calcTotals();
}

function removeItem(i) {
    const row = document.getElementById(`item-row-${i}`);
    if (row) row.remove();
    calcTotals();
}

function calcTotals() {
    let subtotal = 0, taxTotal = 0;
    document.querySelectorAll('#items-body tr').forEach(row => {
        const id     = row.id.replace('item-row-', '');
        const qty    = parseFloat(document.getElementById(`qty-${id}`)?.value   || 0);
        const price  = parseFloat(document.getElementById(`price-${id}`)?.value || 0);
        const tax    = parseFloat(document.getElementById(`tax-${id}`)?.value   || 0);
        const line   = qty * price;
        const lineTax= line * tax / 100;
        subtotal    += line;
        taxTotal    += lineTax;
        const ltd = document.getElementById(`line-total-${id}`);
        if (ltd) ltd.textContent = fmt(line + lineTax);
    });

    const discType  = document.getElementById('disc-type')?.value;
    const discVal   = parseFloat(document.getElementById('disc-value')?.value || 0);
    let   discount  = discType === 'percent' ? (subtotal * discVal / 100) : discVal;
    const total     = subtotal + taxTotal - discount;

    setText('sum-subtotal', fmt(subtotal));
    setText('sum-tax',      fmt(taxTotal));
    setText('sum-discount', fmt(discount));
    setText('sum-total',    fmt(total));
}

function setText(id, val) {
    const el = document.getElementById(id);
    if (el) el.textContent = val;
}

function escHtml(str) {
    return (str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function collectItems() {
    const items = [];
    document.querySelectorAll('#items-body tr').forEach(row => {
        const id = row.id.replace('item-row-', '');
        const desc = document.getElementById(`desc-${id}`)?.value?.trim();
        if (!desc) return;
        items.push({
            service_id:  document.getElementById(`svc-${id}`)?.value  || null,
            description: desc,
            quantity:    document.getElementById(`qty-${id}`)?.value   || 1,
            unit_price:  document.getElementById(`price-${id}`)?.value || 0,
            tax_rate:    document.getElementById(`tax-${id}`)?.value   || 0,
        });
    });
    return items;
}

// ============================================================
// CONFIRM DELETE
// ============================================================
function confirmDelete(msg, callback) {
    openModal(`
        <div class="modal-title">Confirm Delete</div>
        <p style="color:var(--text-muted);margin-bottom:24px">${msg}</p>
        <div style="display:flex;gap:10px;justify-content:flex-end">
            <button class="btn btn-ghost" onclick="closeModal()">Cancel</button>
            <button class="btn btn-danger" onclick="closeModal();${callback}">Delete</button>
        </div>
    `);
}

// ---- PRINT -------------------------------------------------
function printDoc() {
    window.print();
}
