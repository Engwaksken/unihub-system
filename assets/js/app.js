
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
function openModal(html) {
    document.getElementById('modal-body').innerHTML = html;
    document.getElementById('modal').classList.add('active');
    document.getElementById('modal-overlay').classList.add('active');
}
function closeModal() {
    document.getElementById('modal').classList.remove('active');
    document.getElementById('modal-overlay').classList.remove('active');
    document.getElementById('modal-body').innerHTML = '';
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
