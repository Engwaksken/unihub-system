<?php
declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/db.php';

$pdo = getDB();
if (!$pdo instanceof PDO) die('Database connection unavailable.');

if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header('Location: login.php'); exit;
}

/* -- helpers ----------------------------------------------- */
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function ensureUsersTable(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `users` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(150) NOT NULL,
        `email` VARCHAR(150) DEFAULT NULL,
        `phone` VARCHAR(30) DEFAULT NULL,
        `username` VARCHAR(100) NOT NULL,
        `password` VARCHAR(255) NOT NULL,
        `role` VARCHAR(50) NOT NULL DEFAULT 'staff',
        `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
        `last_login` DATETIME DEFAULT NULL,
        `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_users_username` (`username`),
        UNIQUE KEY `uq_users_email` (`email`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function currentUser(PDO $pdo, int $id): ?array {
    $s = $pdo->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
    $s->execute([$id]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

function roleRank(string $role): int {
    return ['viewer'=>1,'staff'=>2,'accountant'=>3,'manager'=>4,'admin'=>5][strtolower($role)] ?? 0;
}

function canManageUsers(array $u): bool { return in_array(strtolower($u['role']),['admin','manager'],true); }
function canDeleteUsers(array $u):  bool { return strtolower($u['role'])==='admin'; }

function allowedRolesFor(array $u): array {
    $r = strtolower($u['role']);
    if ($r === 'admin')   return ['admin','manager','accountant','staff','viewer'];
    if ($r === 'manager') return ['accountant','staff','viewer'];
    return [];
}

/* -- bootstrap --------------------------------------------- */
ensureUsersTable($pdo);
$authUser = currentUser($pdo, (int)$_SESSION['user_id']);
if (!$authUser || ($authUser['status'] ?? '') !== 'active') {
    $_SESSION = []; session_destroy(); header('Location: login.php'); exit;
}

$canManage    = canManageUsers($authUser);
$canDelete    = canDeleteUsers($authUser);
$allowedRoles = allowedRolesFor($authUser);

/* -- AJAX / API -------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data   = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $action = $data['action'] ?? '';

    try {
        if (!$canManage && !in_array($action,['update_user'],true))
            throw new RuntimeException('Permission denied.');

        /* ---- GET (fetch single user) ---- */
        if ($action === 'get') {
            $id = (int)($data['id'] ?? 0);
            $s  = $pdo->prepare("SELECT id,name,email,phone,username,role,status FROM users WHERE id=? LIMIT 1");
            $s->execute([$id]);
            jsonResponse($s->fetch(PDO::FETCH_ASSOC) ?: []);
        }

        /* ---- CREATE ---- */
        if ($action === 'create') {
            $name     = trim($data['name']     ?? '');
            $email    = trim($data['email']    ?? '');
            $phone    = trim($data['phone']    ?? '');
            $username = trim($data['username'] ?? '');
            $password = $data['password'] ?? '';
            $role     = strtolower(trim($data['role']   ?? 'staff'));
            $status   = strtolower(trim($data['status'] ?? 'active'));

            if (!$name)     throw new RuntimeException('Full name is required.');
            if (!$username) throw new RuntimeException('Username is required.');
            if (!$password) throw new RuntimeException('Password is required.');
            if (!in_array($role, $allowedRoles, true)) throw new RuntimeException('Cannot assign that role.');
            if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Invalid email.');

            $c = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username=?"); $c->execute([$username]);
            if ($c->fetchColumn()) throw new RuntimeException('Username already exists.');
            if ($email) {
                $c2 = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email=?"); $c2->execute([$email]);
                if ($c2->fetchColumn()) throw new RuntimeException('Email already exists.');
            }

            $s = $pdo->prepare("INSERT INTO users (name,email,phone,username,password,role,status) VALUES (?,?,?,?,?,?,?)");
            $s->execute([$name, $email?:null, $phone?:null, $username, password_hash($password,PASSWORD_DEFAULT), $role, $status]);
            jsonResponse(['success'=>true,'message'=>'User created','id'=>$pdo->lastInsertId()]);
        }

        /* ---- UPDATE ---- */
        if ($action === 'update') {
            $id       = (int)($data['id'] ?? 0);
            $name     = trim($data['name']     ?? '');
            $email    = trim($data['email']    ?? '');
            $phone    = trim($data['phone']    ?? '');
            $username = trim($data['username'] ?? '');
            $password = $data['password'] ?? '';
            $role     = strtolower(trim($data['role']   ?? 'staff'));
            $status   = strtolower(trim($data['status'] ?? 'active'));

            if ($id <= 0)  throw new RuntimeException('Invalid user.');
            if (!$name)    throw new RuntimeException('Full name is required.');
            if (!$username)throw new RuntimeException('Username is required.');
            if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Invalid email.');

            $existing = currentUser($pdo, $id);
            if (!$existing) throw new RuntimeException('User not found.');

            $isSelf = ($id === (int)$authUser['id']);

            if (!$isSelf && !in_array($role, $allowedRoles, true)) throw new RuntimeException('Cannot assign that role.');
            if (!$isSelf && roleRank($existing['role']) >= roleRank($authUser['role']) && strtolower($authUser['role']) !== 'admin')
                throw new RuntimeException('Cannot edit a user with equal or higher permissions.');
            if ($isSelf && $status !== 'active') throw new RuntimeException('Cannot deactivate your own account.');
            if ($isSelf && strtolower($authUser['role']) !== 'admin' && $role !== strtolower($authUser['role']))
                throw new RuntimeException('Cannot change your own role.');

            $c = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username=? AND id<>?"); $c->execute([$username,$id]);
            if ($c->fetchColumn()) throw new RuntimeException('Username already exists.');
            if ($email) {
                $c2 = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email=? AND id<>?"); $c2->execute([$email,$id]);
                if ($c2->fetchColumn()) throw new RuntimeException('Email already exists.');
            }

            if ($password) {
                $s = $pdo->prepare("UPDATE users SET name=?,email=?,phone=?,username=?,password=?,role=?,status=? WHERE id=? LIMIT 1");
                $s->execute([$name,$email?:null,$phone?:null,$username,password_hash($password,PASSWORD_DEFAULT),$role,$status,$id]);
            } else {
                $s = $pdo->prepare("UPDATE users SET name=?,email=?,phone=?,username=?,role=?,status=? WHERE id=? LIMIT 1");
                $s->execute([$name,$email?:null,$phone?:null,$username,$role,$status,$id]);
            }

            if ($isSelf) {
                $_SESSION['user_name']  = $name;
                $_SESSION['user_email'] = $email;
                $_SESSION['user_role']  = $role;
                $_SESSION['username']   = $username;
            }
            jsonResponse(['success'=>true,'message'=>'User updated']);
        }

        /* ---- TOGGLE STATUS ---- */
        if ($action === 'toggle_status') {
            $id = (int)($data['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Invalid user.');
            if ($id === (int)$authUser['id']) throw new RuntimeException('Cannot deactivate your own account.');
            $existing = currentUser($pdo, $id);
            if (!$existing) throw new RuntimeException('User not found.');
            if (roleRank($existing['role']) >= roleRank($authUser['role']) && strtolower($authUser['role']) !== 'admin')
                throw new RuntimeException('Cannot change that user.');
            $new = ($existing['status'] === 'active') ? 'inactive' : 'active';
            $pdo->prepare("UPDATE users SET status=? WHERE id=? LIMIT 1")->execute([$new,$id]);
            jsonResponse(['success'=>true,'message'=>'Status updated','status'=>$new]);
        }

        /* ---- DELETE ---- */
        if ($action === 'delete') {
            if (!$canDelete) throw new RuntimeException('Only admin can delete users.');
            $id = (int)($data['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Invalid user.');
            if ($id === (int)$authUser['id']) throw new RuntimeException('Cannot delete your own account.');
            $existing = currentUser($pdo, $id);
            if (!$existing) throw new RuntimeException('User not found.');
            $pdo->prepare("DELETE FROM users WHERE id=? LIMIT 1")->execute([$id]);
            jsonResponse(['success'=>true]);
        }

    } catch (Throwable $e) {
        jsonResponse(['success'=>false,'error'=>$e->getMessage()], 400);
    }
}

/* -- GET single user for edit form ------------------------ */
if (isset($_GET['get']) && is_numeric($_GET['get'])) {
    $s = $pdo->prepare("SELECT id,name,email,phone,username,role,status FROM users WHERE id=? LIMIT 1");
    $s->execute([$_GET['get']]);
    jsonResponse($s->fetch(PDO::FETCH_ASSOC) ?: []);
}

/* -- List query -------------------------------------------- */
$search  = trim($_GET['q']      ?? '');
$role    = trim($_GET['role']   ?? '');
$status  = trim($_GET['status'] ?? '');
$page    = max(1,(int)($_GET['page'] ?? 1));
$perPage = 15;
$offset  = ($page-1)*$perPage;

$where = []; $params = [];
if ($search !== '') {
    $where[] = "(name LIKE :s OR username LIKE :s OR email LIKE :s OR phone LIKE :s)";
    $params[':s'] = '%'.$search.'%';
}
if ($role   !== '') { $where[] = "role=:role";     $params[':role']   = $role; }
if ($status !== '') { $where[] = "status=:status"; $params[':status'] = $status; }
$wsql = $where ? 'WHERE '.implode(' AND ',$where) : '';

$cnt = $pdo->prepare("SELECT COUNT(*) FROM users $wsql");
foreach ($params as $k=>$v) $cnt->bindValue($k,$v);
$cnt->execute();
$totalPages = max(1,(int)ceil($cnt->fetchColumn()/$perPage));

$lst = $pdo->prepare("SELECT id,name,email,phone,username,role,status,last_login,created_at FROM users $wsql ORDER BY id DESC LIMIT :lim OFFSET :off");
foreach ($params as $k=>$v) $lst->bindValue($k,$v);
$lst->bindValue(':lim',$perPage,PDO::PARAM_INT);
$lst->bindValue(':off',$offset, PDO::PARAM_INT);
$lst->execute();
$users = $lst->fetchAll(PDO::FETCH_ASSOC);

$stats = $pdo->query("SELECT COUNT(*) AS total,
    SUM(status='active') AS active, SUM(status='inactive') AS inactive,
    SUM(role='admin') AS admins FROM users")->fetch(PDO::FETCH_ASSOC) ?: [];

$pageTitle = 'Users';
include __DIR__ . '/includes/header.php';
?>

<div class="section-header">
    <span class="section-title">Users</span>
    <div class="users-toolbar">
        <a href="email_logs.php"     class="btn btn-ghost btn-sm">Email Logs</a>
        <a href="email_settings.php" class="btn btn-ghost btn-sm">SMTP Settings</a>
        <?php if ($canManage): ?>
            <button class="btn btn-primary" onclick="openUserForm()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                New User
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- Stats -->
<div class="stats-grid users-stats-grid">
    <div class="stat-card"><div class="stat-label">Total Users</div><div class="stat-value"><?= (int)($stats['total']??0) ?></div></div>
    <div class="stat-card"><div class="stat-label">Active</div>    <div class="stat-value"><?= (int)($stats['active']??0) ?></div></div>
    <div class="stat-card"><div class="stat-label">Inactive</div>  <div class="stat-value"><?= (int)($stats['inactive']??0) ?></div></div>
    <div class="stat-card"><div class="stat-label">Admins</div>    <div class="stat-value"><?= (int)($stats['admins']??0) ?></div></div>
</div>

<!-- Filter bar -->
<div class="card">
    <form class="filter-bar users-filter-bar" method="get">
        <input class="search-input" type="text" name="q"
               value="<?= h($search) ?>" placeholder="Search name, username, email, phone...">
        <select class="search-input users-filter-select" name="role">
            <option value="">All Roles</option>
            <?php foreach (['admin','manager','accountant','staff','viewer'] as $r): ?>
                <option value="<?= $r ?>" <?= $role===$r?'selected':'' ?>><?= ucfirst($r) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="search-input users-filter-select" name="status">
            <option value="">All Statuses</option>
            <option value="active"   <?= $status==='active'  ?'selected':'' ?>>Active</option>
            <option value="inactive" <?= $status==='inactive'?'selected':'' ?>>Inactive</option>
        </select>
        <button class="btn btn-primary btn-sm" type="submit">Filter</button>
        <a href="users.php" class="btn btn-ghost btn-sm">Reset</a>
    </form>
</div>

<!-- Table -->
<div class="card">
    <div class="table-wrap">
        <table id="users-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Name</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Last Login</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $i => $u):
                $editable = $canManage && (
                    strtolower($authUser['role'])==='admin'
                    || roleRank($u['role']) < roleRank($authUser['role'])
                    || (int)$u['id'] === (int)$authUser['id']
                );
            ?>
            <tr>
                <td class="users-row-num"><?= $offset+$i+1 ?></td>
                <td><strong><?= h($u['name']) ?></strong></td>
                <td><?= h($u['username']) ?></td>
                <td><?= h($u['email'] ?: '�') ?></td>
                <td><?= h($u['phone'] ?: '�') ?></td>
                <td><span class="badge badge-invoiced"><?= h($u['role']) ?></span></td>
                <td><span class="badge <?= $u['status']==='active'?'badge-paid':'badge-overdue' ?>">
                    <?= h($u['status']) ?></span></td>
                <td class="users-last-login">
                    <?= $u['last_login'] ? h($u['last_login']) : '<span class="users-never">Never</span>' ?>
                </td>
                <td>
                    <div class="td-actions">
                        <?php if ($editable): ?>
                            <button class="btn btn-ghost btn-sm"
                                    onclick="openUserForm(<?= $u['id'] ?>)">Edit</button>
                            <?php if ((int)$u['id'] !== (int)$authUser['id']): ?>
                                <button class="btn btn-ghost btn-sm"
                                        onclick="toggleUserStatus(<?= $u['id'] ?>, this)">
                                    <?= $u['status']==='active'?'Deactivate':'Activate' ?>
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($canDelete && (int)$u['id'] !== (int)$authUser['id']): ?>
                            <button class="btn btn-danger btn-sm"
                                    onclick="deleteUser(<?= $u['id'] ?>, '<?= h(addslashes($u['name'])) ?>')">Del</button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($users)): ?>
            <tr><td colspan="9">
                <div class="empty-state">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                    </svg>
                    <p>No users found.</p>
                </div>
            </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="users-pagination">
        <?php
        $qs = http_build_query(array_filter(['q'=>$search,'role'=>$role,'status'=>$status]));
        if ($page > 1): ?>
            <a class="btn btn-ghost btn-sm"
               href="users.php?<?= $qs ?>&page=<?= $page-1 ?>">Previous</a>
        <?php endif; ?>
        <span class="users-page-info">Page <?= $page ?> of <?= $totalPages ?></span>
        <?php if ($page < $totalPages): ?>
            <a class="btn btn-ghost btn-sm"
               href="users.php?<?= $qs ?>&page=<?= $page+1 ?>">Next</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script>
const ALLOWED_ROLES = <?= json_encode($allowedRoles) ?>;
const AUTH_ROLE     = <?= json_encode(strtolower($authUser['role'])) ?>;
const CAN_MANAGE    = <?= $canManage ? 'true' : 'false' ?>;

/* -- open form modal (create or edit) ---------------------- */
async function openUserForm(id = null) {
    let d = { name:'', email:'', phone:'', username:'', role:'staff', status:'active' };
    if (id) {
        const res = await fetch(`users.php?get=${id}`);
        d = await res.json();
    }

    /* build role options */
    const roles  = id
        ? [...new Set([...ALLOWED_ROLES, AUTH_ROLE])]
        : ALLOWED_ROLES;
    const roleOpts = roles.map(r =>
        `<option value="${r}" ${r===d.role?'selected':''}>${r.charAt(0).toUpperCase()+r.slice(1)}</option>`
    ).join('');

    openModal(`
        <div class="modal-title">${id ? 'Edit' : 'New'} User</div>
        <input type="hidden" id="u-id" value="${id||''}">
        <div class="form-grid users-form-grid">
            <div class="form-group users-full-col">
                <label>Full Name *</label>
                <input id="u-name" type="text" value="${escHtml(d.name)}" placeholder="Jane Namukasa">
            </div>
            <div class="form-group">
                <label>Username *</label>
                <input id="u-username" type="text" value="${escHtml(d.username)}" placeholder="jnamukasa">
            </div>
            <div class="form-group">
                <label>${id ? 'New ' : ''}Password${id ? ' (blank = keep)' : ' *'}</label>
                <input id="u-password" type="password" placeholder="${id ? 'Leave blank to keep' : 'Enter password'}">
            </div>
            <div class="form-group">
                <label>Email</label>
                <input id="u-email" type="email" value="${escHtml(d.email||'')}" placeholder="jane@example.com">
            </div>
            <div class="form-group">
                <label>Phone</label>
                <input id="u-phone" type="text" value="${escHtml(d.phone||'')}" placeholder="+256 700 000 000">
            </div>
            <div class="form-group">
                <label>Role</label>
                <select id="u-role">${roleOpts}</select>
            </div>
            <div class="form-group">
                <label>Status</label>
                <select id="u-status">
                    <option value="active"   ${d.status==='active'  ?'selected':''}>Active</option>
                    <option value="inactive" ${d.status==='inactive'?'selected':''}>Inactive</option>
                </select>
            </div>
        </div>
        <div class="users-modal-actions">
            <button class="btn btn-ghost" onclick="closeModal()">Cancel</button>
            <button class="btn btn-primary" onclick="saveUser()">Save User</button>
        </div>
    `);
}

/* -- save (create or update) ------------------------------- */
async function saveUser() {
    const id  = document.getElementById('u-id').value;
    const pwd = document.getElementById('u-password').value;

    const payload = {
        action:   id ? 'update' : 'create',
        id,
        name:     document.getElementById('u-name').value.trim(),
        username: document.getElementById('u-username').value.trim(),
        password: pwd,
        email:    document.getElementById('u-email').value.trim(),
        phone:    document.getElementById('u-phone').value.trim(),
        role:     document.getElementById('u-role').value,
        status:   document.getElementById('u-status').value,
    };

    if (!payload.name)     { showToast('Full name is required', 'error'); return; }
    if (!payload.username) { showToast('Username is required', 'error'); return; }
    if (!id && !pwd)       { showToast('Password is required', 'error'); return; }

    const res = await api('users.php', { method:'POST', body: JSON.stringify(payload) });
    if (res.success) {
        showToast(res.message);
        closeModal();
        setTimeout(() => location.reload(), 600);
    } else {
        showToast(res.error || 'Error saving user', 'error');
    }
}

/* -- toggle status ----------------------------------------- */
async function toggleUserStatus(id, btn) {
    const res = await api('users.php', {
        method: 'POST',
        body: JSON.stringify({ action:'toggle_status', id })
    });
    if (res.success) {
        showToast(res.message);
        setTimeout(() => location.reload(), 600);
    } else {
        showToast(res.error || 'Failed to update status', 'error');
    }
}

/* -- delete ------------------------------------------------ */
async function deleteUser(id, name) {
    if (!confirm(`Delete user "${name}"? This cannot be undone.`)) return;
    const res = await api('users.php', {
        method: 'POST',
        body: JSON.stringify({ action:'delete', id })
    });
    if (res.success) {
        showToast('User deleted');
        setTimeout(() => location.reload(), 600);
    } else {
        showToast(res.error || 'Delete failed', 'error');
    }
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
